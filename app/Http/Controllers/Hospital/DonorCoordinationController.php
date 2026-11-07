<?php

namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Appointment;
use App\Models\Donation;
use App\Models\DonorDrive;
use App\Models\DonorDriveInvitation;
use App\Models\Hospital;
use App\Models\Prescreening;
use App\Models\User;
use App\Notifications\BloodLinkNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DonorCoordinationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $hospital = $user->ensureFacility();
        $drives = $hospital->donorDrives()->orderByDesc('scheduled_at')->get();
        $drive = $drives->firstWhere('id', (int) $request->query('drive'))
            ?? $drives->first(fn ($item) => $item->scheduled_at->gte(now()))
            ?? $drives->first();

        $targetBloodType = $drive?->target_blood_type
            ?: $request->query('blood_type')
            ?: $this->suggestedBloodType($hospital);
        $targetBloodType = array_key_exists($targetBloodType, config('bloodlink.blood_types'))
            ? $targetBloodType
            : $this->suggestedBloodType($hospital);

        $invitations = $drive ? $drive->invitations()->get()->keyBy('donor_id') : collect();
        $allDonors = $this->matchingDonors($hospital, $drive, $targetBloodType);
        $eligibleDonors = $allDonors->map(function (User $donor) use ($invitations) {
            $donor->setAttribute('drive_invitation', $invitations->get($donor->id));
            return $donor;
        });

        $filter = $request->query('filter', 'all');
        if (in_array($filter, ['not_contacted', 'invited', 'interested'], true)) {
            $eligibleDonors = $eligibleDonors->filter(function (User $donor) use ($filter) {
                $invitation = $donor->getAttribute('drive_invitation');

                return match ($filter) {
                    'not_contacted' => $invitation === null,
                    'invited' => $invitation?->status === DonorDriveInvitation::STATUS_INVITED,
                    'interested' => $invitation?->status === DonorDriveInvitation::STATUS_INTERESTED,
                };
            })->values();
        }
        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $eligibleDonors = $eligibleDonors->filter(fn (User $donor) =>
                str_contains(strtolower($donor->coordinationReference()), strtolower($search))
                || str_contains(strtolower((string) $donor->home_city), strtolower($search))
            )->values();
        }
        $eligibleDonorCount = $eligibleDonors->count();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $eligibleDonors = new LengthAwarePaginator(
            $eligibleDonors->slice(($page - 1) * 12, 12)->values(),
            $eligibleDonorCount,
            12,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $inviteCounts = [
            'all' => $allDonors->count(),
            'not_contacted' => $allDonors->filter(fn (User $donor) => ! $invitations->has($donor->id))->count(),
            'invited' => $allDonors->filter(fn (User $donor) => $invitations->get($donor->id)?->status === DonorDriveInvitation::STATUS_INVITED)->count(),
            'interested' => $allDonors->filter(fn (User $donor) => $invitations->get($donor->id)?->status === DonorDriveInvitation::STATUS_INTERESTED)->count(),
            'sent' => $invitations->count(),
            'replies' => $invitations->whereIn('status', [
                DonorDriveInvitation::STATUS_INTERESTED,
                DonorDriveInvitation::STATUS_DECLINED,
            ])->count(),
        ];

        $reviewInvitations = $drive
            ? $drive->invitations()
                ->where('status', DonorDriveInvitation::STATUS_INTERESTED)
                ->with('donor')
                ->orderBy('responded_at')
                ->get()
            : collect();
        $submittedScreenings = Prescreening::query()
            ->whereIn('user_id', $reviewInvitations->pluck('donor_id'))
            ->where('status', Prescreening::STATUS_SUBMITTED)
            ->orderByDesc('submitted_at')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');
        $reviewInvitations->each(fn (DonorDriveInvitation $invitation) =>
            $invitation->setAttribute('submitted_prescreening', $submittedScreenings->get($invitation->donor_id))
        );
        $attendanceInvitations = $drive
            ? $drive->invitations()
                ->where('status', DonorDriveInvitation::STATUS_PRELIMINARY_ELIGIBLE)
                ->whereNotNull('appointment_id')
                ->with(['donor', 'appointment'])
                ->orderBy('responded_at')
                ->get()
            : collect();

        return view('hospital.donors', [
            'user' => $user,
            'hospital' => $hospital,
            'drive' => $drive,
            'targetBloodType' => $targetBloodType,
            'eligibleDonors' => $eligibleDonors,
            'eligibleDonorCount' => $eligibleDonorCount,
            'inviteCounts' => $inviteCounts,
            'filter' => $filter,
            'search' => $search,
            'bloodTypes' => config('bloodlink.blood_types'),
            'reviewInvitations' => $reviewInvitations,
            'attendanceInvitations' => $attendanceInvitations,
        ]);
    }

    public function saveDrive(Request $request, ?DonorDrive $drive = null)
    {
        $user = $request->user();
        $hospital = $user->ensureFacility();

        if ($drive) {
            abort_unless($drive->hospital_id === $hospital->id, 404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'target_blood_type' => ['required', Rule::in(array_keys(config('bloodlink.blood_types')))],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'team' => ['nullable', 'string', 'max:120'],
            'venue' => ['nullable', 'string', 'max:190'],
            'coordinator_name' => ['nullable', 'string', 'max:120'],
            'recruitment_target' => ['required', 'integer', 'min:1', 'max:1000'],
            'consent_reviewed' => ['sometimes', 'boolean'],
            'supplies_ready' => ['sometimes', 'boolean'],
            'screening_staff_ready' => ['sometimes', 'boolean'],
        ]);
        foreach (['consent_reviewed', 'supplies_ready', 'screening_staff_ready'] as $check) {
            $data[$check] = $request->boolean($check);
        }

        $drive ??= new DonorDrive(['hospital_id' => $hospital->id]);
        $oldDriveDetails = $drive->exists
            ? [$drive->name, $drive->scheduled_at?->toDateTimeString(), $drive->venue]
            : null;
        $drive->fill($data);
        $drive->save();

        $driveDetailsChanged = $oldDriveDetails !== null
            && $oldDriveDetails !== [$drive->name, $drive->scheduled_at->toDateTimeString(), $drive->venue];
        if ($driveDetailsChanged) {
            $drive->invitations()
                ->whereNotNull('appointment_id')
                ->with(['appointment', 'donor'])
                ->get()
                ->each(function (DonorDriveInvitation $invitation) use ($drive, $hospital) {
                    $appointment = $invitation->appointment;
                    if (! $appointment || $appointment->status !== Appointment::STATUS_SCHEDULED) {
                        return;
                    }

                    $location = $drive->venue ?: $hospital->locationLabel();
                    $appointment->update([
                        'scheduled_at' => $drive->scheduled_at,
                        'location_detail' => $location,
                        'notes' => 'Blood drive: ' . $drive->name . '. Appointment is not medical clearance; final eligibility is determined by screening staff at the drive.',
                    ]);
                    $invitation->donor?->notify(new BloodLinkNotification(
                        'Drive appointment updated',
                        'The hospital updated your appointment to ' . $drive->scheduled_at->format('M j, Y · g:i A') . ' at ' . $location . '. Final eligibility is determined by medical staff at the drive.',
                        '/donor/dashboard',
                        'appointment'
                    ));
                });
        }

        ActivityLog::create([
            'hospital_id' => $hospital->id,
            'activity' => $drive->wasRecentlyCreated ? 'Donor drive scheduled' : 'Donor drive updated',
            'details' => $drive->name . ' · ' . $drive->scheduled_at->format('M j, Y g:i A'),
            'operator' => $user->name,
            'status' => 'coordinating',
        ]);

        return redirect()->route('hospital.donors', ['drive' => $drive->id])
            ->with('status', $drive->wasRecentlyCreated ? 'Donor drive scheduled.' : 'Drive schedule and readiness updated.');
    }

    public function invite(Request $request, DonorDrive $drive)
    {
        $user = $request->user();
        $hospital = $user->ensureFacility();
        abort_unless($drive->hospital_id === $hospital->id, 404);
        abort_unless($hospital->is_partner, 403, 'Only verified partner facilities can send donor invitations.');
        abort_if($drive->scheduled_at->isPast(), 422, 'Invitations are closed for this donor drive.');
        if (! $drive->target_blood_type) {
            return back()->withErrors(['drive' => 'Save a target blood group before sending donor invitations.']);
        }

        $data = $request->validate([
            'donor_ids' => ['required', 'array', 'min:1', 'max:12'],
            'donor_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ]);

        $matched = $this->matchingDonors($hospital, $drive, $drive->target_blood_type)->keyBy('id');
        $selectedIds = collect($data['donor_ids'])->map(fn ($id) => (int) $id);
        if ($selectedIds->diff($matched->keys())->isNotEmpty()) {
            return back()->withErrors([
                'donor_ids' => 'One or more selected donors are no longer eligible matches. Refresh the list and try again.',
            ])->withInput();
        }

        $alreadyInvited = $drive->invitations()->whereIn('donor_id', $selectedIds)->pluck('donor_id');
        if ($alreadyInvited->isNotEmpty()) {
            return back()->withErrors([
                'donor_ids' => 'One or more selected donors have already received an invitation for this drive.',
            ])->withInput();
        }

        DB::transaction(function () use ($drive, $user, $hospital, $selectedIds) {
            $lockedDrive = DonorDrive::query()->lockForUpdate()->findOrFail($drive->id);
            abort_unless($lockedDrive->hospital_id === $hospital->id, 404);
            abort_unless($hospital->is_partner, 403, 'Only verified partner facilities can send donor invitations.');
            abort_if($lockedDrive->scheduled_at->isPast(), 422, 'Invitations are closed for this donor drive.');

            $donors = User::query()->whereIn('id', $selectedIds)->lockForUpdate()->get()->keyBy('id');
            $eligibleIds = $this->matchingDonors($hospital, $lockedDrive, $lockedDrive->target_blood_type)->pluck('id');
            if ($selectedIds->diff($eligibleIds)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'donor_ids' => 'One or more selected donors are no longer eligible matches. Refresh the list and try again.',
                ]);
            }

            if ($lockedDrive->invitations()->whereIn('donor_id', $selectedIds)->exists()) {
                throw ValidationException::withMessages([
                    'donor_ids' => 'One or more selected donors have already received an invitation for this drive.',
                ]);
            }

            foreach ($selectedIds as $donorId) {
                DonorDriveInvitation::create([
                    'donor_drive_id' => $lockedDrive->id,
                    'donor_id' => $donorId,
                    'invited_by_user_id' => $user->id,
                    'status' => DonorDriveInvitation::STATUS_INVITED,
                    'sent_at' => now(),
                ]);
                $donors->get($donorId)?->notify(new BloodLinkNotification(
                    'You are invited to a donor drive',
                    $lockedDrive->name . ' invited you to participate on ' . $lockedDrive->scheduled_at->format('M j, Y · g:i A') . '.',
                    '/donor/invitations',
                    'invitation'
                ));
            }

            ActivityLog::create([
                'hospital_id' => $hospital->id,
                'activity' => 'Donor drive invitations sent',
                'details' => $selectedIds->count() . ' invitation(s) · ' . $lockedDrive->name,
                'operator' => $user->name,
                'status' => 'coordinating',
            ]);
        });

        return redirect()->route('hospital.donors', ['drive' => $drive->id])
            ->with('status', $selectedIds->count() . ' in-app donor invitation(s) sent.');
    }

    public function reviewInvitation(Request $request, DonorDrive $drive, DonorDriveInvitation $invitation)
    {
        $user = $request->user();
        $hospital = $user->ensureFacility();
        abort_unless($drive->hospital_id === $hospital->id, 404);
        abort_unless($hospital->is_partner, 403, 'Only verified partner facilities can review donor invitations.');
        abort_unless($invitation->donor_drive_id === $drive->id, 404);

        $data = $request->validate([
            'decision' => ['required', Rule::in([
                DonorDriveInvitation::STATUS_PRELIMINARY_ELIGIBLE,
                DonorDriveInvitation::STATUS_NOT_ELIGIBLE,
            ])],
            'screening_reviewed' => ['accepted'],
        ], [
            'screening_reviewed.accepted' => 'Confirm that you reviewed the submitted pre-screening.',
        ]);

        [$reviewedInvitation, $appointment] = DB::transaction(function () use ($drive, $hospital, $user, $invitation, $data) {
            $lockedDrive = DonorDrive::query()->lockForUpdate()->findOrFail($drive->id);
            $lockedInvitation = DonorDriveInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            abort_unless($lockedDrive->hospital_id === $hospital->id, 404);
            abort_unless($lockedInvitation->donor_drive_id === $lockedDrive->id, 404);

            if ($lockedInvitation->status !== DonorDriveInvitation::STATUS_INTERESTED) {
                throw ValidationException::withMessages([
                    'decision' => 'This invitation is no longer waiting for review.',
                ]);
            }
            if ($lockedDrive->scheduled_at->isPast()) {
                throw ValidationException::withMessages([
                    'decision' => 'This donor drive has already passed.',
                ]);
            }

            $donor = User::query()->lockForUpdate()->findOrFail($lockedInvitation->donor_id);
            $screening = $donor->prescreenings()
                ->where('status', Prescreening::STATUS_SUBMITTED)
                ->orderByDesc('submitted_at')
                ->first();

            if (! $screening || ! $screening->isComplete()) {
                throw ValidationException::withMessages([
                    'decision' => 'The donor must submit a complete pre-screening before this invitation can be reviewed.',
                ]);
            }

            $appointment = null;
            if ($data['decision'] === DonorDriveInvitation::STATUS_PRELIMINARY_ELIGIBLE) {
                if (! $this->matchingDonors($hospital, $lockedDrive, $lockedDrive->target_blood_type)->contains('id', $donor->id)) {
                    throw ValidationException::withMessages([
                        'decision' => 'The donor no longer meets the drive matching requirements. Do not schedule this donor for the drive.',
                    ]);
                }

                if ($donor->appointments()
                    ->where('status', Appointment::STATUS_SCHEDULED)
                    ->where('scheduled_at', '>=', now())
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'decision' => 'The donor already has an upcoming appointment. Resolve that appointment before scheduling this drive.',
                    ]);
                }

                $appointment = $donor->appointments()->create([
                    'hospital_id' => $hospital->id,
                    'scheduled_at' => $lockedDrive->scheduled_at,
                    'status' => Appointment::STATUS_SCHEDULED,
                    'purpose' => Appointment::PURPOSE_DONATION,
                    'location_detail' => $lockedDrive->venue ?: $hospital->locationLabel(),
                    'notes' => 'Blood drive: ' . $lockedDrive->name . '. Appointment is not medical clearance; final eligibility is determined by screening staff at the drive.',
                ]);
            }

            $lockedInvitation->update([
                'status' => $data['decision'],
                'appointment_id' => $appointment?->id,
                'reviewed_by_user_id' => $user->id,
                'reviewed_at' => now(),
            ]);

            ActivityLog::create([
                'hospital_id' => $hospital->id,
                'activity' => $data['decision'] === DonorDriveInvitation::STATUS_PRELIMINARY_ELIGIBLE
                    ? 'Donor pre-screen reviewed and appointment scheduled'
                    : 'Donor marked not eligible for drive',
                'details' => $donor->coordinationReference() . ' · ' . $lockedDrive->name,
                'operator' => $user->name,
                'status' => 'coordinating',
            ]);

            return [$lockedInvitation->fresh(['donor', 'drive']), $appointment];
        });

        $donor = $reviewedInvitation->donor;
        if ($appointment) {
            $donor->notify(new BloodLinkNotification(
                'Drive appointment scheduled',
                'The hospital reviewed your pre-screening and scheduled you for ' . $appointment->scheduled_at->format('M j, Y · g:i A') . ' at ' . ($reviewedInvitation->drive->venue ?: $hospital->locationLabel()) . '. This is preliminary only; medical staff must confirm your eligibility at the drive.',
                '/donor/dashboard',
                'appointment'
            ));

            return redirect()->route('hospital.donors', ['drive' => $drive->id, 'filter' => 'interested'])
                ->with('status', 'Pre-screen reviewed and drive appointment scheduled. Final donation eligibility must be confirmed by medical staff at the drive.');
        }

        $donor->notify(new BloodLinkNotification(
            'Donor drive review update',
            'The hospital reviewed your pre-screening and marked you not eligible for this donor drive. This decision applies to this drive only; contact the facility if you need more information.',
            '/donor/invitations',
            'invitation'
        ));

        return redirect()->route('hospital.donors', ['drive' => $drive->id, 'filter' => 'interested'])
            ->with('status', 'The donor has been marked not eligible for this drive and has been notified.');
    }

    public function confirmAttendance(Request $request, DonorDrive $drive, DonorDriveInvitation $invitation)
    {
        $user = $request->user();
        $hospital = $user->ensureFacility();
        abort_unless($drive->hospital_id === $hospital->id, 404);
        abort_unless($hospital->is_partner, 403, 'Only verified partner facilities can confirm donor attendance.');
        abort_unless($invitation->donor_drive_id === $drive->id, 404);

        $data = $request->validate([
            'attendance_status' => ['required', Rule::in([
                Appointment::ATTENDANCE_ATTENDED,
                Appointment::ATTENDANCE_NO_SHOW,
            ])],
        ]);

        $appointment = DB::transaction(function () use ($drive, $invitation, $hospital, $user, $data) {
            $lockedDrive = DonorDrive::query()->lockForUpdate()->findOrFail($drive->id);
            $lockedInvitation = DonorDriveInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            abort_unless($lockedDrive->hospital_id === $hospital->id, 404);
            abort_unless($lockedInvitation->donor_drive_id === $lockedDrive->id, 404);

            if ($lockedInvitation->status !== DonorDriveInvitation::STATUS_PRELIMINARY_ELIGIBLE
                || ! $lockedInvitation->appointment_id) {
                throw ValidationException::withMessages([
                    'attendance_status' => 'Only donors with a hospital-approved appointment can have attendance recorded.',
                ]);
            }

            $appointment = Appointment::query()->lockForUpdate()->findOrFail($lockedInvitation->appointment_id);
            if ($appointment->hospital_id !== $hospital->id || $appointment->user_id !== $lockedInvitation->donor_id) {
                throw ValidationException::withMessages([
                    'attendance_status' => 'The appointment does not belong to this hospital and donor.',
                ]);
            }
            if ($appointment->status === Appointment::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'attendance_status' => 'Attendance cannot be recorded for a cancelled appointment.',
                ]);
            }
            if ($appointment->scheduled_at->isFuture()) {
                throw ValidationException::withMessages([
                    'attendance_status' => 'Attendance can only be recorded after the scheduled appointment time.',
                ]);
            }

            $oldAttendance = $appointment->attendance_status;
            $appointment->update([
                'attendance_status' => $data['attendance_status'],
                'attendance_confirmed_by' => $user->id,
                'attendance_confirmed_at' => now(),
            ]);

            if ($data['attendance_status'] === Appointment::ATTENDANCE_ATTENDED) {
                Donation::query()->firstOrCreate(
                    ['appointment_id' => $appointment->id],
                    [
                        'user_id' => $appointment->user_id,
                        'hospital_id' => $hospital->id,
                        'donated_at' => $appointment->scheduled_at->toDateString(),
                        'type' => 'whole_blood',
                        'status' => Donation::STATUS_COMPLETED,
                        'recorded_by' => $hospital->name,
                    ]
                );
            } else {
                Donation::query()->where('appointment_id', $appointment->id)->delete();
            }

            ActivityLog::create([
                'hospital_id' => $hospital->id,
                'activity' => 'Donor appointment attendance recorded',
                'details' => $appointment->user->coordinationReference() . ' · ' . $lockedDrive->name . ' · '
                    . ($data['attendance_status'] === Appointment::ATTENDANCE_ATTENDED ? 'Attended' : 'Did not attend')
                    . ($oldAttendance && $oldAttendance !== $data['attendance_status'] ? ' · corrected previous status' : ''),
                'operator' => $user->name,
                'status' => 'coordinating',
            ]);

            return $appointment->fresh('user');
        });

        $attended = $appointment->attendance_status === Appointment::ATTENDANCE_ATTENDED;
        $appointment->user->notify(new BloodLinkNotification(
            $attended ? 'Hospital confirmed your attendance' : 'Hospital updated your attendance record',
            $attended
                ? 'The hospital confirmed your attendance and recorded your completed donation for ' . $appointment->scheduled_at->format('M j, Y') . '. It is now included in your donation history.'
                : 'The hospital recorded that you did not attend your appointment on ' . $appointment->scheduled_at->format('M j, Y · g:i A') . '. Contact the hospital if this is incorrect.',
            '/donor/appointment',
            'appointment'
        ));

        return redirect()->route('hospital.donors', ['drive' => $drive->id])
            ->with('status', $attended
                ? 'Donor attendance confirmed, donation recorded, and the donor has been notified.'
                : 'Donor marked as not attended and the donor has been notified.');
    }

    private function matchingDonors(Hospital $hospital, ?DonorDrive $drive, string $bloodType)
    {
        if (! $drive || $drive->scheduled_at->isPast()) {
            return collect();
        }

        $interval = (int) config('bloodlink.donation_interval_days');
        $donors = User::query()
            ->where('role', User::ROLE_DONOR)
            ->where('visible_to_coordinators', true)
            ->where('region', $hospital->region)
            ->where('blood_type', $bloodType)
            ->whereDoesntHave('donations', fn (Builder $query) => $query
                ->completed()
                ->where('donated_at', '>', today()->subDays($interval)))
            ->whereDoesntHave('appointments', fn (Builder $query) => $query
                ->where('status', 'scheduled')
                ->where('scheduled_at', '>=', now()))
            ->orderBy('home_city')
            ->orderBy('id')
            ->get();

        return $donors->filter(function (User $donor) use ($drive) {
            return match ($donor->preferred_availability) {
                'weekdays' => ! $drive->scheduled_at->isWeekend(),
                'weekends' => $drive->scheduled_at->isWeekend(),
                'evenings' => $drive->scheduled_at->hour >= 17 && $drive->scheduled_at->hour < 22,
                default => true,
            };
        })->values();
    }

    private function suggestedBloodType(Hospital $hospital): string
    {
        $stocks = $hospital->stocks()->where('component', 'whole_blood')->get()->keyBy('blood_type');
        foreach ($stocks as $type => $stock) {
            if ($stock->status() === 'critical') {
                return $type;
            }
        }
        foreach ($stocks as $type => $stock) {
            if ($stock->status() === 'low') {
                return $type;
            }
        }

        return array_key_first(config('bloodlink.blood_types'));
    }
}
