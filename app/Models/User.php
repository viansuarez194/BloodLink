<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_DONOR    = 'donor';
    public const ROLE_RECEIVER = 'receiver';
    public const ROLE_HOSPITAL = 'hospital';
    public const ROLE_SUPERADMIN = 'superadmin';

    public const ROLES = [self::ROLE_DONOR, self::ROLE_RECEIVER, self::ROLE_HOSPITAL];

    protected $fillable = [
        'name',
        'email',
        'mobile',
        'password',
        'role',
        'blood_type',
        'date_of_birth',
        'sex',
        'region',
        'organization',
        'hospital_id',
        'emergency_contact_name',
        'emergency_contact_relationship',
        'emergency_contact_mobile',
        'home_city',
        'notification_appointment_reminders',
        'notification_request_updates',
        'notification_health_tips',
        'research_contact_consent',
        'visible_to_coordinators',
        'preferred_availability',
        'travel_radius_km',
    ];

    private ?int $completedCount = null;
    private ?int $activeRequests = null;
    private ?int $upcomingAppointments = null;
    private ?array $facilityBadgeCache = null;

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'date_of_birth'     => 'date',
            'password'          => 'hashed',
            'notification_appointment_reminders' => 'boolean',
            'notification_request_updates'       => 'boolean',
            'notification_health_tips'           => 'boolean',
            'research_contact_consent'             => 'boolean',
            'research_contact_consent_at'           => 'datetime',
            'visible_to_coordinators'             => 'boolean',
            'travel_radius_km'                    => 'integer',
        ];
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function driveInvitations(): HasMany
    {
        return $this->hasMany(DonorDriveInvitation::class, 'donor_id');
    }

    public function bloodRequests(): HasMany
    {
        return $this->hasMany(BloodRequest::class);
    }

    public function savedFacilities(): BelongsToMany
    {
        return $this->belongsToMany(Hospital::class, 'saved_facilities')->withTimestamps();
    }

    /** Receiver: requests still being worked on (used by the sidebar badge too). */
    public function activeRequestsCount(): int
    {
        return $this->activeRequests ??= $this->bloodRequests()->active()->count();
    }

    public function upcomingAppointmentsCount(): int
    {
        return $this->upcomingAppointments ??= $this->appointments()
            ->where('status', Appointment::STATUS_SCHEDULED)
            ->where('scheduled_at', '>=', now())
            ->count();
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    /** Facility name for hospital accounts, personal name for everyone else. */
    public function displayName(): string
    {
        return $this->role === self::ROLE_HOSPITAL && $this->organization
            ? $this->organization
            : $this->name;
    }

    /**
     * Hospital accounts need a facility record. If there is none yet, create one.
     * New facilities are NOT verified partners (is_partner = false) until an admin approves them,
     * so they do not show up in public search.
     */
    public function ensureFacility(): ?Hospital
    {
        if ($this->role !== self::ROLE_HOSPITAL) {
            return null;
        }

        if ($this->hospital_id && $this->hospital) {
            return $this->hospital;
        }

        $hospital = Hospital::create([
            'name'       => $this->organization ?: $this->name,
            'city'       => config('bloodlink.region_short')[$this->region] ?? 'Philippines',
            'region'     => $this->region ?: 'NCR',
            'is_partner' => false,
            'is_synced'  => false,
        ]);

        $this->forceFill(['hospital_id' => $hospital->id])->save();
        $this->setRelation('hospital', $hospital);

        return $hospital;
    }

    /** Hospital sidebar badges: low-stock types, incoming requests, active dispatch runs. */
    public function facilityBadges(): array
    {
        if ($this->facilityBadgeCache !== null) {
            return $this->facilityBadgeCache;
        }

        if (! $this->hospital_id) {
            return $this->facilityBadgeCache = ['low' => 0, 'incoming' => 0, 'dispatch' => 0];
        }

        return $this->facilityBadgeCache = [
            'low' => BloodStock::query()
                ->where('hospital_id', $this->hospital_id)
                ->where('component', BloodStock::COMPONENT_WHOLE_BLOOD)
                ->whereColumn('units', '<', 'minimum_reserve')
                ->count(),
            'incoming' => BloodRequest::query()
                ->where('hospital_id', $this->hospital_id)
                ->whereIn('status', [
                    BloodRequest::STATUS_PENDING,
                    BloodRequest::STATUS_VERIFIED,
                    BloodRequest::STATUS_MATCHED,
                    BloodRequest::STATUS_READY,
                ])
                ->count(),
            'dispatch' => Dispatch::query()
                ->where('hospital_id', $this->hospital_id)
                ->whereIn('status', Dispatch::ACTIVE_STATUSES)
                ->count(),
        ];
    }

    public function prescreenings(): HasMany
    {
        return $this->hasMany(Prescreening::class);
    }

    /** Last completed donation, next eligibility date and whether the donor is past it. */
    public function donationStatus(): array
    {
        $last = $this->donations()->completed()->orderByDesc('donated_at')->first();
        $next = $last?->donated_at?->copy()->addDays((int) config('bloodlink.donation_interval_days'));

        return [
            'last'     => $last?->donated_at,
            'next'     => $next,
            'eligible' => $next === null || $next->lte(today()),
        ];
    }

    public function completedDonationsCount(): int
    {
        return $this->completedCount ??= $this->donations()->completed()->count();
    }

    /**
     * Donor rank based on completed donations (tiers are set in config/bloodlink.php).
     * Returns: name, label (e.g. "Silver Donor"), next tier name and donations still needed.
     */
    public function donorTier(): array
    {
        $count   = $this->completedDonationsCount();
        $current = null;
        $next    = null;

        foreach (config('bloodlink.donor_tiers') as $tier) {
            if ($count >= $tier['min']) {
                $current = $tier;
            } elseif ($next === null) {
                $next = $tier;
            }
        }

        $name = $current['name'] ?? 'New Donor';

        return [
            'name'      => $name,
            'label'     => $current ? $name . ' Donor' : $name,
            'next'      => $next['name'] ?? null,
            'remaining' => $next ? $next['min'] - $count : 0,
        ];
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->displayName())) ?: [];
        $first = mb_substr($words[0] ?? '', 0, 1);
        $last  = count($words) > 1 ? mb_substr(end($words), 0, 1) : '';

        return mb_strtoupper($first . $last);
    }

    public function coordinationReference(): string
    {
        return 'DL-' . strtoupper(substr(hash_hmac('sha256', (string) $this->id, (string) config('app.key')), 0, 7));
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_DONOR    => 'Standby Blood Donor',
            self::ROLE_RECEIVER => 'Receiver / Patient',
            self::ROLE_HOSPITAL => 'Hospital & Lab Partner',
            self::ROLE_SUPERADMIN => 'Super Admin',
            default             => 'Member',
        };
    }

    /**
     * Turn "+63 917 123 4567", "0917-123-4567" or "9171234567" into "09171234567".
     */
    public static function normalizeMobile(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '0' . substr($digits, 2);
        }

        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '0' . $digits;
        }

        return $digits;
    }
}
