<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Notifications\BloodLinkNotification;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $appointment = $user->appointments()
            ->with('hospital')
            ->where('status', Appointment::STATUS_SCHEDULED)
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->first();

        if (! $appointment) {
            $appointment = $user->appointments()
                ->with('hospital')
                ->orderByDesc('scheduled_at')
                ->first();
        }

        $driveInvitation = $appointment
            ? $user->driveInvitations()
                ->with('drive')
                ->where('appointment_id', $appointment->id)
                ->first()
            : null;

        return view('donor.appointment', [
            'appointment' => $appointment,
            'driveInvitation' => $driveInvitation,
        ]);
    }

    public function cancel(Request $request, Appointment $appointment)
    {
        // A donor may only cancel their own appointment.
        abort_unless($appointment->user_id === $request->user()->id, 403);

        if ($appointment->status === Appointment::STATUS_SCHEDULED) {
            $appointment->update(['status' => Appointment::STATUS_CANCELLED]);
            $appointment->loadMissing('hospital');
            $appointment->hospital?->accounts()->each(fn ($account) => $account->notify(new BloodLinkNotification(
                'Donor appointment cancelled',
                'A donor cancelled an appointment scheduled for ' . $appointment->scheduled_at->format('M j, Y · g:i A') . '.',
                '/hospital/donors',
                'appointment'
            )));
        }

        return redirect()->route('donor.appointment')->with('status', 'Your appointment was cancelled.');
    }
}
