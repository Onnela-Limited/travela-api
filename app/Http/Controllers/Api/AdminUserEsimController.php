<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Esim;
use App\Models\Order;
use App\Models\User;
use App\Models\UserEsim;
use App\Services\Esim\UserEsimOrderLinkService;
use App\Services\Esim\VodacomSimManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserEsimController extends Controller
{
    public function __construct(
        private readonly VodacomSimManagerService $vodacom,
        private readonly UserEsimOrderLinkService $esimOrderLink,
    ) {}

    /**
     * Return every user with a count of how many eSIMs they hold on the platform.
     */
    public function userEsimCounts(): JsonResponse
    {
        $counts = User::select('id', 'name', 'email')
            ->withCount('esims')
            ->orderByDesc('esims_count')
            ->get()
            ->map(fn (User $u) => [
                'user_id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'esim_count' => $u->esims_count,
            ]);

        return response()->json([
            'success' => true,
            'total_assigned' => $counts->sum('esim_count'),
            'data' => $counts,
        ]);
    }

    public function index()
    {
        $items = UserEsim::with(['user:id,name,email,role', 'esim', 'bundle', 'order', 'orderItem'])
            ->orderBy('id', 'desc')
            ->paginate(50);

        $items->getCollection()->transform(fn (UserEsim $row) => $row->toAssignmentArray());

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'esim_id' => ['nullable', 'integer', 'exists:esims,id'],
            'msisdn' => ['nullable', 'string'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'bundle_id' => ['nullable', 'integer', 'exists:bundles,id'],
        ]);

        $esimId = $data['esim_id'] ?? null;

        if (! $esimId && ! empty($data['msisdn'])) {
            $esimId = Esim::findByMsisdn($data['msisdn'])?->id;
        }

        if (! $esimId) {
            return response()->json(['message' => 'Provide esim_id or msisdn.'], 422);
        }

        // Assignment: cannot reuse an esim_id (unique constraint)
        $assignment = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $esimId) {
            Esim::whereKey($esimId)->lockForUpdate()->firstOrFail();
            return UserEsim::create([
                'user_id' => $data['user_id'], 'esim_id' => $esimId,
                'bundle_id' => $data['bundle_id'] ?? null, 'order_id' => $data['order_id'] ?? null,
            ]);
        });

        // Mark inventory as managed
        Esim::where('id', $esimId)->update(['status' => 'MANAGED']);

        if (! empty($data['order_id'])) {
            $order = Order::with('orderItems')->find($data['order_id']);
            if ($order) {
                $assignment = $this->esimOrderLink->linkAssignmentToOrder($assignment, $order);
            }
        } elseif (empty($data['bundle_id'])) {
            $assignment = $this->esimOrderLink->linkAssignmentFromLatestPaidOrder($assignment);
        }

        $assignment->load(['user:id,name,email,role', 'esim', 'bundle', 'order', 'orderItem']);

        return response()->json([
            'assignment' => $assignment->toAssignmentArray(),
        ], 201);
    }

    public function destroy(int $id)
    {
        $esim = UserEsim::find($id);

        if (! $esim) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $esim->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function syncFromVodacom(Request $request)
    {
        $query = [
            'status' => $request->query('status', 'ALL'),
            'page' => (int) $request->query('page', 1),
            'page_size' => (int) $request->query('page_size', 50),
        ];

        return $this->proxy($this->vodacom->get('/api/sims', $query));
    }

    private function proxy($vodacomResponse)
    {
        $contentType = $vodacomResponse->header('Content-Type', 'application/json');
        $body = $vodacomResponse->body();

        return response($body, $vodacomResponse->status())
            ->header('Content-Type', $contentType ?: 'application/json');
    }
}
