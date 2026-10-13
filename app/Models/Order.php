<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'draft_id',
        'user_id',
        'status',
        'subtotal',
        'discount_amount',
        'discount_code',
        'total_amount',
        'currency',
        'source',
        'platform',
        'metadata',
        'payment_reference',
        'payment_gateway',
        'gateway_payment_id',
        'payment_status',
        'payment_payload',
        'payment_callback',
        'paid_at',
        'recharge_status',
        'recharge_reference',
        'recharge_transaction_id',
        'recharge_response',
        'recharge_completed_at',
        'recharge_http_status',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'metadata' => 'array',
        'payment_payload' => 'array',
        'payment_callback' => 'array',
        'recharge_response' => 'array',
        'paid_at' => 'datetime',
        'recharge_completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that owns the order.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function userEsims(): HasMany
    {
        return $this->hasMany(UserEsim::class);
    }

    /**
     * Get the trip associated with the order.
     */
    public function trip(): HasOne
    {
        return $this->hasOne(Trip::class);
    }

    /**
     * Get the order items for the order.
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the KYC record for the user.
     */
    public function kyc(): BelongsTo
    {
        return $this->belongsTo(Kyc::class, 'user_id', 'user_id');
    }
}
