<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EsimActivateRequest;
use App\Http\Requests\EsimCreateRequest;
use App\Http\Requests\EsimRechargeRequest;
use App\Http\Requests\EsimSuspendRequest;
use App\Models\Esim;
use App\Models\UserEsim;
use App\Services\Esim\OrderRechargeService;
use App\Services\Esim\VodacomBalanceService;
use App\Services\Esim\VodacomRechargePayload;
use App\Services\Esim\VodacomSimManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EsimController extends Controller
{
    public function __construct(
        private readonly VodacomSimManagerService $vodacom,
        private readonly VodacomBalanceService $balances,
        private readonly OrderRechargeService $orderRecharge,
    ) {}

    public function organisationBalance()
    {
        return $this->proxy($this->vodacom->get('/api/organisations/balance'));
    }

    public function networks()
    {
        return $this->proxy($this->vodacom->get('/api/networks'));
    }

    public function products(Request $request)
    {
        $query = $request->only(['network_id', 'product_type', 'page', 'page_size']);

        return $this->proxy($this->vodacom->get('/api/products', $query));
    }

    public function sims(Request $request)
    {
        $query = $request->only(['iccid', 'imsi', 'msisdn', 'network_id', 'status', 'page', 'page_size']);

        return $this->proxy($this->vodacom->get('/api/sims', $query));
    }

    public function storeSim(EsimCreateRequest $request)
    {
        $payload = array_filter(
            $request->only(['msisdn', 'iccid', 'imsi', 'network_id', 'description']),
            fn ($value) => ! is_null($value) && $value !== '',
        );

        if (isset($payload['msisdn'])) {
            $payload['msisdn'] = Esim::toVodacomMsisdn((string) $payload['msisdn']);
        }

        if (isset($payload['iccid'])) {
            $payload['iccid'] = strtoupper((string) $payload['iccid']);
        }

        return $this->proxy($this->vodacom->post('/api/sims', [], $payload));
    }

    public function activate(EsimActivateRequest $request)
    {
        $query = array_filter($request->only(['msisdn', 'iccid', 'imsi']), fn ($v) => ! is_null($v) && $v !== '');

        if (isset($query['msisdn'])) {
            $query['msisdn'] = Esim::toVodacomMsisdn((string) $query['msisdn']);
        }

        if (isset($query['iccid'])) {
            $query['iccid'] = strtoupper((string) $query['iccid']);
        }

        return $this->proxy($this->vodacom->post('/api/sims-activate', $query));
    }

    public function suspend(EsimSuspendRequest $request)
    {
        $query = array_filter($request->only(['msisdn', 'iccid', 'imsi']), fn ($v) => ! is_null($v) && $v !== '');

        return $this->proxy($this->vodacom->post('/api/sims-suspend', $query));
    }

    public function usage(Request $request)
    {
        $query = $request->only([
            'iccid',
            'imsi',
            'msisdn',
            'status',
            'rule_id',
            'start_date',
            'end_date',
            'page',
            'page_size',
        ]);

        return $this->proxy($this->vodacom->get('/api/usage', $query));
    }

    public function usageDetails(Request $request)
    {
        $query = $request->only([
            'msisdn',
            'iccid',
            'imsi',
            'start_date',
            'end_date',
            'page',
            'page_size',
        ]);

        return $this->proxy($this->vodacom->get('/api/usage-details', $query));
    }

    public function recharges(Request $request)
    {
        $query = $request->only([
            'transaction_id',
            'rule_id',
            'rule_type',
            'sim_id',
            'msisdn',
            'start_date',
            'end_date',
            'start_datetime',
            'end_datetime',
            'page',
            'page_size',
        ]);

        return $this->proxy($this->vodacom->get('/api/recharge', $query));
    }

    public function recharge(EsimRechargeRequest $request)
    {
        $payload = VodacomRechargePayload::normalize(
            $request->only(['airtime_amount', 'msisdn', 'network_id', 'reference', 'product_id'])
        );

        $response = $this->vodacom->post('/api/recharge', [], $payload);

        if ($response->successful() && ! empty($payload['msisdn'])) {
            $this->requestBalancesAfterRecharge((string) $payload['msisdn']);
        }

        return $this->proxy($response);
    }

    public function simsBalances(Request $request)
    {
        $query = array_filter($request->only(['msisdn', 'iccid', 'imsi']), fn ($v) => ! is_null($v) && $v !== '');

        if (isset($query['msisdn'])) {
            $query['msisdn'] = Esim::toVodacomMsisdn((string) $query['msisdn']);
        }

        Log::info('Vodacom sims-balances request', [
            'query' => $query,
            'ip' => $request->ip(),
        ]);

        $response = $this->vodacom->get('/api/sims-balances', $query);

        Log::info('Vodacom sims-balances response', [
            'query' => $query,
            'status' => $response->status(),
            'body' => mb_substr((string) $response->body(), 0, 8000),
        ]);

        if ($response->status() === 202) {
            Log::info('Vodacom sims-balances queued for callback', [
                'query' => $query,
                'body' => mb_substr((string) $response->body(), 0, 2000),
            ]);
        } elseif ($response->successful()) {
            $synced = $this->balances->syncFromVodacomPayload($response->json());
            Log::info('Vodacom sims-balances synced to database', ['synced' => $synced]);
        } else {
            Log::warning('Vodacom sims-balances failed', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 2000),
            ]);
        }

        return $this->proxy($response);
    }

    public function rechargeCallback(Request $request): JsonResponse
    {
        Log::info('Vodacom recharge callback received', $this->callbackLogContext($request));

        $raw = $request->all();
        $payload = VodacomRechargePayload::unwrapResponse(is_array($raw) ? $raw : []);

        $msisdn = isset($payload['msisdn']) ? trim((string) $payload['msisdn']) : '';
        if ($msisdn === '') {
            Log::warning('Vodacom recharge callback validation failed', [
                'errors' => ['msisdn' => ['The msisdn field is required.']],
                'body' => $raw,
            ]);

            throw ValidationException::withMessages([
                'msisdn' => 'The msisdn field is required.',
            ]);
        }

        $amount = VodacomRechargePayload::amountFrom($payload);
        $status = $payload['status'] ?? $payload['Status'] ?? 'SUCCESS';
        $reference = isset($payload['reference']) && is_string($payload['reference']) && $payload['reference'] !== ''
            ? VodacomRechargePayload::formatReference($payload['reference'])
            : null;
        $transactionId = VodacomRechargePayload::transactionIdFrom($payload, false);

        $esim = Esim::findByMsisdn($msisdn);

        if (! $esim) {
            Log::warning('Vodacom recharge callback: SIM not found', ['msisdn' => $msisdn]);

            return response()->json(['success' => false, 'message' => 'SIM not found'], 404);
        }

        $assignment = UserEsim::where('esim_id', $esim->id)->first();
        $assignmentUpdated = false;

        if ($assignment) {
            $update = [
                'last_recharge_status' => (string) $status,
                'last_recharged_at' => now(),
            ];
            if ($amount !== null) {
                $update['last_recharge_amount'] = $amount;
            }
            if ($reference) {
                $update['last_recharge_reference'] = $reference;
            }
            $assignment->update($update);
            $assignmentUpdated = true;
        } else {
            Log::info('Vodacom recharge callback: no user assignment yet', [
                'esim_id' => $esim->id,
                'msisdn' => $esim->msisdn,
            ]);
        }

        $customerRecharge = app(\App\Services\Esim\CustomerBundleRechargeService::class)
            ->callback(is_array($raw) ? $raw : $payload);
        $orderResult = $customerRecharge
            ? ['order_id' => null, 'recharge_status' => null]
            : $this->orderRecharge->applyRechargeCallback(is_array($raw) ? $raw : $payload);

        if (VodacomRechargePayload::isSuccessStatus($payload) && $esim->msisdn && empty($orderResult['order_id'])) {
            try {
                $this->balances->requestBalancesForMsisdn($esim->msisdn);
            } catch (\Throwable $e) {
                Log::warning('Balance refresh after recharge callback failed', [
                    'msisdn' => $esim->msisdn,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Vodacom recharge callback processed', [
            'user_esim_id' => $assignment?->id,
            'order_id' => $orderResult['order_id'] ?? null,
            'recharge_status' => $orderResult['recharge_status'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Recharge recorded',
            'data' => [
                'msisdn' => $esim->msisdn,
                'amount' => $amount,
                'price' => $payload['price'] ?? $amount,
                'status' => $status,
                'reference' => $reference,
                'transaction_id' => $transactionId,
                'product_id' => $payload['product_id'] ?? null,
                'product_type' => $payload['product_type'] ?? null,
                'assignment_updated' => $assignmentUpdated,
                'order_id' => $orderResult['order_id'] ?? null,
                'recharge_status' => $orderResult['recharge_status'] ?? null,
            ],
            'callback' => $payload,
        ]);
    }

    public function simsBalancesCallback(Request $request): JsonResponse
    {
        Log::info('Vodacom sims-balances callback received', $this->callbackLogContext($request));

        $raw = $request->all();
        if ($raw === []) {
            $decoded = json_decode((string) $request->getContent(), true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        $synced = $this->balances->syncFromVodacomPayload($raw);

        if ($synced === []) {
            Log::warning('Vodacom sims-balances callback: payload not applied', [
                'body' => $raw,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Balance payload was not recognized or SIM not found in inventory',
            ], 422);
        }

        $first = $synced[0];
        Log::info('Vodacom sims-balances callback processed', ['synced' => $synced]);

        return response()->json([
            'success' => true,
            'message' => 'Balance updated',
            'esim_id' => $first['esim_id'],
            'assignment_updated' => $first['assignment_updated'],
            'synced' => $synced,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackLogContext(Request $request): array
    {
        return [
            'method' => $request->method(),
            'ip' => $request->ip(),
            'query' => $request->query(),
            'body' => $request->all(),
            'raw_content' => mb_substr((string) $request->getContent(), 0, 8000),
        ];
    }

    private function requestBalancesAfterRecharge(string $msisdn): void
    {
        try {
            $this->balances->requestBalancesForMsisdn($msisdn);
        } catch (\Throwable $e) {
            Log::warning('Balance refresh after recharge failed', [
                'msisdn' => $msisdn,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function proxy($vodacomResponse)
    {
        $contentType = $vodacomResponse->header('Content-Type', 'application/json');
        $body = $vodacomResponse->body();

        return response($body, $vodacomResponse->status())
            ->header('Content-Type', $contentType ?: 'application/json');
    }
}
