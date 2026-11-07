<?php

namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BloodStock;
use App\Models\BloodUnit;
use App\Models\Hospital;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryController extends Controller
{
    private const STATES = ['all', 'available', 'reserved', 'expiring'];

    // ------------------------------------------------------------------
    // Main page
    // ------------------------------------------------------------------
    public function index(Request $request)
    {
        $user     = $request->user();
        $hospital = $user->ensureFacility();
        $typeKeys = array_keys(config('bloodlink.blood_types'));

        // Totals follow the unit register (also expires units that passed their date).
        BloodUnit::syncStocks($hospital->id);

        $component = $this->component($request);
        $state     = in_array($request->query('state'), self::STATES, true) ? $request->query('state') : 'all';

        // ----- blood group cards -----
        $stocks = $hospital->stocks()->where('component', $component)->get()->keyBy('blood_type');
        $cards  = collect($typeKeys)->map(fn ($type) => ['type' => $type, 'stock' => $stocks->get($type)]);

        $totalOnHand = (int) $stocks->sum('units');
        $below       = $stocks->filter(fn ($s) => $s->status() !== 'healthy');
        $critical    = $below->filter(fn ($s) => $s->status() === 'critical');

        $lastUpdated = collect([$stocks->max('updated_at'), BloodUnit::where('hospital_id', $hospital->id)->max('updated_at')])
            ->filter()->map(fn ($d) => \Illuminate\Support\Carbon::parse($d))->max();

        // ----- unit register -----
        $expiringDays = (int) config('bloodlink.inventory.expiring_days');

        $register = BloodUnit::query()
            ->where('hospital_id', $hospital->id)
            ->where('component', $component)
            ->onHand()
            ->when($state === 'available', fn ($q) => $q->where('status', BloodUnit::STATUS_AVAILABLE)->where('expires_at', '>=', today()))
            ->when($state === 'reserved', fn ($q) => $q->where('status', BloodUnit::STATUS_RESERVED))
            ->when($state === 'expiring', fn ($q) => $q->where('status', BloodUnit::STATUS_AVAILABLE)
                ->whereBetween('expires_at', [today(), today()->addDays($expiringDays)]))
            ->orderBy('expires_at')->orderBy('unit_code');

        $units    = $register->paginate((int) config('bloodlink.inventory.per_page'))->withQueryString();
        $selected = $units->getCollection()->firstWhere('id', (int) $request->query('unit')) ?? $units->getCollection()->first();

        $reservedCount = ($selected && $selected->reservation_reference)
            ? BloodUnit::where('hospital_id', $hospital->id)->where('reservation_reference', $selected->reservation_reference)->count()
            : 0;

        // ----- who could replenish the low groups -----
        $transferHint = null;
        if ($below->isNotEmpty()) {
            $best = BloodStock::query()
                ->selectRaw('hospital_id, SUM(units) AS total')
                ->where('component', $component)
                ->whereIn('blood_type', $below->keys()->all())
                ->where('hospital_id', '!=', $hospital->id)
                ->whereIn('hospital_id', Hospital::query()->where('is_partner', true)->where('region', $hospital->region)->select('id'))
                ->groupBy('hospital_id')
                ->orderByDesc('total')
                ->first();

            if ($best && (int) $best->total > 0) {
                $transferHint = ['hospital' => Hospital::find($best->hospital_id), 'units' => (int) $best->total];
            }
        }

        return view('hospital.inventory', [
            'hospital'      => $hospital,
            'component'     => $component,
            'components'    => BloodStock::COMPONENTS,
            'state'         => $state,
            'cards'         => $cards,
            'totalOnHand'   => $totalOnHand,
            'below'         => $below,
            'critical'      => $critical,
            'lastUpdated'   => $lastUpdated,
            'units'         => $units,
            'selected'      => $selected,
            'reservedCount' => $reservedCount,
            'transferHint'  => $transferHint,
        ]);
    }

    // ------------------------------------------------------------------
    // Add stock + minimum reserve levels
    // ------------------------------------------------------------------
    public function create(Request $request)
    {
        $hospital  = $request->user()->ensureFacility();
        $component = $this->component($request);

        return view('hospital.inventory-add', [
            'hospital'   => $hospital,
            'component'  => $component,
            'components' => BloodStock::COMPONENTS,
            'bloodTypes' => config('bloodlink.blood_types'),
            'maxBatch'   => (int) config('bloodlink.inventory.max_batch'),
            'reserves'   => $hospital->stocks()->where('component', $component)->pluck('minimum_reserve', 'blood_type'),
            'prefix'     => BloodUnit::facilityPrefix($hospital->name),
        ]);
    }

    public function store(Request $request)
    {
        $user     = $request->user();
        $hospital = $user->ensureFacility();

        $data = $request->validate([
            'blood_type'   => ['required', Rule::in(array_keys(config('bloodlink.blood_types')))],
            'component'    => ['required', Rule::in(array_keys(BloodStock::COMPONENTS))],
            'quantity'     => ['required', 'integer', 'between:1,' . (int) config('bloodlink.inventory.max_batch')],
            'collected_at' => ['required', 'date', 'before_or_equal:today'],
            'expires_at'   => ['required', 'date', 'after_or_equal:today', 'after:collected_at'],
            'storage_unit' => ['nullable', 'string', 'max:30'],
            'shelf'        => ['nullable', 'string', 'max:20'],
            'temperature'  => ['nullable', 'numeric', 'between:-80,30'],
        ], [
            'quantity.between'          => 'You can add 1 to ' . (int) config('bloodlink.inventory.max_batch') . ' units at a time.',
            'collected_at.before_or_equal' => 'The collection date cannot be in the future.',
            'expires_at.after_or_equal' => 'This expiry date has already passed. Expired blood cannot be added to stock.',
            'expires_at.after'          => 'The expiry date must be after the collection date.',
            'temperature.between'       => 'Enter a temperature between -80 and 30 °C.',
        ]);

        $collected = \Illuminate\Support\Carbon::parse($data['collected_at']);
        $codes     = [];

        DB::transaction(function () use ($hospital, $data, $collected, &$codes) {
            $base = BloodUnit::facilityPrefix($hospital->name)
                . '-' . (BloodUnit::COMPONENT_CODES[$data['component']] ?? 'UN')
                . '-' . $collected->format('ymd');

            // Never reuse a number that already exists for this facility.
            $taken = BloodUnit::query()->where('hospital_id', $hospital->id)
                ->where('unit_code', 'like', $base . '-%')->pluck('unit_code')->flip();

            $number = 0;

            for ($i = 0; $i < (int) $data['quantity']; $i++) {
                do {
                    $number++;
                    $code = $base . '-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
                } while ($taken->has($code));

                BloodUnit::create([
                    'hospital_id'  => $hospital->id,
                    'unit_code'    => $code,
                    'blood_type'   => $data['blood_type'],
                    'component'    => $data['component'],
                    'collected_at' => $data['collected_at'],
                    'expires_at'   => $data['expires_at'],
                    'storage_unit' => $data['storage_unit'] ?? null,
                    'shelf'        => $data['shelf'] ?? null,
                    'temperature'  => $data['temperature'] ?? null,
                    'status'       => BloodUnit::STATUS_AVAILABLE,
                ]);

                $codes[] = $code;
            }
        });

        BloodUnit::syncStocks($hospital->id);

        $label = $this->unitsText((int) $data['quantity'], $data['blood_type'], $data['component']);

        $this->log($hospital, $user->name, 'Stock added', $label . ' (' . $codes[0] . ($data['quantity'] > 1 ? ' to ' . end($codes) : '') . ')', 'completed');

        return redirect()
            ->route('hospital.inventory', ['component' => $data['component']])
            ->with('status', $label . ' added to the register.');
    }

    /** Save the minimum reserve (the level that makes a group Low or Critical). */
    public function reserves(Request $request)
    {
        $user     = $request->user();
        $hospital = $user->ensureFacility();
        $types    = array_keys(config('bloodlink.blood_types'));

        $data = $request->validate([
            'component' => ['required', Rule::in(array_keys(BloodStock::COMPONENTS))],
            'reserve'   => ['required', 'array'],
            'reserve.*' => ['nullable', 'integer', 'between:0,999'],
        ], [
            'reserve.*.integer' => 'Minimum reserve must be a whole number.',
            'reserve.*.between' => 'Minimum reserve must be between 0 and 999.',
        ]);

        foreach ($data['reserve'] as $type => $value) {
            if (! in_array($type, $types, true) || $value === null) {
                continue;
            }

            BloodStock::updateOrCreate(
                ['hospital_id' => $hospital->id, 'blood_type' => $type, 'component' => $data['component']],
                ['minimum_reserve' => (int) $value],
            );
        }

        $this->log($hospital, $user->name, 'Reserve levels updated', BloodStock::COMPONENTS[$data['component']] . ' minimum reserves changed', 'completed');

        return redirect()
            ->route('hospital.inventory', ['component' => $data['component']])
            ->with('status', 'Minimum reserve levels saved.');
    }

    // ------------------------------------------------------------------
    // Unit actions
    // ------------------------------------------------------------------
    public function discard(Request $request, BloodUnit $unit)
    {
        $user     = $request->user();
        $hospital = $user->ensureFacility();
        abort_unless($unit->hospital_id === $hospital->id, 404);

        if (in_array($unit->status, [BloodUnit::STATUS_AVAILABLE, BloodUnit::STATUS_RESERVED], true)) {
            $unit->update([
                'status'                => BloodUnit::STATUS_DISCARDED,
                'reserved_for'          => null,
                'reservation_reference' => null,
            ]);

            BloodUnit::syncStocks($hospital->id);
            $this->log($hospital, $user->name, 'Unit discarded', $unit->unit_code . ' removed from stock', 'completed');
        }

        return redirect()->route('hospital.inventory', ['component' => $unit->component])
            ->with('status', "{$unit->unit_code} was removed from stock.");
    }

    public function release(Request $request, BloodUnit $unit)
    {
        $user     = $request->user();
        $hospital = $user->ensureFacility();
        abort_unless($unit->hospital_id === $hospital->id, 404);

        if ($unit->status === BloodUnit::STATUS_RESERVED) {
            $unit->update([
                'status'                => BloodUnit::STATUS_AVAILABLE,
                'reserved_for'          => null,
                'reservation_reference' => null,
            ]);

            $this->log($hospital, $user->name, 'Reservation released', $unit->unit_code . ' is available again', 'completed');
        }

        return redirect()->route('hospital.inventory', ['component' => $unit->component, 'unit' => $unit->id])
            ->with('status', "{$unit->unit_code} is available again.");
    }

    // ------------------------------------------------------------------
    // CSV export
    // ------------------------------------------------------------------
    public function export(Request $request): StreamedResponse
    {
        $hospital  = $request->user()->ensureFacility();
        $component = $this->component($request);

        $units = BloodUnit::query()
            ->where('hospital_id', $hospital->id)
            ->where('component', $component)
            ->onHand()
            ->orderBy('expires_at')->orderBy('unit_code')
            ->get();

        $filename = 'bloodlink-unit-register-' . $component . '-' . now()->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($units, $component) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // lets Excel read the file as UTF-8

            fputcsv($out, ['Unit identifier', 'Blood group', 'Component', 'Collected', 'Expiry', 'Storage', 'Temperature (C)', 'State', 'Reservation']);

            foreach ($units as $u) {
                fputcsv($out, array_map(fn ($cell) => $this->safeCell($cell), [
                    $u->unit_code,
                    $u->blood_type,
                    BloodStock::COMPONENTS[$component] ?? $component,
                    $u->collected_at?->format('Y-m-d'),
                    $u->expires_at->format('Y-m-d'),
                    $u->storageLabel() === '—' ? '' : $u->storageLabel(),
                    $u->temperature,
                    $u->stateLabel(),
                    trim(($u->reservation_reference ?? '') . ' ' . ($u->reserved_for ?? '')),
                ]));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------------
    private function component(Request $request): string
    {
        $value = $request->query('component', $request->input('component'));

        return is_string($value) && array_key_exists($value, BloodStock::COMPONENTS)
            ? $value
            : BloodStock::COMPONENT_WHOLE_BLOOD;
    }

    private function unitsText(int $n, string $type, string $component): string
    {
        $word = strtolower(BloodStock::COMPONENTS[$component] ?? $component);
        $word = str_replace('rbc', 'RBC', $word);

        return $n . ' ' . str_replace('-', '−', $type) . ' ' . $word . ' ' . Str::plural('unit', $n);
    }

    private function log(Hospital $hospital, string $operator, string $activity, string $details, string $status): void
    {
        ActivityLog::create([
            'hospital_id' => $hospital->id,
            'activity'    => $activity,
            'details'     => Str::limit($details, 250, ''),
            'operator'    => $operator,
            'status'      => $status,
        ]);
    }

    /** Stops spreadsheet programs from running a cell that starts with = + - or @. */
    private function safeCell($value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
