<?php

namespace Tests\Unit;

use App\Services\Email\ResendMailConfigurator;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class ResendMailConfiguratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'resend',
            'mail.from.address' => 'noreply@mail.thetravela.com',
            'mail.from.name' => 'Travela',
            'services.resend.key' => 're_test_sending_only',
            'services.resend.domain' => 'thetravela.com',
        ]);
    }

    public function test_keeps_explicit_sender_subdomain_without_domain_cache_lookup(): void
    {
        Cache::shouldReceive('remember')->never();

        $this->assertSame('noreply@mail.thetravela.com', app(ResendMailConfigurator::class)->resolvedFromAddress());
    }

    public function test_falls_back_to_configured_domain_and_local_part_only_when_sender_is_empty(): void
    {
        config(['mail.from.address' => ' ', 'services.resend.from_local' => 'support']);

        $this->assertSame('support@thetravela.com', app(ResendMailConfigurator::class)->resolvedFromAddress());
    }

    public function test_resend_message_uses_configured_sender_and_name(): void
    {
        Cache::shouldReceive('remember')->never();
        $message = (new Email)->from('old@example.com');

        Event::dispatch(new MessageSending($message, []));

        $this->assertSame('noreply@mail.thetravela.com', $message->getFrom()[0]->getAddress());
        $this->assertSame('Travela', $message->getFrom()[0]->getName());
    }

    public function test_check_reports_local_config_without_claiming_domain_verification(): void
    {
        Cache::shouldReceive('remember')->never();

        $this->artisan('resend:check')
            ->expectsOutput('Resolved from: noreply@mail.thetravela.com')
            ->expectsOutput('Resend key: configured')
            ->expectsOutput('Local mail configuration checked; no Resend API calls made.')
            ->expectsOutput('Domain verification and email delivery have not been checked.')
            ->assertExitCode(0);
    }

    public function test_check_fails_for_missing_key(): void
    {
        config(['services.resend.key' => '']);

        $this->artisan('resend:check')->expectsOutput('RESEND_KEY is not set in .env')->assertExitCode(1);
    }

    public function test_check_fails_for_invalid_sender(): void
    {
        config(['mail.from.address' => 'invalid']);

        $this->artisan('resend:check')
            ->expectsOutput('The configured sender is not a valid email address.')
            ->assertExitCode(1);
    }
}
