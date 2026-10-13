<?php

namespace App\Models;

use App\Services\Esim\PhysicalSimIssuanceService;
use App\Services\Esim\UserEsimOrderLinkService;
use Illuminate\Database\Eloquent\Model;

class UserEsim extends Model
{
    protected $fillable = [
        'user_id',
        'esim_id',
        'bundle_id',
        'order_id',
        'order_item_id',
        'balance',
        'balance_currency',
        'balance_fetched_at',
        'balances',
        'last_recharge_amount',
        'last_recharge_reference',
        'last_recharge_status',
        'last_recharged_at',
        'physical_issued_at',
        'physical_issued_by',
        'physical_issued_location',
        'device_activated_at',
        'activation_email_sent_at',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'balance_fetched_at' => 'datetime',
        'balances' => 'array',
        'last_recharge_amount' => 'decimal:2',
        'last_recharged_at' => 'datetime',
        'physical_issued_at' => 'datetime',
        'device_activated_at' => 'datetime',
        'activation_email_sent_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function esim()
    {
        return $this->belongsTo(Esim::class);
    }

    public function bundle()
    {
        return $this->belongsTo(Bundle::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function physicalIssuedBy()
    {
        return $this->belongsTo(User::class, 'physical_issued_by');
    }

    /**
     * Bundle purchased with this assignment, including duration from order item or catalog.
     *
     * @return array<string, mixed>|null
     */
    public function bundleWithDuration(): ?array
    {
        return app(UserEsimOrderLinkService::class)->bundleForAssignment($this);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAssignmentArray(): array
    {
        $service = app(UserEsimOrderLinkService::class);
        $this->loadMissing('esim');

        $data = $this->toArray();
        $data['bundle'] = $service->bundleForAssignment($this);
        $data['esim'] = $this->esim
            ? $this->esim->toUserAssignmentApiArray()
            : null;

        if (! $this->order_id) {
            $data['latest_order'] = $service->latestOrderForUser((int) $this->user_id);
        }

        return array_merge(
            $data,
            app(PhysicalSimIssuanceService::class)->issuancePayload($this),
            $service->planWindowForAssignment($this),
        );
    }
}
