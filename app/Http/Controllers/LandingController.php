<?php

namespace App\Http\Controllers;

use App\Models\Hospital;

class LandingController extends Controller
{
    public function index()
    {
        $partners = Hospital::query()
            ->where('is_partner', true)
            ->orderBy('id')
            ->take(6)
            ->get();

        return view('landing', [
            'partners'       => $partners,
            'bloodTypes'     => config('bloodlink.blood_types'),
            'regions'        => config('bloodlink.regions'),
            'selectedType'   => config('bloodlink.default_blood_type'),
            'selectedRegion' => 'NCR',
        ]);
    }
}
