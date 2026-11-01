<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Esim extends Model
{
    public const SIM_TYPE_ESIM = 'esim';

    public const SIM_TYPE_PHYSICAL = 'physical';

    public const PROVIDER_STATUS_PENDING = 'pending';

    public const PROVIDER_STATUS_ACTIVE = 'active';

    public const PROVIDER_STATUS_SUSPENDED = 'suspended';

    public const SALE_STATUS_AVAILABLE = 'available';

    public const SALE_STATUS_SOLD = 'sold';

    public const SALE_STATUS_USED = 'used';

    protected $fillable = [
        'sim_id',
        'msisdn',
        'import_batch_id',
        'network_id',
        'iccid',
        'imsi',
        'description',
        'status',
        'sale_status',
        'sim_type',
        'provider_status',
        'qr_code_path',
        'qr_code_data',
        'balances',
        'balance_fetched_at',
    ];

    protected $casts = [
        'network_id' => 'integer',
        'import_batch_id' => 'integer',
        'balances' => 'array',
        'balance_fetched_at' => 'datetime',
    ];

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(EsimImportBatch::class, 'import_batch_id');
    }

    public function importItems(): HasMany
    {
        return $this->hasMany(EsimImportItem::class, 'esim_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toImportApiArray(): array
    {
        return [
            'id' => $this->id,
            'phone_number' => $this->msisdn,
            'iccid' => $this->iccid,
            'sim_type' => $this->sim_type,
            'status' => $this->sale_status ?? self::SALE_STATUS_AVAILABLE,
            'import_batch_id' => $this->import_batch_id,
            'has_qr_code' => (bool) $this->qr_code_path,
        ];
    }

    /**
     * Complete eSIM payload for an authenticated owner's assignment responses.
     * Activation uses `qr_code_data` (imported QR/LPA payload) — not `lpa_string`.
     *
     * @return array<string, mixed>
     */
    public function toUserAssignmentApiArray(): array
    {
        $qrCodeData = trim((string) ($this->qr_code_data ?? ''));

        return [
            'id' => $this->id,
            'msisdn' => $this->msisdn,
            'phone_number' => $this->msisdn,
            'iccid' => $this->iccid,
            'imsi' => $this->imsi,
            'description' => $this->description,
            'status' => $this->status,
            'sale_status' => $this->sale_status,
            'sim_type' => $this->sim_type,
            'provider_status' => $this->provider_status,
            'network_id' => $this->network_id,
            'qr_code_data' => $qrCodeData !== '' ? $qrCodeData : null,
            'has_activation_data' => $qrCodeData !== '',
            'balances' => is_array($this->balances) ? $this->balances : null,
            'balance_fetched_at' => $this->balance_fetched_at?->toIso8601String(),
        ];
    }

    public static function normalizeMsisdn(string $msisdn): string
    {
        return ltrim(preg_replace('/\s+/', '', trim($msisdn)), '+');
    }

    public static function toVodacomMsisdn(string $msisdn): string
    {
        $normalized = self::normalizeMsisdn($msisdn);

        return str_starts_with($normalized, '+') ? $normalized : '+'.$normalized;
    }

    public static function defaultNetworkId(): int
    {
        return (int) config('services.vodacom_sim.default_network_id', 1);
    }

    public static function findByMsisdn(string $msisdn): ?self
    {
        $normalized = self::normalizeMsisdn($msisdn);

        return static::query()
            ->whereIn('msisdn', [$normalized, '+'.$normalized])
            ->first();
    }

    /**
     * Inventory rows eligible for agent assignment (not linked to any user).
     */
    public function scopeAvailableForAssignment(Builder $query): Builder
    {
        return $query
            ->where('status', 'AVAILABLE')
            ->whereNull('partner_bulk_order_id')
            ->where(function (Builder $q) {
                $q->whereNull('sale_status')
                    ->orWhere('sale_status', self::SALE_STATUS_AVAILABLE);
            })
            ->whereNotIn('id', UserEsim::query()->select('esim_id'));
    }

    public function isAssigned(): bool
    {
        return UserEsim::query()->where('esim_id', $this->id)->exists();
    }
}

