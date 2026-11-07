<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\BloodLinkNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function create()
    {
        return view('auth.register', [
            'bloodTypes' => config('bloodlink.blood_types'),
            'regions'    => config('bloodlink.regions'),
        ]);
    }

    public function store(Request $request)
    {
        // Clean up the input first so "0917-123-4567" and "JUAN@Gmail.com" work.
        $request->merge([
            'email'  => Str::lower(trim((string) $request->input('email'))),
            'mobile' => User::normalizeMobile((string) $request->input('mobile')),
        ]);

        $isHospital = $request->input('role') === User::ROLE_HOSPITAL;

        $data = $request->validate([
            'role'          => ['required', Rule::in(User::ROLES)],
            'name'          => ['required', 'string', 'max:120'],
            'email'         => ['required', 'email', 'max:190', 'unique:users,email'],
            'mobile'        => ['required', 'regex:/^09\d{9}$/', 'unique:users,mobile'],
            'password'      => ['required', 'confirmed', Password::min(8)],
            'region'        => ['required', Rule::in(array_keys(config('bloodlink.regions')))],

            // Donors and receivers
            'blood_type'    => [Rule::requiredIf(! $isHospital), 'nullable', Rule::in(array_keys(config('bloodlink.blood_types')))],
            'date_of_birth' => [Rule::requiredIf(! $isHospital), 'nullable', 'date', 'before:today'],
            'sex'           => [Rule::requiredIf(! $isHospital), 'nullable', Rule::in(['female', 'male'])],

            // Hospitals and labs
            'organization'  => [Rule::requiredIf($isHospital), 'nullable', 'string', 'max:150'],

            'terms'         => ['accepted'],
        ], [
            'role.required'         => 'Please choose an account type.',
            'name.required'         => 'Enter your full name.',
            'email.unique'          => 'That email is already registered. Try logging in instead.',
            'mobile.required'       => 'Enter your mobile number.',
            'mobile.regex'          => 'Enter a valid PH mobile number, e.g. 09171234567.',
            'mobile.unique'         => 'That mobile number is already registered.',
            'password.confirmed'    => 'The passwords do not match.',
            'region.required'       => 'Select your region.',
            'blood_type.required'   => 'Select your blood type.',
            'date_of_birth.required'=> 'Enter your date of birth.',
            'date_of_birth.before'  => 'Date of birth must be in the past.',
            'sex.required'          => 'Select your sex.',
            'organization.required' => 'Enter your facility name.',
            'terms.accepted'        => 'You must agree to the Terms of Use and Privacy Policy.',
        ]);

        $user = User::create([
            'role'          => $data['role'],
            'name'          => $data['name'],
            'email'         => $data['email'],
            'mobile'        => $data['mobile'],
            'password'      => $data['password'],            // hashed automatically by the User model
            'region'        => $data['region'],
            'blood_type'    => $isHospital ? null : $data['blood_type'],
            'date_of_birth' => $isHospital ? null : $data['date_of_birth'],
            'sex'           => $isHospital ? null : $data['sex'],
            'organization'  => $isHospital ? $data['organization'] : null,
        ]);

        // Hospital accounts get their own (unverified) facility record.
        $facility = $isHospital ? $user->ensureFacility() : null;
        if ($facility) {
            User::query()->where('role', User::ROLE_SUPERADMIN)->each(fn (User $admin) => $admin->notify(new BloodLinkNotification(
                'New facility application',
                $facility->name . ' registered and is waiting for partner review.',
                '/admin/partners',
                'approval'
            )));
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
