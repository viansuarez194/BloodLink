<?php

namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BloodRequest;
use App\Models\BloodStock;
use App\Models\BloodUnit;
use App\Models\Dispatch;
use App\Models\Hospital;
use App\Models\RequestDocument;
use App\Notifications\BloodLinkNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequestReviewController extends Controller
{
    private const FILTERS = ['all', 'critical', 'other'];

    /** The checks a staff member ticks by hand. */
    private const MANUAL_CHECKS = ['physician_order', 'abo_confirmed', 'compatibility_completed', 'release_authorized'];
    private const PICKUP_CHECKS = ['identity_confirmed', 'units_reconciled', 'product_integrity_confirmed'];

    // ------------------------------------------------------------------
    // Queue + review page
    // ------------------------------------------------------------------
    public function index(Request $request)
    {
        $user     = $request->user();
        $hospital = $user->ensureFacility();

        $all = $this->queue($hospital);

        $counts = [
            'all'      => $all->count(),
            'critical' => $all->filter(fn ($r) => $r->priority() === 'critical')->count(),
        ];
        $counts['other'] = $counts['all'] - $counts['critical'];

        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : 'all';

        $queue = match ($filter) {
            'critical' => $all->filter(fn ($r) => $r->priority() === 'critical')->values(),
            'other'    => $all->filter(fn ($r) => $r->priority() !== 'critical')->values(),
            default    => $all,
        };

        $selected = $queue->firstWhere('id', (int) $request->query('request')) ?? $queue->first();

        $review = $selected ? $this->reviewData($hospital, $selected) : null;

        return view('hospital.requests-review', [
            'hospital' => $hospital,
            'queue'    => $queue,
            'counts'   => $counts,
            'filter'   => $filter,
            'selected' => $selected,
            'review'   => $review,
        ]);
    }

    private function hasPhysicianOrder(BloodRequest $request): bool
    {
        return $request->documents()
            ->where('kind', 'physician_request')
            ->where('status', '!=', 'rejected')
            ->exists();
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /** Save the four manual checks. */
    public function checklist(Request $request, BloodRequest $bloodRequest)
    {
        $hospital = $request->user()->ensureFacility();
        $this->authorizeRequest($bloodRequest, $hospital);
        abort_unless(in_array($bloodRequest->status, [
            BloodRequest::STATUS_PENDING,
            BloodRequest::STATUS_VERIFIED,
            BloodRequest::STATUS_MATCHED,
        ], true), 409);

        $checks = [];
        foreach (self::MANUAL_CHECKS as $key) {
            $checks[$key] = $request->boolean($key);
        }

        $bloodRequest->update(['review_checks' => $checks]);

        return $this->back($bloodRequest)->with('status', 'Checklist saved.');
    }

    public function readyForPickup(Request $request, BloodRequest $bloodRequest)
    {
        $user = $request->user();
        $hospital = $user->ensureFacility();
        $this->authorizeRequest($bloodRequest, $hospital);
        $data = $request->validate([
            'pickup_instructions' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'pickup_instructions.required' => 'Provide the pickup location, hours, and any facility-specific instructions.',
        ]);

        DB::transaction(function () use ($bloodRequest, $hospital, $user, $data) {
            $req = BloodRequest::query()->whereKey($bloodRequest->id)->lockForUpdate()->firstOrFail();
            if ($req->status !== BloodRequest::STATUS_MATCHED) {
                throw ValidationException::withMessages(['pickup' => 'Only a matched request can be marked ready for pickup.']);
            }
            if (! $req->share_authorized_data) {
                throw ValidationException::withMessages(['pickup' => 'The receiver has withdrawn information-sharing consent. Do not release this request.']);
            }
            if (! $this->hasPhysicianOrder($req) || ! $req->reviewCheck('physician_order')) {
                throw ValidationException::withMessages(['pickup' => 'A signed physician order must be attached and reviewed before pickup authorization.']);
            }
            foreach (self::MANUAL_CHECKS as $check) {
                if (! $req->reviewCheck($check)) {
                    throw ValidationException::withMessages([
                        'pickup' => 'Complete and save every clinical verification and release authorization checklist item first.',
                    ]);
                }
            }
            if (Dispatch::query()->where('blood_request_id', $req->id)
                ->whereNotIn('status', [Dispatch::STATUS_CANCELLED])->exists()
            ) {
                throw ValidationException::withMessages(['pickup' => 'This request already has a delivery dispatch. Complete that handoff instead of preparing a pickup.']);
            }

            $units = BloodUnit::query()
                ->where('hospital_id', $hospital->id)
                ->where('reservation_reference', $req->reference)
                ->where('status', BloodUnit::STATUS_RESERVED)
                ->where('blood_type', $req->blood_type)
                ->where('component', $req->component)
                ->where('expires_at', '>=', today())
                ->orderBy('expires_at')->orderBy('unit_code')
                ->lockForUpdate()->get();

            if ($units->count() !== $req->units) {
                throw ValidationException::withMessages(['pickup' => 'The reserved unit count or expiry no longer matches this request. Review inventory before authorizing pickup.']);
            }

            $req->forceFill([
                'status' => BloodRequest::STATUS_READY,
                'ready_at' => now(),
                'pickup_instructions' => trim($data['pickup_instructions']),
            ])->save();
        });

        $this->log($hospital, $user->name, 'Request authorized for pickup', $bloodRequest->reference, 'coordinating');
        if ($bloodRequest->share_authorized_data && $bloodRequest->user) {
            $bloodRequest->user->notify(new BloodLinkNotification(
                'Blood request ready for pickup',
                $bloodRequest->reference . ' is ready for authorized pickup at ' . $hospital->name . '. Review the pickup instructions before traveling.',
                route('receiver.requests.show', $bloodRequest, false),
                'request'
            ));
        }

        return $this->back($bloodRequest)->with('status', 'Pickup authorization recorded and the receiver was notified.');
    }

    public function completePickup(Request $request, BloodRequest $bloodRequest)
    {
        $user = $request->user();
        $hospital = $user->ensureFacility();
        $this->authorizeRequest($bloodRequest, $hospital);
        $data = $request->validate([
            'pickup_recipient_name' => ['required', 'string', 'max:120'],
            'pickup_recipient_relationship' => ['required', 'in:patient,authorized representative'],
            'identity_confirmed' => ['accepted'],
            'units_reconciled' => ['accepted'],
            'product_integrity_confirmed' => ['accepted'],
        ]);

        DB::transaction(function () use ($bloodRequest, $hospital, $user, $data) {
            $req = BloodRequest::query()->whereKey($bloodRequest->id)->lockForUpdate()->firstOrFail();
            if ($req->status !== BloodRequest::STATUS_READY) {
                throw ValidationException::withMessages(['pickup' => 'Only an authorized pickup can be completed.']);
            }

            $units = BloodUnit::query()
                ->where('hospital_id', $hospital->id)
                ->where('reservation_reference', $req->reference)
                ->where('status', BloodUnit::STATUS_RESERVED)
                ->where('blood_type', $req->blood_type)
                ->where('component', $req->component)
                ->where('expires_at', '>=', today())
                ->orderBy('unit_code')
                ->lockForUpdate()->get();

            if ($units->count() !== $req->units) {
                throw ValidationException::withMessages(['pickup' => 'The reserved unit count or expiry no longer matches this request. Do not complete the handoff.']);
            }

            foreach ($units as $unit) {
                $unit->update(['status' => BloodUnit::STATUS_DISPATCHED]);
            }

            $req->forceFill([
                'status' => BloodRequest::STATUS_COMPLETED,
                'completed_at' => now(),
                'pickup_recipient_name' => trim($data['pickup_recipient_name']),
                'pickup_recipient_relationship' => $data['pickup_recipient_relationship'],
                'pickup_completed_by' => $user->name,
                'pickup_handoff_checks' => [
                    'identity_confirmed' => true,
                    'units_reconciled' => true,
                    'product_integrity_confirmed' => true,
                ],
            ])->save();

            BloodUnit::syncStocks($hospital->id);
        });

        $this->log(
            $hospital,
            $user->name,
            'Request pickup completed',
            $bloodRequest->reference . ' · released to ' . $data['pickup_recipient_name'],
            'completed'
        );
        if ($bloodRequest->share_authorized_data && $bloodRequest->user) {
            $bloodRequest->user->notify(new BloodLinkNotification(
                'Blood request handoff recorded',
                $bloodRequest->reference . ' was handed to ' . $data['pickup_recipient_name'] . ' and marked complete by ' . $hospital->name . '.',
                route('receiver.requests.show', $bloodRequest, false),
                'request'
            ));
        }

        return $this->back($bloodRequest)->with('status', 'Pickup handoff recorded and the request was completed.');
    }

    /** Accept for coordination: reserve the earliest-expiry units and mark the request as matched. */
    public function accept(Request $request, BloodRequest $bloodRequest)
    {
        $user     = $request->user();
        $hospital = $user->ensureFacility();
        $this->authorizeRequest($bloodRequest, $hospital);

        $reservedCount = 0;

        DB::transaction(function () use ($bloodRequest, $hospital, &$reservedCount) {
            // Lock the request so two staff members cannot accept it twice.
            $req = BloodRequest::query()->whereKey($bloodRequest->id)->lockForUpdate()->first();

            if (! in_array($req->status, [BloodRequest::STATUS_PENDING, BloodRequest::STATUS_VERIFIED], true)) {
                throw ValidationException::withMessages(['accept' => 'This request was already handled.']);
            }

            if (! $this->hasPhysicianOrder($req)) {
                throw ValidationException::withMessages(['accept' => 'No active physician order is attached. Ask the requester for the required document first.']);
            }

            if (! $req->reviewCheck('physician_order')) {
                throw ValidationException::withMessages(['accept' => 'Review the signed physician order and tick it in the checklist before accepting.']);
            }

            $units = BloodUnit::query()
                ->where('hospital_id', $hospital->id)
                ->where('component', $req->component)
                ->where('blood_type', $req->blood_type)
                ->where('status', BloodUnit::STATUS_AVAILABLE)
                ->where('expires_at', '>=', today())
                ->orderBy('expires_at')->orderBy('unit_code')
                ->limit($req->units)
                ->lockForUpdate()
                ->get();

            if ($units->count() < $req->units) {
                throw ValidationException::withMessages(['accept' => "Only {$units->count()} of {$req->units} units are available in stock. Request a transfer, or ask the requester for clarification."]);
            }

            $requester = $req->user?->displayName() ?? 'Requester';

            foreach ($units as $unit) {
                $unit->update([
                    'status'                => BloodUnit::STATUS_RESERVED,
                    'reserved_for'          => $requester,
                    'reservation_reference' => $req->reference,
                ]);
            }

            $req->forceFill([
                'status'      => BloodRequest::STATUS_MATCHED,
                'verified_at' => $req->verified_at ?? now(),
                'matched_at'  => now(),
            ])->save();

            $reservedCount = $units->count();
        });

        $this->log($hospital, $user->name, 'Request accepted', "{$bloodRequest->reference} · {$reservedCount} " . Str::plural('unit', $reservedCount) . ' reserved', 'coordinating');
        if ($bloodRequest->share_authorized_data && $bloodRequest->user) {
            $bloodRequest->user->notify(new BloodLinkNotification(
                'Request accepted for coordination',
                $bloodRequest->reference . ' was accepted by ' . $hospital->name . ' and units were reserved for coordination.',
                route('receiver.requests.show', $bloodRequest, false),
                'request'
            ));
        }

        return redirect()->route('hospital.requests')->with(
            'status',
            "{$bloodRequest->reference} accepted for coordination. {$reservedCount} " . Str::plural('unit', $reservedCount) . ' reserved. This is not a clinical release; all pending checks must be completed before dispatch.'
        );
    }

    /** Ask the requester for more information. They will see the message on their request page. */
    public function clarify(Request $request, BloodRequest $bloodRequest)
    {
        $user     = $request->user();
        $hospital = $user->ensureFacility();
        $this->authorizeRequest($bloodRequest, $hospital);

        $data = $request->validate([
            'message' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'message.required' => 'Write what you need the requester to clarify.',
            'message.min'      => 'Please write at least a short sentence.',
            'message.max'      => 'Keep the message under 500 characters.',
        ]);

        $bloodRequest->update([
            'clarification_note' => $data['message'],
            'clarification_at'   => now(),
        ]);
        if ($bloodRequest->share_authorized_data && $bloodRequest->user) {
            $bloodRequest->user->notify(new BloodLinkNotification(
                'More information requested',
                $hospital->name . ' sent a clarification request for ' . $bloodRequest->reference . '. Open the request to review it.',
                route('receiver.requests.show', $bloodRequest, false),
                'request'
            ));
        }

        $this->log($hospital, $user->name, 'Clarification requested', $bloodRequest->reference, 'logged');

        return $this->back($bloodRequest)->with('status', 'Your message was sent. The requester will see it on their request page.');
    }

    /** Record that a critical shortage was escalated. */
    public function escalate(Request $request, BloodRequest $bloodRequest)
    {
        $user     = $request->user();
        $hospital = $user->ensureFacility();
        $this->authorizeRequest($bloodRequest, $hospital);

        if (! $bloodRequest->escalated_at) {
            $bloodRequest->update(['escalated_at' => now()]);
            $this->log($hospital, $user->name, 'Shortage escalated', "{$bloodRequest->reference} · " . $bloodRequest->requirementText(), 'coordinating');
        }

        return $this->back($bloodRequest)->with(
            'status',
            'Escalation recorded in your activity log. Automatic network alerts are not available yet, so please also call your partner coordinators or dial 143.'
        );
    }

    /** Only the facility the request was sent to can open its documents. */
    public function document(Request $request, RequestDocument $document)
    {
        $hospital = $request->user()->ensureFacility();
        $req      = $document->bloodRequest;

        abort_unless(
            $req
                && $req->hospital_id === $hospital->id
                && $req->status !== BloodRequest::STATUS_DRAFT
                && $req->status !== BloodRequest::STATUS_PAUSED
                && $req->share_authorized_data,
            404
        );
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------
    /** Requests waiting for a decision: most urgent first, then earliest need-by time. */
    private function queue(Hospital $hospital)
    {
        return BloodRequest::query()
            ->where('hospital_id', $hospital->id)
            ->where('share_authorized_data', true)
            ->whereIn('status', [
                BloodRequest::STATUS_PENDING,
                BloodRequest::STATUS_VERIFIED,
                BloodRequest::STATUS_MATCHED,
                BloodRequest::STATUS_READY,
            ])
            ->with('user')
            ->get()
            ->sort(fn ($a, $b) => [$a->priorityRank(), $a->needed_by?->getTimestamp() ?? PHP_INT_MAX, $a->created_at->getTimestamp()]
                              <=> [$b->priorityRank(), $b->needed_by?->getTimestamp() ?? PHP_INT_MAX, $b->created_at->getTimestamp()])
            ->values();
    }

    /** Everything the right-hand side of the page needs for the selected request. */
    private function reviewData(Hospital $hospital, BloodRequest $req): array
    {
        $req->load('documents');

        $stock = BloodStock::query()
            ->where('hospital_id', $hospital->id)
            ->where('component', $req->component)
            ->where('blood_type', $req->blood_type)
            ->first();

        $availableQuery = BloodUnit::query()
            ->where('hospital_id', $hospital->id)
            ->where('component', $req->component)
            ->where('blood_type', $req->blood_type)
            ->where('status', BloodUnit::STATUS_AVAILABLE)
            ->where('expires_at', '>=', today());

        $reservedRequest = in_array($req->status, [BloodRequest::STATUS_MATCHED, BloodRequest::STATUS_READY], true);
        $proposalQuery = $reservedRequest
            ? BloodUnit::query()
                ->where('hospital_id', $hospital->id)
                ->where('component', $req->component)
                ->where('blood_type', $req->blood_type)
                ->where('status', BloodUnit::STATUS_RESERVED)
                ->where('reservation_reference', $req->reference)
                ->where('expires_at', '>=', today())
            : $availableQuery;
        $proposal       = (clone $proposalQuery)->orderBy('expires_at')->orderBy('unit_code')->limit($req->units)->get();
        $availableCount = (clone $availableQuery)->count();

        $type      = $req->bloodTypeLabel();
        $requester = $req->user;
        $hasDocs   = $req->documents->contains(fn (RequestDocument $document) =>
            $document->kind === 'physician_request' && $document->status !== 'rejected'
        );

        // [title, note, state: auto|manual, done, key]
        $checklist = [
            ['Requester identity and contact details received',
                ($requester?->displayName() ?? 'Requester') . ' · ' . ($req->contact_number ?: 'no contact number'),
                'auto', $requester !== null && filled($req->contact_number), null],
            ['Patient identifiers and quantity received',
                ($req->patient_name ?: 'Patient') . ' · ' . $req->units . ' ' . Str::plural('unit', $req->units) . ' requested.',
                'auto', filled($req->patient_name) && $req->units > 0, null],
            ['Signed physician order and indication reviewed',
                $hasDocs ? 'Open the uploaded order above, then tick this item.' : 'No document is attached to this request.',
                'manual', $req->reviewCheck('physician_order'), 'physician_order'],
            ['Patient ABO/Rh independently confirmed',
                "{$type} is requested; confirm against the facility’s validated records.",
                'manual', $req->reviewCheck('abo_confirmed'), 'abo_confirmed'],
            ['Compatibility testing completed and result reviewed',
                'This is a staff attestation; BloodLink does not perform or validate laboratory testing.',
                'manual', $req->reviewCheck('compatibility_completed'), 'compatibility_completed'],
            ['Authorized blood-bank professional approved release',
                'Confirm facility release policy and record pickup instructions before marking ready.',
                'manual', $req->reviewCheck('release_authorized'), 'release_authorized'],
        ];

        $onHand  = $stock?->units ?? 0;
        $minimum = $stock?->minimum_reserve;

        return [
            'stock'          => $stock,
            'onHand'         => $onHand,
            'leftAfter'      => max(0, $onHand - $req->units),
            'proposal'       => $proposal,
            'availableCount' => $availableCount,
            'enough'         => $proposal->count() >= $req->units,
            'reservedRequest' => $reservedRequest,
            'checklist'      => $checklist,
            'hasDocs'        => $hasDocs,
            'stockStatus'    => $stock ? $stock->status() : null,
            'minimum'        => $minimum,
            'storage'        => $proposal->map(fn ($u) => $u->storageLabel())->reject(fn ($l) => $l === '—')->unique()->implode(', '),
            'requirement'    => $req->requirementText(),
        ];
    }

    private function authorizeRequest(BloodRequest $req, Hospital $hospital): void
    {
        abort_unless(
            $req->hospital_id === $hospital->id
                && $req->status !== BloodRequest::STATUS_DRAFT
                && $req->status !== BloodRequest::STATUS_PAUSED
                && $req->share_authorized_data,
            404
        );
    }

    private function back(BloodRequest $req)
    {
        return redirect()->route('hospital.requests', ['request' => $req->id]);
    }

    private function log(Hospital $hospital, string $operator, string $activity, string $details, string $status): void
    {
        ActivityLog::create([
            'hospital_id' => $hospital->id,
            'activity'    => $activity,
            'details'     => Str::limit($details, 250, ''),
            'operator'    => $operator,
            'status'      => $status,
        ]);
    }
}
