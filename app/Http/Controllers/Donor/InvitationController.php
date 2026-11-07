<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Models\DonorDriveInvitation;
use App\Models\Prescreening;
use App\Notifications\BloodLinkNotification;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvitationController extends Controller
{
    public function index(Request $request)
    {
        $invitations = $request->user()->driveInvitations()
            ->with(['drive.hospital', 'appointment'])
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [DonorDriveInvitation::STATUS_INVITED])
            ->orderByDesc('sent_at')
            ->paginate(12);

        return view('donor.invitations', compact('invitations'));
    }

    public function respond(Request $request, DonorDriveInvitation $invitation)
    {
        abort_unless($invitation->donor_id === $request->user()->id, 404);
        abort_if($invitation->status !== DonorDriveInvitation::STATUS_INVITED, 422, 'This invitation has already been answered.');
        abort_if($invitation->drive->scheduled_at->isPast(), 422, 'This donor drive has already passed.');

        $data = $request->validate([
            'response' => ['required', Rule::in([DonorDriveInvitation::STATUS_INTERESTED, DonorDriveInvitation::STATUS_DECLINED])],
        ]);

        $invitation->update([
            'status' => $data['response'],
            'responded_at' => now(),
        ]);

        $hasSubmittedPrescreening = false;
        if ($data['response'] === DonorDriveInvitation::STATUS_INTERESTED) {
            $invitation->loadMissing('drive.hospital');
            $hasSubmittedPrescreening = $request->user()->prescreenings()
                ->where('status', Prescreening::STATUS_SUBMITTED)
                ->exists();
            $invitation->drive->hospital?->accounts()->each(fn ($account) => $account->notify(new BloodLinkNotification(
                'Donor interested in your drive',
                'A donor responded to ' . $invitation->drive->name . '. Review the response and any submitted pre-screening in Donor Coordination.',
                '/hospital/donors?drive=' . $invitation->drive->id . '&filter=interested',
                'invitation'
            )));
        }

        return redirect()->route('donor.invitations')
            ->with('status', $data['response'] === DonorDriveInvitation::STATUS_INTERESTED
                ? ($hasSubmittedPrescreening
                    ? 'Thanks for responding. The hospital has been notified and can review your submitted pre-screening.'
                    : 'Thanks for responding. The hospital has been notified. Complete your pre-screening so the team can review your request.')
                : 'Your response has been saved.');
    }
}
