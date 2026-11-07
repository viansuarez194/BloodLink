<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** Sends each account type to its own dashboard. */
    public function __invoke(Request $request)
    {
        $user = $request->user();

        if ($user->role === User::ROLE_DONOR) {
            return redirect()->route('donor.dashboard');
        }

        if ($user->role === User::ROLE_RECEIVER) {
            return redirect()->route('receiver.dashboard');
        }

        if ($user->role === User::ROLE_HOSPITAL) {
            return redirect()->route('hospital.dashboard');
        }

        if ($user->role === User::ROLE_SUPERADMIN) {
            return redirect()->route('admin.dashboard');
        }

        return view('dashboard', [
            'user'    => $user,
            'regions' => config('bloodlink.regions'),
        ]);
    }
}
