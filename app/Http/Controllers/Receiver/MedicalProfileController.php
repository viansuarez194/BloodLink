<?php

namespace App\Http\Controllers\Receiver;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\RequestDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MedicalProfileController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $profileRequest = $user->bloodRequests()
            ->where('status', '!=', BloodRequest::STATUS_DRAFT)
            ->orderByRaw("CASE WHEN status IN ('pending', 'verified', 'matched', 'ready', 'in_transit', 'paused') THEN 0 ELSE 1 END")
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->with(['hospital', 'documents'])
            ->first();

        return view('receiver.medical-profile', [
            'user' => $user,
            'profileRequest' => $profileRequest,
            'canEditPatient' => $profileRequest
                && in_array($profileRequest->status, [BloodRequest::STATUS_PENDING, BloodRequest::STATUS_VERIFIED], true),
            'canUploadDocuments' => $profileRequest
                && in_array($profileRequest->status, [
                    BloodRequest::STATUS_PENDING,
                    BloodRequest::STATUS_VERIFIED,
                    BloodRequest::STATUS_PAUSED,
                ], true),
            'relationships' => config('bloodlink.requests.relationships'),
        ]);
    }

    public function updateAccount(Request $request)
    {
        $user = $request->user();
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'email' => Str::lower(trim((string) $request->input('email'))),
            'mobile' => User::normalizeMobile((string) $request->input('mobile')),
            'emergency_contact_mobile' => $request->filled('emergency_contact_mobile')
                ? User::normalizeMobile((string) $request->input('emergency_contact_mobile'))
                : null,
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'mobile' => ['required', 'regex:/^09\d{9}$/', Rule::unique('users', 'mobile')->ignore($user->id)],
            'home_city' => ['nullable', 'string', 'max:120'],
            'region' => ['required', Rule::in(array_keys(config('bloodlink.regions')))],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:80'],
            'emergency_contact_mobile' => ['nullable', 'regex:/^09\d{9}$/'],
        ], [
            'mobile.regex' => 'Enter a valid PH mobile number, e.g. 09171234567.',
            'emergency_contact_mobile.regex' => 'Enter a valid PH mobile number for your emergency contact.',
        ]);

        $user->fill($data)->save();

        return redirect()->route('receiver.profile')->with('status', 'Your account and emergency contact details were saved.');
    }

    public function updatePatient(Request $request, BloodRequest $bloodRequest)
    {
        abort_unless($bloodRequest->user_id === $request->user()->id, 404);
        abort_unless(in_array($bloodRequest->status, [BloodRequest::STATUS_PENDING, BloodRequest::STATUS_VERIFIED], true), 403);

        $request->merge([
            'contact_number' => $request->filled('contact_number')
                ? User::normalizeMobile((string) $request->input('contact_number'))
                : null,
        ]);

        $data = $request->validate([
            'patient_name' => ['required', 'string', 'max:120'],
            'relationship' => ['required', Rule::in(array_keys(config('bloodlink.requests.relationships')))],
            'patient_reference' => ['nullable', 'string', 'max:60'],
            'contact_number' => ['required', 'regex:/^09\d{9}$/'],
            'physician_name' => ['required', 'string', 'max:120'],
            'ward_service' => ['nullable', 'string', 'max:120'],
        ], [
            'contact_number.regex' => 'Enter a valid PH mobile number, e.g. 09171234567.',
        ]);

        DB::transaction(function () use ($bloodRequest, $data) {
            $lockedRequest = BloodRequest::query()->whereKey($bloodRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($lockedRequest->status, [BloodRequest::STATUS_PENDING, BloodRequest::STATUS_VERIFIED], true), 403);

            $lockedRequest->fill($data)->forceFill([
                'status' => BloodRequest::STATUS_PENDING,
                'verified_at' => null,
                'review_checks' => null,
            ])->save();
        });

        return redirect()->route('receiver.profile')->with(
            'status',
            'Patient details were saved. The facility must review the updated information before continuing coordination.'
        );
    }

    public function updateConsent(Request $request)
    {
        $data = $request->validate([
            'blood_request_id' => ['nullable', 'integer', 'min:1'],
            'share_authorized_data' => [
                Rule::requiredIf($request->filled('blood_request_id')),
                'boolean',
            ],
            'research_contact_consent' => ['required', 'boolean'],
        ]);
        $user = $request->user();

        DB::transaction(function () use ($data, $user) {
            $researchConsent = (bool) $data['research_contact_consent'];
            if ($researchConsent !== (bool) $user->research_contact_consent) {
                $user->forceFill([
                    'research_contact_consent' => $researchConsent,
                    'research_contact_consent_at' => $researchConsent ? now() : null,
                ])->save();
            }

            if (empty($data['blood_request_id'])) {
                return;
            }

            $bloodRequest = $user->bloodRequests()
                ->whereKey($data['blood_request_id'])
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(in_array($bloodRequest->status, BloodRequest::ACTIVE_STATUSES, true), 403);

            $shareConsent = (bool) ($data['share_authorized_data'] ?? false);
            if ($bloodRequest->status === BloodRequest::STATUS_PAUSED && $shareConsent) {
                $bloodRequest->forceFill([
                    'status' => BloodRequest::STATUS_PENDING,
                    'share_authorized_data' => true,
                    'consented_at' => now(),
                    'consent_revoked_at' => null,
                    'verified_at' => null,
                    'matched_at' => null,
                    'ready_at' => null,
                    'review_checks' => null,
                ])->save();

                $this->logConsentChange($bloodRequest, 'Request returned for review', 'The receiver restored information-sharing consent.');
            } elseif ($bloodRequest->status === BloodRequest::STATUS_IN_TRANSIT && ! $shareConsent) {
                $bloodRequest->forceFill([
                    'share_authorized_data' => false,
                    'consent_revoked_at' => now(),
                ])->save();
                $this->logConsentChange(
                    $bloodRequest,
                    'Consent withdrawn during transport',
                    'The receiver withdrew information-sharing consent after dispatch. The existing physical handoff remains in progress.'
                );
            } elseif ($bloodRequest->status !== BloodRequest::STATUS_PAUSED && ! $shareConsent) {
                $this->pauseRequest($bloodRequest);
            } elseif ($bloodRequest->status !== BloodRequest::STATUS_PAUSED && $shareConsent && ! $bloodRequest->share_authorized_data) {
                $bloodRequest->forceFill([
                    'share_authorized_data' => true,
                    'consented_at' => now(),
                    'consent_revoked_at' => null,
                ])->save();
            }
        });

        return redirect()->route('receiver.profile')->with(
            'status',
            'Your privacy preferences were saved. Any existing appointment must be handled separately with the facility.'
        );
    }

    public function uploadDocument(Request $request, BloodRequest $bloodRequest)
    {
        abort_unless($bloodRequest->user_id === $request->user()->id, 404);
        abort_unless(in_array($bloodRequest->status, [
            BloodRequest::STATUS_PENDING,
            BloodRequest::STATUS_VERIFIED,
            BloodRequest::STATUS_PAUSED,
        ], true), 403);

        $data = $request->validate([
            'kind' => ['required', Rule::in(['physician_request', 'authorization', 'supporting'])],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:' . (int) config('bloodlink.requests.max_file_kb')],
        ]);

        $path = $data['document']->store('request-documents/' . $request->user()->id, 'local');
        if (! $path) {
            throw new \RuntimeException('The document could not be stored on the private disk.');
        }

        DB::transaction(function () use ($bloodRequest, $request, $data, $path) {
            $lockedRequest = BloodRequest::query()->whereKey($bloodRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedRequest->user_id === $request->user()->id, 404);
            abort_unless(in_array($lockedRequest->status, [
                BloodRequest::STATUS_PENDING,
                BloodRequest::STATUS_VERIFIED,
                BloodRequest::STATUS_PAUSED,
            ], true), 403);

            if ($lockedRequest->documents()->count() >= (int) config('bloodlink.requests.max_files')) {
                Storage::disk('local')->delete($path);
                abort(422, 'This request has reached the document limit.');
            }

            $lockedRequest->documents()->create([
                'user_id' => $request->user()->id,
                'kind' => $data['kind'],
                'original_name' => mb_substr(basename((string) $data['document']->getClientOriginalName()), 0, 150),
                'path' => $path,
                'mime' => $data['document']->getMimeType(),
                'size' => $data['document']->getSize(),
            ]);
        });

        return redirect()->route('receiver.profile')->with('status', 'The document was uploaded to the private request record.');
    }

    private function pauseRequest(BloodRequest $bloodRequest): void
    {
        $hospitalId = $bloodRequest->hospital_id;
        $reference = $bloodRequest->reference;

        $bloodRequest->forceFill([
            'status' => BloodRequest::STATUS_PAUSED,
            'share_authorized_data' => false,
            'consent_revoked_at' => now(),
            'verified_at' => null,
            'matched_at' => null,
            'ready_at' => null,
            'pickup_instructions' => null,
            'review_checks' => null,
        ])->save();

        if (! $hospitalId) {
            return;
        }

        $released = BloodUnit::query()
            ->where('hospital_id', $hospitalId)
            ->where('reservation_reference', $reference)
            ->where('status', BloodUnit::STATUS_RESERVED)
            ->update([
                'status' => BloodUnit::STATUS_AVAILABLE,
                'reserved_for' => null,
                'reservation_reference' => null,
                'updated_at' => now(),
            ]);

        if ($released > 0) {
            BloodUnit::syncStocks($hospitalId);
        }

        $this->logConsentChange($bloodRequest, 'Request paused', 'The receiver withdrew information-sharing consent; in-app coordination is paused.');
    }

    private function logConsentChange(BloodRequest $bloodRequest, string $activity, string $details): void
    {
        if (! $bloodRequest->hospital_id) {
            return;
        }

        ActivityLog::create([
            'hospital_id' => $bloodRequest->hospital_id,
            'activity' => $activity,
            'details' => Str::limit($bloodRequest->reference . ' · ' . $details, 250, ''),
            'operator' => 'BloodLink system',
            'status' => 'logged',
        ]);
    }
}
