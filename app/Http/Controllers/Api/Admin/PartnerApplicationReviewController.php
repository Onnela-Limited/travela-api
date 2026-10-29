<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnerApplication;
use App\Models\PartnerApplicationReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartnerApplicationReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['under_review', 'info_requested', 'approved', 'rejected', 'suspended'])],
            'reference' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $query = PartnerApplication::query();
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['reference'])) $query->where('reference', 'like', $filters['reference'].'%');
        $page = $query->orderByDesc('submitted_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'applications' => collect($page->items())->map(fn ($application) => $this->summary($application))->all(),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total(), 'perPage' => $page->perPage()],
        ])->header('Cache-Control', 'no-store');
    }

    public function show(PartnerApplication $application): JsonResponse
    {
        return response()->json(['application' => $this->detail($application)])->header('Cache-Control', 'no-store');
    }

    public function document(PartnerApplication $application, string $kind): StreamedResponse
    {
        abort_unless(in_array($kind, ['id_front', 'id_back', 'selfie', 'registration', 'director_id'], true), 404);
        $path = $application->documents[$kind] ?? null;
        $disk = Storage::disk('local');
        abort_unless(is_string($path) && str_starts_with($path, "partner-applications/$application->reference/") && ! str_contains($path, '..') && $disk->exists($path), 404);
        $mime = $disk->mimeType($path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true), 404);
        $extension = match ($mime) { 'image/jpeg' => 'jpg', 'image/png' => 'png', default => 'pdf' };

        return $disk->response($path, "application-$application->id-$kind.$extension", [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; sandbox",
        ], 'inline');
    }

    public function review(Request $request, PartnerApplication $application): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected', 'info_requested'])],
            'expected_version' => ['required', 'integer', 'min:0'],
            'message' => ['required_if:decision,rejected,info_requested', 'nullable', 'string', 'max:2000'],
        ]);
        $reviewed = DB::transaction(function () use ($application, $request, $data) {
            $current = PartnerApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            abort_if($current->review_version !== (int) $data['expected_version'], 409, 'This application changed. Refresh before saving a decision.');
            abort_unless(in_array($current->status, ['under_review', 'info_requested'], true), 409, 'This application is no longer awaiting review.');
            $message = isset($data['message']) ? trim($data['message']) : null;
            PartnerApplicationReview::create([
                'partner_application_id' => $current->id,
                'reviewer_id' => $request->user()->id,
                'reviewer_name' => $request->user()->name,
                'from_status' => $current->status,
                'to_status' => $data['decision'],
                'message' => $message ?: null,
                'created_at' => now(),
            ]);
            $current->update([
                'status' => $data['decision'], 'review_message' => $message ?: null,
                'reviewed_at' => now(), 'review_version' => $current->review_version + 1,
            ]);
            return $current;
        });

        return response()->json(['application' => $this->detail($reviewed)])->header('Cache-Control', 'no-store');
    }

    private function summary(PartnerApplication $application): array
    {
        $payload = $application->application_payload;
        return [
            'id' => $application->id, 'reference' => $application->reference, 'status' => $application->status,
            'type' => $payload['type'] ?? '', 'displayName' => $payload['profile']['displayName'] ?? '',
            'email' => $payload['profile']['email'] ?? '', 'submittedAt' => $application->submitted_at->toIso8601String(),
            'reviewVersion' => $application->review_version, 'reviewedAt' => $application->reviewed_at?->toIso8601String(),
        ];
    }

    private function detail(PartnerApplication $application): array
    {
        return [
            ...$this->summary($application),
            'details' => $application->application_payload,
            'documents' => array_keys($application->documents ?? []),
            'reviewMessage' => $application->review_message,
            'history' => PartnerApplicationReview::where('partner_application_id', $application->id)->orderByDesc('id')->limit(50)->get()->map(fn ($review) => [
                'id' => $review->id, 'reviewer' => $review->reviewer_name,
                'fromStatus' => $review->from_status, 'toStatus' => $review->to_status,
                'message' => $review->message, 'createdAt' => $review->created_at->toIso8601String(),
            ])->all(),
        ];
    }
}
