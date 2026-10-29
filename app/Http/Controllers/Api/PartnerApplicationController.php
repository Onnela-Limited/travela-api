<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePartnerApplicationRequest;
use App\Models\PartnerApplication;
use App\Support\PartnerIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PartnerApplicationController extends Controller
{
    public function store(StorePartnerApplicationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $existing = PartnerApplication::where('submission_key', $data['submission_key'])->first();
        if ($existing) {
            return $this->receipt($existing, 200);
        }

        $stored = [];
        $reference = 'TP-'.now()->year.'-'.Str::upper((string) Str::uuid());
        try {
            foreach ($request->file('documents', []) as $kind => $file) {
                $path = $file->store("partner-applications/$reference", 'local');
                if ($path === false) {
                    throw new RuntimeException('Could not save application documents.');
                }
                $stored[$kind] = $path;
            }

            $application = DB::transaction(fn () => PartnerApplication::create([
                'submission_key' => $data['submission_key'],
                'reference' => $reference,
                'status' => 'under_review',
                'application_payload' => $data['application'],
                'email_hash' => PartnerIdentity::emailHash($data['application']['profile']['email']),
                'documents' => $stored,
                'submitted_at' => now(),
            ]));
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_values($stored));
            // A simultaneous retry must return the original receipt, not create another application.
            if ($exception instanceof UniqueConstraintViolationException) {
                $existing = PartnerApplication::where('submission_key', $data['submission_key'])->first();
                if ($existing) {
                    return $this->receipt($existing, 200);
                }
            }
            throw $exception;
        }

        return $this->receipt($application, 201);
    }

    public function show(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'reference' => ['required', 'string', 'max:64'],
            'submission_key' => ['required', 'uuid'],
        ]);

        $application = PartnerApplication::query()
            ->select(['reference', 'status', 'submitted_at', 'review_version', 'review_message', 'reviewed_at'])
            ->where('reference', $credentials['reference'])
            ->where('submission_key', $credentials['submission_key'])
            ->first();

        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404)
                ->header('Cache-Control', 'no-store');
        }

        return $this->receipt($application, 200);
    }

    private function receipt(PartnerApplication $application, int $status): JsonResponse
    {
        return response()->json(['application' => [
            'reference' => $application->reference,
            'status' => $application->status,
            'submittedAt' => $application->submitted_at->toIso8601String(),
            ...($application->review_version > 0 ? [
                'reviewMessage' => $application->review_message,
                'reviewedAt' => $application->reviewed_at?->toIso8601String(),
            ] : []),
        ]], $status)->header('Cache-Control', 'no-store');
    }
}
