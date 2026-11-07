<?php

namespace App\Http\Controllers\Receiver;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BloodRequest;
use App\Notifications\BloodLinkNotification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'view' => ['nullable', 'in:upcoming,past,cancelled'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $view = $validated['view'] ?? 'upcoming';
        $search = trim($validated['q'] ?? '');

        $all = $user->appointments()->with('hospital')->orderByDesc('scheduled_at')->get();
        $upcoming = $all->filter(fn (Appointment $appointment) => $appointment->status === Appointment::STATUS_SCHEDULED && $appointment->scheduled_at->isFuture())->sortBy('scheduled_at')->values();
        $past = $all->filter(fn (Appointment $appointment) => $appointment->status === Appointment::STATUS_COMPLETED || ($appointment->status === Appointment::STATUS_SCHEDULED && $appointment->scheduled_at->isPast()))->values();
        $cancelled = $all->where('status', Appointment::STATUS_CANCELLED)->values();
        $counts = [
            'upcoming' => $upcoming->count(),
            'past' => $past->count(),
            'cancelled' => $cancelled->count(),
        ];

        $appointments = match ($view) {
            'past' => $past,
            'cancelled' => $cancelled,
            default => $upcoming,
        };

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $appointments = $appointments->filter(fn (Appointment $appointment) =>
                str_contains(mb_strtolower((string) $appointment->purpose), $needle)
                || str_contains(mb_strtolower((string) $appointment->location_detail), $needle)
                || str_contains(mb_strtolower((string) $appointment->hospital?->name), $needle)
            )->values();
        }

        $appointment = $view === 'upcoming' ? $appointments->first() : null;
        $appointmentRequest = $appointment && $appointment->hospital_id
            ? $user->bloodRequests()
                ->where('hospital_id', $appointment->hospital_id)
                ->whereIn('status', [
                    BloodRequest::STATUS_MATCHED,
                    BloodRequest::STATUS_READY,
                    BloodRequest::STATUS_IN_TRANSIT,
                    BloodRequest::STATUS_COMPLETED,
                ])
                ->orderByDesc('created_at')
                ->first()
            : null;
        return view('receiver.appointments', [
            'user' => $user,
            'view' => $view,
            'search' => $search,
            'counts' => $counts,
            'appointments' => $appointments,
            'appointment' => $appointment,
            'appointmentRequest' => $appointmentRequest,
            'past' => $past,
        ]);
    }

    public function confirm(Request $request, Appointment $appointment)
    {
        // A receiver may only confirm their own appointment.
        abort_unless($appointment->user_id === $request->user()->id, 403);

        if ($appointment->status !== Appointment::STATUS_SCHEDULED || $appointment->confirmed_at) {
            return redirect()->back();
        }

        if ($appointment->scheduled_at->isPast()) {
            return redirect()->back()
                ->with('status', 'This appointment time has passed. Please contact the facility.');
        }

        if ($appointment->reserved_until && $appointment->reserved_until->isPast()) {
            return redirect()->back()
                ->with('status', 'This slot reservation has expired. Please reschedule.');
        }

        $appointment->update(['confirmed_at' => now()]);
        $this->notifyFacility($appointment, 'Appointment confirmed', 'A receiver confirmed an upcoming appointment for ' . $appointment->scheduled_at->format('M j, Y · g:i A') . '.');

        return redirect()->back()->with('status', 'Your appointment is confirmed.');
    }

    public function cancel(Request $request, Appointment $appointment)
    {
        abort_unless($appointment->user_id === $request->user()->id, 403);

        if ($appointment->status !== Appointment::STATUS_SCHEDULED || $appointment->scheduled_at->isPast()) {
            return redirect()->route('receiver.appointments')->with('status', 'This appointment is no longer scheduled.');
        }

        $appointment->update(['status' => Appointment::STATUS_CANCELLED]);
        $this->notifyFacility($appointment, 'Appointment cancelled', 'A receiver cancelled an appointment scheduled for ' . $appointment->scheduled_at->format('M j, Y · g:i A') . '.');

        return redirect()->route('receiver.appointments', ['view' => 'cancelled'])
            ->with('status', 'Your appointment was cancelled. This does not cancel the related blood request.');
    }

    public function requestReschedule(Request $request, Appointment $appointment)
    {
        abort_unless($appointment->user_id === $request->user()->id, 403);
        abort_unless(
            $appointment->status === Appointment::STATUS_SCHEDULED && $appointment->scheduled_at->isFuture(),
            404
        );

        $data = $request->validate([
            'reschedule_requested_for' => ['required', 'date', 'after:now'],
            'reschedule_note' => ['nullable', 'string', 'max:500'],
        ]);

        $appointment->update([
            'reschedule_requested_at' => now(),
            'reschedule_requested_for' => $data['reschedule_requested_for'],
            'reschedule_note' => $data['reschedule_note'] ?? null,
        ]);
        $this->notifyFacility($appointment, 'Appointment reschedule requested', 'A receiver requested a change to an appointment scheduled for ' . $appointment->scheduled_at->format('M j, Y · g:i A') . '.');

        return redirect()->route('receiver.appointments')
            ->with('status', 'Your reschedule request was sent. Your current appointment remains active until the facility confirms a change.');
    }

    public function checklist(Request $request, Appointment $appointment): StreamedResponse
    {
        abort_unless($appointment->user_id === $request->user()->id, 403);

        $appointment->load('hospital');
        $relatedRequest = $request->user()->bloodRequests()
            ->where('hospital_id', $appointment->hospital_id)
            ->whereNotIn('status', [BloodRequest::STATUS_DRAFT, BloodRequest::STATUS_CANCELLED])
            ->orderByDesc('created_at')
            ->first();

        $lines = [
            'BLOODLINK APPOINTMENT CHECKLIST',
            '',
            'Appointment: ' . ($appointment->purpose ?: 'Blood release consultation'),
            'Date: ' . $appointment->scheduled_at->format('F j, Y'),
            'Time: ' . $appointment->scheduled_at->format('g:i A') . ($appointment->ends_at ? ' - ' . $appointment->ends_at->format('g:i A') : ''),
            'Facility: ' . ($appointment->hospital?->name ?? $appointment->location_detail ?? 'Partner facility'),
            'Location: ' . ($appointment->location_detail ?? $appointment->hospital?->locationLabel() ?? 'Confirm with the facility'),
            'Request reference: ' . ($relatedRequest?->reference ?? 'Bring the request reference shown in your BloodLink account'),
            '',
            'BRING WITH YOU',
            '[ ] Valid government-issued ID',
            '[ ] Current signed physician request showing component and quantity',
            '[ ] Blood request reference',
            '[ ] Patient authorization or representative documents, if applicable',
            '',
            'A consultation does not authorize blood release. Follow the facility instructions and wait for confirmation that release is approved.',
        ];

        return response()->streamDownload(
            fn () => print(implode(PHP_EOL, $lines)),
            'bloodlink-appointment-checklist-' . $appointment->scheduled_at->format('Ymd') . '.txt',
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }

    private function notifyFacility(Appointment $appointment, string $title, string $message): void
    {
        $appointment->loadMissing('hospital');
        $appointment->hospital?->accounts()->each(fn ($account) => $account->notify(new BloodLinkNotification(
            $title,
            $message,
            '/hospital/donors',
            'appointment'
        )));
    }
}
