<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        return view('donor.profile', [
            'user' => $request->user(),
            'donationStatus' => $request->user()->donationStatus(),
            'donationCount' => $request->user()->completedDonationsCount(),
            'regions' => config('bloodlink.regions'),
        ]);
    }

    public function update(Request $request)
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
            'region' => ['required', Rule::in(array_keys(config('bloodlink.regions')))],
            'home_city' => ['nullable', 'string', 'max:120'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:80'],
            'emergency_contact_mobile' => ['nullable', 'regex:/^09\d{9}$/'],
            'notification_appointment_reminders' => ['required', 'boolean'],
            'notification_request_updates' => ['required', 'boolean'],
            'notification_health_tips' => ['required', 'boolean'],
            'visible_to_coordinators' => ['required', 'boolean'],
            'preferred_availability' => ['nullable', Rule::in(['anytime', 'weekdays', 'weekends', 'evenings'])],
            'travel_radius_km' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ], [
            'mobile.regex' => 'Enter a valid PH mobile number, e.g. 09171234567.',
            'emergency_contact_mobile.regex' => 'Enter a valid PH mobile number for your emergency contact.',
        ]);

        $user->fill($data)->save();

        return redirect()->route('donor.profile')->with('status', 'Your profile settings have been saved.');
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'current_password.current_password' => 'Your current password is incorrect.',
            'password.confirmed' => 'The new passwords do not match.',
        ]);

        $request->user()->forceFill(['password' => $data['password']])->save();
        $request->session()->regenerate();

        return redirect()->route('donor.profile')->with('status', 'Your password has been changed.');
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $donations = $user->donations()
            ->completed()
            ->with('hospital')
            ->orderByDesc('donated_at')
            ->get()
            ->map(fn ($donation) => [
                'receipt_reference' => $donation->receiptReference(),
                'date' => $donation->donated_at?->toDateString(),
                'center' => $donation->centerLabel(),
                'type' => $donation->typeLabel(),
                'status' => $donation->status,
            ]);

        $account = [
            'exported_at' => now()->toIso8601String(),
            'personal_information' => [
                'name' => $user->name,
                'email' => $user->email,
                'mobile' => $user->mobile,
                'region' => $user->region,
                'home_city' => $user->home_city,
                'blood_type' => $user->blood_type,
                'date_of_birth' => $user->date_of_birth?->toDateString(),
                'sex' => $user->sex,
            ],
            'emergency_contact' => [
                'name' => $user->emergency_contact_name,
                'relationship' => $user->emergency_contact_relationship,
                'mobile' => $user->emergency_contact_mobile,
            ],
            'donation_history' => $donations,
        ];

        return response()->streamDownload(
            fn () => print(json_encode($account, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'bloodlink-account-' . now()->format('Ymd') . '.json',
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }
}
