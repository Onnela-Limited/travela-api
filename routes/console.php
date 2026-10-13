<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('user:make-admin {email}', function () {
    $email = (string) $this->argument('email');

    $user = \App\Models\User::where('email', $email)->first();

    if (! $user) {
        $this->error("User not found: {$email}");

        return 1;
    }

    $user->role = 'admin';
    $user->save();

    $this->info("User promoted to admin: {$email}");

    return 0;
})->purpose('Promote a user to admin role');

Artisan::command('resend:check', function () {
    $key = (string) config('services.resend.key');
    if ($key === '') {
        $this->error('RESEND_KEY is not set in .env');

        return 1;
    }

    /** @var \App\Services\Email\ResendMailConfigurator $configurator */
    $configurator = app(\App\Services\Email\ResendMailConfigurator::class);
    $verified = $configurator->verifiedDomainNames();
    $resolvedFrom = $configurator->resolvedFromAddress();

    $this->info('Mailer: '.config('mail.default'));
    $this->line('Configured from: '.config('mail.from.address'));
    $this->line('Resolved from: '.$resolvedFrom);

    if ($verified === []) {
        $this->warn('No verified Resend domains found.');
        $this->line('Add and verify a domain at https://resend.com/domains');

        return 1;
    }

    $this->info('Verified domains:');
    foreach ($verified as $domain) {
        $this->line(" - {$domain}");
    }

    $configuredDomain = substr(strrchr((string) config('mail.from.address'), '@'), 1) ?: '';
    if ($configuredDomain !== '' && ! in_array($configuredDomain, $verified, true)) {
        $this->warn("Configured MAIL_FROM domain ({$configuredDomain}) is not verified.");
        $this->line("Sending will use {$resolvedFrom} instead.");
    }

    return 0;
})->purpose('Inspect Resend domain verification and sender address');

Artisan::command('mail:test {email}', function () {
    $email = (string) $this->argument('email');

    try {
        \Illuminate\Support\Facades\Mail::to($email)->send(
            new \App\Mail\EmailVerificationCodeMail('123456')
        );
    } catch (\Throwable $e) {
        $this->error('Mail failed: '.$e->getMessage());

        return 1;
    }

    $this->info("Test verification email sent to {$email}");

    return 0;
})->purpose('Send a test verification email through the configured mailer');

Artisan::command('esim:activate-due', function () {
    $activation = app(\App\Services\Esim\ScheduledEsimActivationService::class);
    $ready = 0;
    \App\Models\Order::query()
        ->where(function ($q) {
            $q->where('payment_status', 'paid')->orWhere('status', 'paid');
        })
        ->whereHas('trip', function ($q) {
            $q->whereDate('arrival_date', '<=', \App\Support\ActivationSchedule::today());
        })
        ->where(function ($q) {
            $q->whereNull('recharge_status')->orWhere('recharge_status', '!=', 'success')
                ->orWhereHas('userEsims', fn ($assignment) => $assignment->whereNull('activation_email_sent_at'));
        })
        ->with('trip')
        ->chunkById(100, function ($orders) use ($activation, &$ready) {
            foreach ($orders as $order) {
                try {
                    $result = $activation->activateOrder($order);
                    if ($result['status'] === 'ready') $ready++;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Scheduled eSIM activation failed', [
                        'order_id' => $order->id, 'error' => $e->getMessage(),
                    ]);
                    $this->error("Order {$order->id}: activation failed; will retry.");
                }
            }
        });
    $this->info("eSIM orders ready: {$ready}");
    return 0;
})->purpose('Assign and recharge due eSIM orders, then send installation QR emails');

Artisan::command('esim:notify-assignment-due', function () {
    $assignment = app(\App\Services\Esim\SimAssignmentService::class);
    $sent = 0;

    $orders = \App\Models\Order::query()
        ->with('trip')
        ->where(function ($q) {
            $q->where('payment_status', 'paid')->orWhere('status', 'paid');
        })
        ->whereHas('trip', function ($q) {
            $q->whereDate('arrival_date', '<=', \App\Support\ActivationSchedule::today());
        })
        ->orderBy('id')
        ->get();

    foreach ($orders as $order) {
        if (\App\Support\OrderCheckout::isTopUpOrder($order)) {
            continue;
        }
        if ($assignment->orderSimType($order) !== \App\Models\Esim::SIM_TYPE_ESIM) {
            continue;
        }
        if ($assignment->findAssignmentForOrder($order)) {
            continue;
        }

        $before = $order->metadata['assignment_due_email_for'] ?? null;
        $assignment->notifyAssignmentDue($order);
        $order->refresh();
        if (($order->metadata['assignment_due_email_for'] ?? null) !== $before) {
            $sent++;
        }
    }

    $this->info("Assignment-due emails sent: {$sent}");

    return 0;
})->purpose('Email customers when their eSIM activation date is due and no number is assigned');
