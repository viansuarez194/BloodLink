<?php

namespace App\Http\Controllers;

use App\Models\Hospital;
use Illuminate\Http\Request;

class FindBloodController extends Controller
{
    public function index(Request $request)
    {
        $bloodTypes = config('bloodlink.blood_types');
        $regions    = config('bloodlink.regions');

        // Only accept values we know about (never trust raw query input).
        $selectedType   = array_key_exists((string) $request->query('blood_type'), $bloodTypes)
            ? $request->query('blood_type') : null;
        $selectedRegion = array_key_exists((string) $request->query('region'), $regions)
            ? $request->query('region') : null;

        $results = null;

        if ($selectedType) {
            $results = Hospital::query()
                ->where('is_partner', true)
                ->when($selectedRegion, fn ($q) => $q->where('region', $selectedRegion))
                ->whereHas('stocks', fn ($q) => $q->where('component', 'whole_blood')->where('blood_type', $selectedType)->where('units', '>', 0))
                ->with(['stocks' => fn ($q) => $q->where('component', 'whole_blood')->where('blood_type', $selectedType)])
                ->get()
                ->sortByDesc(fn ($hospital) => $hospital->stocks->first()->units)
                ->values();
        }

        // Text search from the dashboard search bar: ?q=pgh
        $q       = trim((string) $request->query('q', ''));
        $matches = null;

        if (! $selectedType && $q !== '') {
            $term = '%' . addcslashes(mb_strtolower($q), '%_\\') . '%';

            $matches = Hospital::query()
                ->where('is_partner', true)
                ->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$term])
                                     ->orWhereRaw('LOWER(city) LIKE ?', [$term]))
                ->orderBy('name')
                ->limit(24)
                ->get();
        }

        return view('find-blood', [
            'results'        => $results,
            'matches'        => $matches,
            'q'              => $q,
            'bloodTypes'     => $bloodTypes,
            'regions'        => $regions,
            'selectedType'   => $selectedType ?? config('bloodlink.default_blood_type'),
            'selectedRegion' => $selectedRegion ?? 'NCR',
            'searched'       => $selectedType !== null,
        ]);
    }
}
