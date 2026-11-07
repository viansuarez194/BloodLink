<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BloodUnit extends Model
{
    public const STATUS_AVAILABLE  = 'available';
    public const STATUS_RESERVED   = 'reserved';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_DISCARDED  = 'discarded';

    /** Short codes used inside unit identifiers. */
    public const COMPONENT_CODES = [
        'whole_blood' => 'WB',
        'packed_rbc'  => 'RBC',
        'platelets'   => 'PLT',
        'plasma'      => 'PLS',
    ];

    protected $fillable = [
        'hospital_id', 'unit_code', 'blood_type', 'component', 'collected_at', 'expires_at',
        'storage_unit', 'shelf', 'temperature', 'status', 'reserved_for', 'reservation_reference',
    ];

    protected function casts(): array
    {
        return [
            'collected_at' => 'date',
            'expires_at'   => 'date',
            'temperature'  => 'float',
        ];
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    /** Units physically in the blood bank (not dispatched or discarded). */
    public function scopeOnHand(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_AVAILABLE, self::STATUS_RESERVED]);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lt(today());
    }

    public function isExpiringSoon(): bool
    {
        return $this->status === self::STATUS_AVAILABLE
            && ! $this->isExpired()
            && $this->expires_at->lte(today()->addDays((int) config('bloodlink.inventory.expiring_days')));
    }

    /** "Available", "Reserved · PGH", "Expiring · 3 d", "Expired" */
    public function stateLabel(): string
    {
        if ($this->isExpired()) {
            return 'Expired';
        }

        if ($this->status === self::STATUS_RESERVED) {
            return 'Reserved' . ($this->reserved_for ? ' · ' . self::shortName($this->reserved_for) : '');
        }

        if ($this->isExpiringSoon()) {
            $days = (int) today()->diffInDays($this->expires_at, false);

            return 'Expiring · ' . max(0, $days) . ' d';
        }

        return 'Available';
    }

    /** Badge colour: green | blue | amber | red */
    public function badgeKind(): string
    {
        if ($this->isExpired()) return 'red';
        if ($this->status === self::STATUS_RESERVED) return 'blue';
        if ($this->isExpiringSoon()) return 'amber';

        return 'green';
    }

    /** "BB-01 / A2" */
    public function storageLabel(): string
    {
        return collect([$this->storage_unit, $this->shelf])->filter()->implode(' / ') ?: '—';
    }

    public function temperatureLabel(): string
    {
        return $this->temperature !== null ? number_format($this->temperature, 1) . '°C' : '—';
    }

    /** "Philippine General Hospital" -> "PGH" (3+ words), otherwise the name itself. */
    public static function shortName(string $name): string
    {
        $words = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->reject(fn ($w) => in_array(strtolower($w), ['of', 'the', 'and', '&'], true))
            ->values();

        if ($words->count() < 3) {
            return $name;
        }

        return $words->map(fn ($w) => Str::upper(mb_substr($w, 0, 1)))->implode('');
    }

    /** "St. Luke's BGC" -> "SLB" */
    public static function facilityPrefix(string $hospitalName): string
    {
        $letters = collect(preg_split('/\s+/', trim($hospitalName)) ?: [])
            ->map(fn ($w) => preg_replace('/[^A-Za-z]/', '', $w))
            ->filter()
            ->take(3)
            ->map(fn ($w) => Str::upper(mb_substr($w, 0, 1)))
            ->implode('');

        return str_pad($letters ?: 'BLK', 2, 'X');
    }

    /**
     * Keep blood_stocks (the totals other pages read) equal to the units physically on hand.
     * Only groups that have unit records are touched; totals typed in by hand stay as they are.
     */
    public static function syncStocks(int $hospitalId): void
    {
        $rows = static::query()
            ->where('hospital_id', $hospitalId)
            ->selectRaw(
                "blood_type, component, SUM(CASE WHEN status IN ('available', 'reserved') AND expires_at >= ? THEN 1 ELSE 0 END) AS on_hand",
                [today()->toDateString()]
            )
            ->groupBy('blood_type', 'component')
            ->get();

        foreach ($rows as $row) {
            $stock = BloodStock::firstOrNew([
                'hospital_id' => $hospitalId,
                'blood_type'  => $row->blood_type,
                'component'   => $row->component,
            ]);

            $onHand = (int) $row->on_hand;

            if (! $stock->exists || (int) $stock->units !== $onHand) {
                $stock->units = $onHand;
                $stock->save();
            }
        }
    }
}
