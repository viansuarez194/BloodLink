<?php

namespace App\Http\Controllers\Receiver;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BloodRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RequestTrackingController extends Controller
{
    public function __invoke(Request $request, BloodRequest $bloodRequest)
    {
        $user = $request->user();

        // Only the receiver who filed the request can open it.
        abort_unless($bloodRequest->user_id === $user->id, 404);

        if ($bloodRequest->status === BloodRequest::STATUS_DRAFT) {
            return redirect()->route('receiver.new-request');
        }

        $bloodRequest->load(['hospital', 'documents']);

        $hospital     = $bloodRequest->hospital;
        $hospitalName = $hospital?->name ?? 'The facility';
        $type         = $bloodRequest->bloodTypeLabel();
        $unitsText    = $bloodRequest->units . ' ' . $type . ' ' . $this->componentAdjective($bloodRequest) . ' ' . Str::plural('unit', $bloodRequest->units);

        // The receiver's upcoming appointment at the facility handling this request.
        $appointment = null;
        if ($hospital && in_array($bloodRequest->status, [BloodRequest::STATUS_VERIFIED, BloodRequest::STATUS_MATCHED, BloodRequest::STATUS_READY], true)) {
            $appointment = $user->appointments()->with('hospital')
                ->where('status', Appointment::STATUS_SCHEDULED)
                ->where('hospital_id', $hospital->id)
                ->where('scheduled_at', '>=', now())
                ->orderBy('scheduled_at')
                ->first();
        }

        $urgency = $bloodRequest->urgency ?? ($bloodRequest->is_urgent ? 'urgent' : 'routine');

        return view('receiver.request-tracking', [
            'user'         => $user,
            'req'          => $bloodRequest,
            'hospital'     => $hospital,
            'hospitalName' => $hospitalName,
            'unitsText'    => $unitsText,
            'urgency'      => $urgency,
            'banner'       => $this->banner($bloodRequest, $hospitalName, $unitsText),
            'events'       => $this->timeline($bloodRequest, $hospitalName, $unitsText, Str::before($user->name, ' ')),
            'checklist'    => $this->checklist($bloodRequest, $hospitalName),
            'appointment'  => $appointment,
            'reservation'  => $appointment?->reservation(),
            'documents'    => $bloodRequest->documents,
        ]);
    }

    private function componentAdjective(BloodRequest $r): string
    {
        return ['whole_blood' => 'whole-blood', 'packed_rbc' => 'packed-RBC', 'platelets' => 'platelet', 'plasma' => 'plasma'][$r->component]
            ?? $r->componentLabel();
    }

    /** The coloured message at the top. [kind, title, text] */
    private function banner(BloodRequest $r, string $hospital, string $units): array
    {
        // The facility asked the requester a question.
        if ($r->clarification_note && in_array($r->status, [BloodRequest::STATUS_PENDING, BloodRequest::STATUS_VERIFIED], true)) {
            return ['amber', 'The facility needs more information',
                "{$hospital} asked: \"{$r->clarification_note}\" Please contact the facility to answer. No blood is reserved yet."];
        }

        return match ($r->status) {
            BloodRequest::STATUS_PAUSED => ['grey', 'Coordination paused — consent withdrawn',
                'Information sharing with partner facilities is paused. Existing appointments are not cancelled automatically; contact the facility directly about any prior arrangements.'],
            BloodRequest::STATUS_PENDING => ['amber', 'Request submitted — awaiting review',
                "{$hospital} will review your request and physician document. No blood is reserved yet. Do not travel for blood release until you are notified."],
            BloodRequest::STATUS_VERIFIED => ['amber', 'Request verified — finding a match',
                'Your request details were reviewed. No blood is reserved yet. Wait for a facility match before making any plans.'],
            BloodRequest::STATUS_MATCHED => ['green', 'Facility matched — not ready for pickup',
                "{$hospital} has reserved {$units}. Final clinical verification and release authorization are still pending. Wait for a pickup-ready notification before traveling for blood release."],
            BloodRequest::STATUS_READY => ['green', 'Ready for pickup',
                "{$hospital} has authorized {$units} for pickup. Bring a valid ID and the original physician request, and follow the facility pickup instructions below."],
            BloodRequest::STATUS_IN_TRANSIT => ['green', 'In transit — not a pickup notice',
                "{$hospital} has released {$units} to its transport team. Contact the facility for delivery coordination; do not travel to collect the blood based on this status."],
            BloodRequest::STATUS_COMPLETED => ['green', 'Released / completed',
                'This request is complete. Release was recorded after an authorized handoff.'],
            BloodRequest::STATUS_CANCELLED => ['grey', 'Request cancelled',
                'This request was cancelled and no blood is reserved.'],
            default => ['grey', 'Request status', ''],
        };
    }

    /**
     * Six steps. The first one not done yet is the "current step".
     * Each event: title, state (done|current|pending|cancelled), when, text.
     */
    private function timeline(BloodRequest $r, string $hospital, string $units, string $requesterFirst): array
    {
        $stamp   = fn ($at) => $at ? $at->format('M j · g:i A') : null;
        $releaseAt = $r->ready_at ?? $r->in_transit_at;
        $steps = [
            ['title' => 'Request filed', 'at' => $r->created_at, 'done' => true,
             'text' => "{$requesterFirst} submitted the patient requirement and physician request.",
             'wait' => ''],
            ['title' => 'Request information verified', 'at' => $r->verified_at, 'done' => $r->verified_at !== null,
             'text' => 'Identity and request details reviewed; this is not a compatibility result.',
             'wait' => "{$hospital} will review the request details and physician document."],
            ['title' => 'Facility matched', 'at' => $r->matched_at, 'done' => $r->matched_at !== null,
             'text' => "{$hospital} reserved {$units}.",
             'wait' => 'The facility will look for available units to reserve.'],
            ['title' => 'Clinical verification & release authorization', 'at' => null, 'done' => $releaseAt !== null,
             'text' => 'The facility recorded completion of its clinical checks and release authorization. BloodLink does not perform or validate laboratory testing.',
             'wait' => "{$hospital} is coordinating patient sample testing and release authorization.",
             'titles' => ['done' => 'Clinical verification recorded', 'current' => 'Clinical verification & release authorization']],
            ['title' => 'Pickup ready', 'at' => $r->ready_at, 'done' => $r->ready_at !== null,
             'text' => 'Your blood is ready for authorized pickup.',
             'wait' => 'Pickup instructions will be sent after facility release authorization.'],
            ['title' => 'In transit', 'at' => $r->in_transit_at, 'done' => $r->in_transit_at !== null,
             'text' => 'The facility recorded release to its transport team.',
             'wait' => 'If delivery is arranged, transport status will be shared by the facility.'],
            ['title' => 'Released / completed', 'at' => $r->completed_at, 'done' => $r->completed_at !== null,
             'text' => $r->pickup_recipient_name
                ? 'Handoff recorded to ' . $r->pickup_recipient_name . ' by ' . ($r->pickup_completed_by ?: $hospital) . '.'
                : 'Delivery was recorded after an authorized handoff.',
             'wait' => 'Release is recorded after authorized handoff.'],
        ];
        $steps = array_values(array_filter($steps, fn (array $step) =>
            ! ($step['title'] === 'Pickup ready' && $r->in_transit_at)
            && ! ($step['title'] === 'In transit' && ! $r->in_transit_at)
        ));

        $events  = [];
        $current = false;

        foreach ($steps as $i => $step) {
            $state = 'done';

            if (! $step['done']) {
                if (in_array($r->status, [BloodRequest::STATUS_CANCELLED, BloodRequest::STATUS_PAUSED], true)) {
                    break;
                }
                $state   = $current ? 'pending' : 'current';
                $current = true;
            }

            $title = $step['title'];
            if (isset($step['titles']) && isset($step['titles'][$state])) {
                $title = $step['titles'][$state];
            }

            $isLast = $i === count($steps) - 1;

            $events[] = [
                'title' => $title,
                'state' => $state,
                'when'  => match ($state) {
                    'done'    => $stamp($step['at']) ?? 'Completed',
                    'current' => 'Current step',
                    default   => $isLast ? 'Not started' : 'Pending',
                },
                'text'  => $state === 'done' ? $step['text'] : $step['wait'],
            ];
        }

        if ($r->status === BloodRequest::STATUS_PAUSED) {
            $events[] = [
                'title' => 'Coordination paused',
                'state' => 'pending',
                'when' => $stamp($r->consent_revoked_at) ?? 'Consent withdrawn',
                'text' => 'No further in-app coordination will occur until information-sharing consent is restored.',
            ];
        } elseif ($r->status === BloodRequest::STATUS_CANCELLED) {
            $events[] = [
                'title' => 'Request cancelled',
                'state' => 'cancelled',
                'when'  => $stamp($r->cancelled_at) ?? '',
                'text'  => 'No blood is reserved for this request.',
            ];
        }

        return $events;
    }

    /** Verification & release checklist. [title, done, note] */
    private function checklist(BloodRequest $r, string $hospital): array
    {
        return [
            ['Patient identity and request details received', true, null],
            ['Physician request uploaded', $r->documents->isNotEmpty(), $r->documents->isEmpty() ? 'No document is attached to this request.' : null],
            ['Patient ABO/Rh independently confirmed', $r->reviewCheck('abo_confirmed'), $r->reviewCheck('abo_confirmed') ? null : "Pending facility confirmation."],
            ['Compatibility testing completed and result reviewed', $r->reviewCheck('compatibility_completed'), $r->reviewCheck('compatibility_completed') ? 'Recorded by facility staff; not tested by BloodLink.' : "Pending facility laboratory review."],
            ['Blood-bank release authorization', $r->reviewCheck('release_authorized'), $r->reviewCheck('release_authorized') ? 'Recorded by authorized facility staff.' : 'Not issued. Facility match alone does not authorize pickup.'],
            ['Pickup instructions provided', $r->ready_at !== null, $r->ready_at !== null ? $r->pickup_instructions : 'Wait for a pickup-ready notice before traveling.'],
            ['Receiver identity and authorized handoff', $r->completed_at !== null, $r->completed_at !== null ? 'Recipient: ' . ($r->pickup_recipient_name ?: 'recorded by facility staff') : 'Bring a valid ID and follow the facility’s release process.'],
        ];
    }
}
