<?php

namespace App\Http\Controllers\Receiver;

use App\Http\Controllers\Controller;
use App\Models\BloodRequest;
use App\Models\BloodStock;
use App\Models\Hospital;
use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SavedFacilityController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'blood_type' => ['nullable', 'in:all,' . implode(',', array_keys(config('bloodlink.blood_types')))],
            'location' => ['nullable', 'in:' . implode(',', array_keys(config('bloodlink.localities')))],
        ]);
        $user = $request->user();
        $bloodType = $validated['blood_type'] ?? 'all';
        $localities = config('bloodlink.localities');
        $defaultLocality = collect($localities)->filter(function (array $locality) use ($user) {
            return $locality['region'] === $user->region
                && $user->home_city
                && Str::contains(mb_strtolower($locality['name']), mb_strtolower($user->home_city));
        })->keys()->first()
            ?? collect($localities)->filter(fn ($locality) => $locality['region'] === $user->region)->keys()->first()
            ?? array_key_first($localities);
        $locationKey = $validated['location'] ?? $defaultLocality;
        $location = $localities[$locationKey];
        $search = trim($validated['q'] ?? '');
        $activeRequest = $user->bloodRequests()->active()->orderByDesc('created_at')->first();

        $facilities = $user->savedFacilities()
            ->where('is_partner', true)
            ->with(['stocks' => function ($query) use ($bloodType) {
                $query->where('component', BloodStock::COMPONENT_WHOLE_BLOOD);
                if ($bloodType !== 'all') {
                    $query->where('blood_type', $bloodType);
                }
            }])
            ->orderBy('name')
            ->get()
            ->map(function (Hospital $hospital) use ($location, $activeRequest, $bloodType) {
                $distance = $hospital->latitude !== null && $hospital->longitude !== null
                    ? Geo::distanceKm((float) $location['lat'], (float) $location['lng'], (float) $hospital->latitude, (float) $hospital->longitude)
                    : null;
                $stock = $bloodType !== 'all'
                    ? $hospital->stocks->first()
                    : ($activeRequest?->hospital_id === $hospital->id
                        ? $hospital->stocks->firstWhere('blood_type', $activeRequest->blood_type)
                        : $hospital->stocks->sortByDesc(fn (BloodStock $row) => $row->updated_at ?? $row->created_at)->first());
                $stockUpdatedAt = $stock?->updated_at ?? $stock?->created_at;
                $freshness = ! $stock
                    ? 'none'
                    : ($stockUpdatedAt && $stockUpdatedAt->gte(now()->subMinutes((int) config('bloodlink.stock_stale_minutes'))) ? 'fresh' : 'stale');

                $requestForFacility = $activeRequest?->hospital_id === $hospital->id ? $activeRequest : null;

                return [
                    'hospital' => $hospital,
                    'distance' => $distance,
                    'stock' => $stock,
                    'stock_updated_at' => $stockUpdatedAt,
                    'freshness' => $freshness,
                    'active_request' => $requestForFacility,
                ];
            });

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $facilities = $facilities->filter(function (array $facility) use ($needle) {
                return str_contains(mb_strtolower($facility['hospital']->name), $needle)
                    || str_contains(mb_strtolower($facility['hospital']->city), $needle)
                    || str_contains(mb_strtolower((string) $facility['hospital']->address), $needle);
            })->values();
        }

        $facilities = $facilities->sortBy(fn (array $facility) => [
            $facility['active_request'] && in_array($facility['active_request']->status, ['matched', 'ready', 'in_transit'], true) ? 0 : 1,
            $facility['freshness'] === 'fresh' && $facility['stock']?->units > 0 ? 0 : 1,
            $facility['distance'] ?? PHP_INT_MAX,
            mb_strtolower($facility['hospital']->name),
        ])->values();

        return view('receiver.saved-facilities', [
            'user' => $user,
            'facilities' => $facilities,
            'count' => $facilities->count(),
            'search' => $search,
            'bloodType' => $bloodType,
            'locationKey' => $locationKey,
            'localities' => $localities,
            'location' => $location,
            'activeRequest' => $activeRequest,
        ]);
    }

    /** Save the facility if it is not saved yet, otherwise remove it. */
    public function toggle(Request $request, Hospital $hospital)
    {
        abort_unless($hospital->is_partner, 404);

        $attached = $request->user()->savedFacilities()->whereKey($hospital->id)->exists();
        if ($attached) {
            $request->user()->savedFacilities()->detach($hospital->id);
            return back()->with('status', $hospital->name . ' was removed from your saved facilities.');
        }

        $request->user()->savedFacilities()->syncWithoutDetaching([$hospital->id]);
        return back()->with('status', $hospital->name . ' was added to your saved facilities.');
    }
}
