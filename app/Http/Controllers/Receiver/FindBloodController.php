<?php

namespace App\Http\Controllers\Receiver;

use App\Http\Controllers\Controller;
use App\Models\BloodRequest;
use App\Models\BloodStock;
use App\Models\Hospital;
use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FindBloodController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        $bloodTypes = config('bloodlink.blood_types');
        $components = BloodStock::COMPONENTS;
        $localities = config('bloodlink.localities');
        $radii      = config('bloodlink.search_radii');
        $staleAfter = (int) config('bloodlink.stock_stale_minutes');

        $activeRequest = $user->bloodRequests()->active()->with('hospital')
            ->orderByDesc('created_at')->first();

        // ----- filters (only accept values we know about) -----
        $bloodType = $this->pick($request->query('blood_type'), array_keys($bloodTypes))
            ?? $activeRequest?->blood_type
            ?? $user->blood_type;

        $component = $this->pick($request->query('component'), array_keys($components))
            ?? ($activeRequest && array_key_exists($activeRequest->component, $components) ? $activeRequest->component : BloodStock::COMPONENT_WHOLE_BLOOD);

        $defaultLocality = collect($localities)->filter(fn ($l) => $l['region'] === $user->region)->keys()->first()
            ?? array_key_first($localities);
        $localityKey = $this->pick($request->query('location'), array_keys($localities)) ?? $defaultLocality;
        $locality    = $localities[$localityKey];

        $radius = (int) $request->query('radius');
        $radius = in_array($radius, $radii, true) ? $radius : (int) config('bloodlink.default_radius');

        // ----- facilities + their stock for the chosen blood group and component -----
        $hospitals = Hospital::query()->where('is_partner', true)->get();

        $stocks = BloodStock::query()
            ->where('component', $component)
            ->where('blood_type', $bloodType)
            ->whereIn('hospital_id', $hospitals->pluck('id'))
            ->get()
            ->keyBy('hospital_id');

        $componentWord = str_replace('rbc', 'RBC', Str::lower($components[$component]));

        $results = $hospitals->map(function (Hospital $hospital) use ($locality, $stocks, $activeRequest, $staleAfter, $bloodType, $componentWord) {
            $distance = ($hospital->latitude !== null && $hospital->longitude !== null)
                ? Geo::distanceKm((float) $locality['lat'], (float) $locality['lng'], (float) $hospital->latitude, (float) $hospital->longitude)
                : null;

            return $this->describe($hospital, $stocks->get($hospital->id), $activeRequest, $distance, $staleAfter, $bloodType, $componentWord);
        })
        ->filter(function (array $row) use ($radius, $locality) {
            // With coordinates: inside the radius. Without: same region as the chosen place.
            return $row['distance'] !== null
                ? $row['distance'] <= $radius
                : $row['hospital']->region === $locality['region'];
        })
        ->sort(fn ($a, $b) => [$a['state'] === 'matched' ? 0 : 1, $a['distance'] ?? PHP_INT_MAX, $a['hospital']->name]
                          <=> [$b['state'] === 'matched' ? 0 : 1, $b['distance'] ?? PHP_INT_MAX, $b['hospital']->name])
        ->values();

        // ----- "Locality context": nearest facilities grouped by town/city -----
        $context = $results->groupBy(fn ($row) => trim(Str::afterLast($row['hospital']->city, ',')))
            ->map(fn ($rows) => $rows->map(fn ($row) => $row['hospital']->name
                . ($row['distance'] !== null ? ' · ' . number_format($row['distance'], 1) . ' km' : ''))->implode(' / '));

        $placeShort = trim(Str::before($locality['name'], ','));

        return view('receiver.find-blood', [
            'user'           => $user,
            'activeRequest'  => $activeRequest,
            'bloodTypes'     => $bloodTypes,
            'components'     => $components,
            'localities'     => $localities,
            'radii'          => $radii,
            'bloodType'      => $bloodType,
            'component'      => $component,
            'localityKey'    => $localityKey,
            'radius'         => $radius,
            'placeShort'     => $placeShort,
            'networkName'    => config('bloodlink.region_short')[$locality['region']] ?? 'Partner',
            'results'        => $results,
            'context'        => $context,
            'requestStatus'  => $activeRequest ? $this->requestStatusLabel($activeRequest) : null,
        ]);
    }

    /** Works out how one facility card should look. */
    private function describe(Hospital $hospital, ?BloodStock $stock, ?BloodRequest $active, ?float $distance, int $staleAfter, string $bloodType, string $componentWord): array
    {
        $type = str_replace('-', '−', $bloodType);

        $row = [
            'hospital'  => $hospital,
            'distance'  => $distance,
            'updated'   => $stock ? ($stock->updated_at ?? $stock->created_at) : null,
            'stock'     => $stock,
            'state'     => 'stock',
            'badge'     => 'blue',
            'badgeText' => 'Stock reported',
            'line'      => '',
            'lineTone'  => 'normal',
            'action'    => 'reserve',
        ];

        $forThisFacility = $active && $active->hospital_id === $hospital->id;

        if ($forThisFacility && in_array($active->status, [
            BloodRequest::STATUS_MATCHED,
            BloodRequest::STATUS_READY,
            BloodRequest::STATUS_IN_TRANSIT,
        ], true)) {
            return array_merge($row, [
                'state'     => 'matched',
                'badge'     => 'green',
                'badgeText' => $active->status === BloodRequest::STATUS_IN_TRANSIT ? 'In transit' : ($active->status === BloodRequest::STATUS_READY ? 'Pickup authorized' : 'Matched'),
                'line'      => $active->units . ' ' . $type . ' ' . Str::plural('unit', $active->units) . ' ' . match ($active->status) {
                    BloodRequest::STATUS_READY => 'authorized for pickup',
                    BloodRequest::STATUS_IN_TRANSIT => 'released to transport',
                    default => 'reserved for your request',
                },
                'lineTone'  => 'good',
                'action'    => 'view',
            ]);
        }

        if ($forThisFacility) {
            return array_merge($row, [
                'state'     => 'requested',
                'badge'     => 'amber',
                'badgeText' => 'Request sent',
                'line'      => 'Waiting for the facility to review your request',
                'action'    => 'view',
            ]);
        }

        if (! $stock || $stock->units < 1) {
            return array_merge($row, [
                'state'     => 'none',
                'badge'     => 'neutral',
                'badgeText' => 'No stock listed',
                'line'      => $type . ' ' . $componentWord . ' · no units reported',
                'action'    => 'contact',
            ]);
        }

        // A stock row with no timestamp at all is treated as old.
        $stamp      = $stock->updated_at ?? $stock->created_at;
        $minutesAgo = $stamp ? (int) floor((now()->getTimestamp() - $stamp->getTimestamp()) / 60) : PHP_INT_MAX;

        if ($minutesAgo > $staleAfter) {
            return array_merge($row, [
                'state'     => 'stale',
                'badge'     => 'amber',
                'badgeText' => 'Refresh needed',
                'line'      => $type . ' ' . $componentWord . ' · call to verify',
                'action'    => 'contact',
            ]);
        }

        return array_merge($row, [
            'line' => $type . ' ' . $componentWord . ' · ' . $stock->units . ' ' . Str::plural('unit', $stock->units) . ' reported',
        ]);
    }

    private function requestStatusLabel(BloodRequest $request): string
    {
        return match ($request->status) {
            BloodRequest::STATUS_MATCHED  => 'Matched · Verification pending',
            BloodRequest::STATUS_READY    => 'Ready for pickup',
            BloodRequest::STATUS_IN_TRANSIT => 'In transit · Contact facility',
            BloodRequest::STATUS_VERIFIED => 'Verified · Finding a facility',
            default                       => 'Request filed',
        };
    }

    private function pick($value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }
}
