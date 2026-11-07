<?php

namespace App\Http\Controllers\Receiver;

use App\Http\Controllers\Controller;
use App\Models\BloodRequest;
use App\Models\BloodStock;
use App\Models\Hospital;
use App\Models\RequestDocument;
use App\Models\User;
use App\Notifications\BloodLinkNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RequestController extends Controller
{
    public function create(Request $request)
    {
        $user   = $request->user();
        $draft  = $this->draft($user)?->load('documents');
        $active = $this->activeRequest($user);

        return view('receiver.new-request', [
            'user'          => $user,
            'draft'         => $draft,
            'active'        => $active,
            'values'        => $this->values($request, $user, $draft, $active),
            'documents'     => $draft?->documents ?? collect(),
            'hospitals'     => Hospital::query()->where('is_partner', true)->orderBy('name')->get(),
            'bloodTypes'    => config('bloodlink.blood_types'),
            'components'    => BloodStock::COMPONENTS,
            'relationships' => config('bloodlink.requests.relationships'),
            'maxUnits'      => (int) config('bloodlink.requests.max_units'),
            'maxFiles'      => (int) config('bloodlink.requests.max_files'),
            'maxFileMb'     => (int) round(config('bloodlink.requests.max_file_kb') / 1024),
        ]);
    }

    public function store(Request $request)
    {
        $user       = $request->user();
        $submitting = $request->input('action') === 'submit';
        $draft      = $this->draft($user);
        $active     = $this->activeRequest($user);

        $request->merge([
            'contact_number' => $request->filled('contact_number')
                ? User::normalizeMobile((string) $request->input('contact_number'))
                : null,
        ]);

        // "Save draft" only checks that what was typed is valid; "Submit" requires everything.
        $required = $submitting ? 'required' : 'nullable';
        $maxFiles = (int) config('bloodlink.requests.max_files');

        $rules = [
            'patient_name'      => [$required, 'string', 'max:120'],
            'relationship'      => [$required, Rule::in(array_keys(config('bloodlink.requests.relationships')))],
            'patient_reference' => ['nullable', 'string', 'max:60'],
            'contact_number'    => [$required, 'regex:/^09\d{9}$/'],
            'blood_type'        => [$required, Rule::in(array_keys(config('bloodlink.blood_types')))],
            'component'         => [$required, Rule::in(array_keys(BloodStock::COMPONENTS))],
            'units'             => [$required, 'integer', 'between:1,' . (int) config('bloodlink.requests.max_units')],
            'urgency'           => [$required, Rule::in(['routine', 'urgent', 'critical'])],
            'needed_by'         => $submitting ? ['required', 'date', 'after:now'] : ['nullable', 'date'],
            'clinical_note'     => ['nullable', 'string', 'max:160'],
            'hospital_id'       => [$required, Rule::exists('hospitals', 'id')->where('is_partner', true)],
            'physician_name'    => [$required, 'string', 'max:120'],
            'ward_service'      => ['nullable', 'string', 'max:120'],
            'documents'         => ['nullable', 'array', 'max:' . $maxFiles],
            'documents.*'       => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:' . (int) config('bloodlink.requests.max_file_kb')],
        ];

        if ($submitting) {
            $rules['confirm_authorized'] = ['accepted'];
            $rules['confirm_consent']    = ['accepted'];

            if ($active) {
                $rules['confirm_new'] = ['accepted'];
            }
        }

        $request->validate($rules, $this->messages());

        $files    = array_filter($request->file('documents', []));
        $existing = $draft ? $draft->documents()->count() : 0;

        if ($existing + count($files) > $maxFiles) {
            throw ValidationException::withMessages(['documents' => "You can attach up to {$maxFiles} files."]);
        }

        if ($submitting && $existing + count($files) === 0) {
            throw ValidationException::withMessages(['documents' => 'Please upload a current signed physician request.']);
        }

        $urgency = $request->input('urgency');

        $attributes = [
            'patient_name'      => $request->input('patient_name'),
            'relationship'      => $request->input('relationship'),
            'patient_reference' => $request->input('patient_reference'),
            'contact_number'    => $request->input('contact_number'),
            'blood_type'        => $request->input('blood_type'),
            'component'         => $request->input('component') ?: BloodStock::COMPONENT_WHOLE_BLOOD,
            'units'             => $request->integer('units') ?: 1,
            'urgency'           => $urgency,
            'is_urgent'         => in_array($urgency, ['urgent', 'critical'], true),
            'needed_by'         => $request->filled('needed_by') ? Carbon::parse($request->input('needed_by')) : null,
            'clinical_note'     => $request->input('clinical_note'),
            'hospital_id'       => $request->input('hospital_id'),
            'physician_name'    => $request->input('physician_name'),
            'ward_service'      => $request->input('ward_service'),
        ];

        $saved = DB::transaction(function () use ($user, $draft, $attributes, $files, $submitting) {
            $draft ??= new BloodRequest(['user_id' => $user->id, 'status' => BloodRequest::STATUS_DRAFT]);
            $draft->fill($attributes)->save();

            foreach ($files as $file) {
                // Private disk: these files can only be opened through our own controller.
                $path = $file->store("request-documents/{$user->id}", 'local');

                $draft->documents()->create([
                    'user_id'       => $user->id,
                    'original_name' => mb_substr(basename((string) $file->getClientOriginalName()), 0, 150),
                    'path'          => $path,
                    'mime'          => $file->getMimeType(),
                    'size'          => $file->getSize(),
                ]);
            }

            if ($submitting) {
                $draft->forceFill([
                    'status'       => BloodRequest::STATUS_PENDING,
                    'reference'    => BloodRequest::generateReference(),
                    'submitted_at' => now(),
                    'consented_at' => now(),
                    'share_authorized_data' => true,
                    'created_at'   => now(),       // "Request filed" time
                ])->save();
            }

            return $draft;
        });

        if ($submitting) {
            $saved->loadMissing('hospital');
            $saved->hospital?->accounts()->each(fn ($account) => $account->notify(new BloodLinkNotification(
                'New consented blood request',
                'A new consented request, ' . $saved->reference . ', is ready for facility review.',
                '/hospital/requests',
                'request'
            )));

            return redirect()->route('receiver.dashboard')->with(
                'status',
                "Request {$saved->reference} was sent to " . ($saved->hospital?->name ?? 'the facility') . ' for review. Submission does not guarantee a match, stock reservation or clinical compatibility.'
            );
        }

        return redirect()->route('receiver.new-request')->with('status', 'Draft saved. You can finish and submit it anytime.');
    }

    /** Remove one uploaded file from a draft. */
    public function destroyDocument(Request $request, RequestDocument $document)
    {
        abort_unless(
            $document->user_id === $request->user()->id
                && $document->bloodRequest?->status === BloodRequest::STATUS_DRAFT,
            403
        );

        Storage::disk('local')->delete($document->path);
        $document->delete();

        return redirect()->route('receiver.new-request')->with('status', 'Document removed.');
    }

    /** Only the person who uploaded a document can open it. */
    public function showDocument(Request $request, RequestDocument $document)
    {
        abort_unless($document->user_id === $request->user()->id, 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ------------------------------------------------------------------

    private function draft(User $user): ?BloodRequest
    {
        return $user->bloodRequests()
            ->where('status', BloodRequest::STATUS_DRAFT)
            ->orderByDesc('id')
            ->first();
    }

    private function activeRequest(User $user): ?BloodRequest
    {
        return $user->bloodRequests()->active()->with('hospital')->orderByDesc('created_at')->first();
    }

    /**
     * What each field shows when the page opens:
     * saved draft, else a copy of the active request's requirement, else defaults,
     * then Find Blood's ?hospital=&blood_type=&component= on top.
     */
    private function values(Request $request, User $user, ?BloodRequest $draft, ?BloodRequest $active): array
    {
        $source = $draft ?? $active;

        $values = [
            'patient_name'      => $draft?->patient_name ?? $active?->patient_name,
            'relationship'      => $draft?->relationship ?? 'family',
            'patient_reference' => $draft?->patient_reference,
            'contact_number'    => $draft?->contact_number ?? $user->mobile,
            'blood_type'        => $source?->blood_type,
            'component'         => $source?->component ?? BloodStock::COMPONENT_WHOLE_BLOOD,
            'units'             => $source?->units ?? 1,
            'urgency'           => $draft?->urgency ?? ($active?->is_urgent ? 'urgent' : 'routine'),
            'needed_by'         => $draft?->needed_by?->format('Y-m-d\TH:i'),
            'clinical_note'     => $source?->clinical_note,
            'hospital_id'       => $source?->hospital_id,
            'physician_name'    => $draft?->physician_name,
            'ward_service'      => $draft?->ward_service,
        ];

        // Coming from Find Blood → "Request reserve"
        $type = $request->query('blood_type');
        if (is_string($type) && array_key_exists($type, config('bloodlink.blood_types'))) {
            $values['blood_type'] = $type;
        }

        $component = $request->query('component');
        if (is_string($component) && array_key_exists($component, BloodStock::COMPONENTS)) {
            $values['component'] = $component;
        }

        $hospitalId = (int) $request->query('hospital');
        if ($hospitalId > 0 && Hospital::query()->whereKey($hospitalId)->where('is_partner', true)->exists()) {
            $values['hospital_id'] = $hospitalId;
        }

        return $values;
    }

    private function messages(): array
    {
        return [
            'patient_name.required'      => 'Enter the patient’s full name.',
            'relationship.required'      => 'Tell us how you are related to the patient.',
            'contact_number.required'    => 'Enter a contact number.',
            'contact_number.regex'       => 'Enter a valid PH mobile number, e.g. 09171234567.',
            'blood_type.required'        => 'Select the blood group.',
            'component.required'         => 'Select the component.',
            'units.required'             => 'Select how many units are needed.',
            'urgency.required'           => 'Select the urgency.',
            'needed_by.required'         => 'Choose when the blood is needed.',
            'needed_by.after'            => 'The needed-by time must be in the future.',
            'hospital_id.required'       => 'Choose the treating facility.',
            'hospital_id.exists'         => 'Choose a facility from the list.',
            'physician_name.required'    => 'Enter the attending physician from the signed request.',
            'documents.*.mimes'          => 'Upload a PDF, JPG or PNG file.',
            'documents.*.max'            => 'Each file must be 10 MB or smaller.',
            'documents.*.uploaded'       => 'A file could not be uploaded. It may be larger than your server allows (see upload_max_filesize in php.ini).',
            'confirm_authorized.accepted' => 'Please confirm you are authorized to request for this patient.',
            'confirm_consent.accepted'   => 'Please give your consent to share the necessary health information.',
            'confirm_new.accepted'       => 'Please confirm this is a new requirement, not a duplicate.',
        ];
    }
}
