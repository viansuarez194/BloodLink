<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Appointment;
use App\Models\BloodRequest;
use App\Models\Donation;
use App\Models\Hospital;
use App\Models\RequestDocument;
use App\Models\User;
use App\Notifications\BloodLinkNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        return $this->show($request, 'overview');
    }

    public function users(Request $request)
    {
        return $this->show($request, 'users');
    }

    public function partners(Request $request)
    {
        return $this->show($request, 'partners');
    }

    public function records(Request $request)
    {
        return $this->show($request, 'records');
    }

    public function requests(Request $request)
    {
        return $this->show($request, 'requests');
    }

    public function analytics(Request $request)
    {
        return $this->show($request, 'analytics');
    }

    public function audit(Request $request)
    {
        return $this->show($request, 'audit');
    }

    public function notifications(Request $request)
    {
        return $this->show($request, 'notifications');
    }

    public function settings(Request $request)
    {
        return $this->show($request, 'settings');
    }

    public function security(Request $request)
    {
        return $this->show($request, 'security');
    }

    public function approvePartner(Request $request, Hospital $hospital)
    {
        if ($hospital->is_partner) {
            throw ValidationException::withMessages([
                'facility' => 'This facility has already been approved as a partner.',
            ]);
        }

        DB::transaction(function () use ($request, $hospital) {
            $locked = Hospital::query()->whereKey($hospital->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_partner) {
                throw ValidationException::withMessages([
                    'facility' => 'This facility has already been approved as a partner.',
                ]);
            }

            $locked->update(['is_partner' => true]);
            $locked->accounts()->each(fn (User $account) => $account->notify(new BloodLinkNotification(
                'Your facility is approved',
                $locked->name . ' is now an approved BloodLink partner facility.',
                '/hospital/dashboard',
                'approval'
            )));
            AdminAuditLog::create([
                'actor_user_id' => $request->user()->id,
                'action' => 'partner_approved',
                'subject_type' => Hospital::class,
                'subject_id' => $locked->id,
                'subject_label' => $locked->name,
                'details' => 'Facility approved as a BloodLink partner.',
            ]);
        });

        return back()->with('status', $hospital->name . ' is now an approved partner facility.');
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);
        $user = $request->user();
        $oldName = $user->name;
        $user->update(['name' => trim($data['name'])]);
        if ($oldName !== $user->name) {
            $this->recordAdminAction($user, 'admin_profile_updated', User::class, $user->id, $user->name, 'Admin display name updated.');
        }

        return back()->with('status', 'Admin profile updated.');
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        $user = $request->user();
        $user->update(['password' => Hash::make($data['password'])]);
        $this->recordAdminAction($user, 'admin_password_updated', User::class, $user->id, $user->name, 'Admin password updated.');

        return back()->with('status', 'Admin password updated.');
    }

    private function show(Request $request, string $section)
    {
        $search = trim((string) $request->query('q', ''));
        $role = (string) $request->query('role', '');
        $roles = [...User::ROLES, User::ROLE_SUPERADMIN];
        if (! in_array($role, $roles, true)) {
            $role = '';
        }

        $reviewableRequests = BloodRequest::query()
            ->where('share_authorized_data', true)
            ->whereNotIn('status', [BloodRequest::STATUS_PAUSED, BloodRequest::STATUS_CANCELLED])
            ->select('id');

        $usersQuery = User::query()->with('hospital')->orderByDesc('created_at');
        if ($search !== '') {
            $usersQuery->where(fn ($query) => $query
                ->where('name', 'like', '%' . $search . '%')
                ->orWhere('email', 'like', '%' . $search . '%')
                ->orWhere('organization', 'like', '%' . $search . '%'));
        }
        if ($role !== '') {
            $usersQuery->where('role', $role);
        }
        $userRows = $section === 'users'
            ? $usersQuery->paginate(15)->withQueryString()
            : $usersQuery->limit(8)->get();

        $pendingPartners = Hospital::query()->withCount('accounts')
            ->where('is_partner', false)->orderByDesc('created_at')->limit(6)->get();
        $partnerRows = $section === 'partners'
            ? Hospital::query()->withCount('accounts')->orderBy('is_partner')->orderBy('name')
                ->paginate(15)->withQueryString()
            : collect();

        $requestRows = $section === 'requests'
            ? BloodRequest::query()->with('hospital:id,name')
                ->orderByDesc('created_at')->paginate(15)->withQueryString()
            : collect();

        $auditRows = $section === 'audit'
            ? AdminAuditLog::query()->with('actor:id,name')->orderByDesc('created_at')
                ->paginate(20)->withQueryString()
            : AdminAuditLog::query()->with('actor:id,name')->orderByDesc('created_at')->limit(6)->get();

        $latestDonations = Donation::query()->with('hospital:id,name')
            ->orderByDesc('donated_at')->limit(6)->get();
        $latestAppointments = Appointment::query()->with('hospital:id,name')
            ->orderByDesc('scheduled_at')->limit(6)->get();

        $requestsByStatus = collect(BloodRequest::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status'));
        $usersByRole = User::query()
            ->select('role', DB::raw('COUNT(*) as total'))
            ->groupBy('role')
            ->pluck('total', 'role');

        return view('admin.dashboard', [
            'section' => $section,
            'search' => $search,
            'roleFilter' => $role,
            'roles' => $roles,
            'users' => $userRows,
            'pendingPartners' => $pendingPartners,
            'partners' => $partnerRows,
            'requests' => $requestRows,
            'auditRows' => $auditRows,
            'latestDonations' => $latestDonations,
            'latestAppointments' => $latestAppointments,
            'usersByRole' => $usersByRole,
            'requestsByStatus' => $requestsByStatus,
            'metrics' => [
                'registered_users' => User::query()->count(),
                'active_partners' => Hospital::query()->where('is_partner', true)->count(),
                'open_requests' => BloodRequest::query()->whereIn('status', [
                    BloodRequest::STATUS_PENDING,
                    BloodRequest::STATUS_VERIFIED,
                    BloodRequest::STATUS_MATCHED,
                    BloodRequest::STATUS_READY,
                    BloodRequest::STATUS_IN_TRANSIT,
                ])->count(),
                'pending_partners' => Hospital::query()->where('is_partner', false)->count(),
                'documents_review' => RequestDocument::query()
                    ->where('status', 'review_required')
                    ->whereIn('blood_request_id', $reviewableRequests)->count(),
                'appointments' => Appointment::query()->count(),
                'donations' => Donation::query()->where('status', Donation::STATUS_COMPLETED)->count(),
                'inventory_units' => \App\Models\BloodStock::query()->sum('units'),
                'transfers' => \App\Models\Transfer::query()->count(),
                'superadmins' => User::query()->where('role', User::ROLE_SUPERADMIN)->count(),
            ],
            'admins' => $section === 'security'
                ? User::query()->where('role', User::ROLE_SUPERADMIN)->orderBy('name')->get()
                : collect(),
        ]);
    }

    private function recordAdminAction(User $actor, string $action, string $subjectType, int $subjectId, string $subjectLabel, string $details): void
    {
        AdminAuditLog::create([
            'actor_user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel,
            'details' => $details,
        ]);
    }
}
