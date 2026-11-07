<?php

namespace App\Http\Controllers\Receiver;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BloodRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RequestsController extends Controller
{
    private const PER_PAGE = 8;

    public function index(Request $request)
    {
        $user = $request->user();
        $filters = $this->validatedFilters($request);
        $requests = $this->filteredRequests($user->id, $filters);
        $page = $requests->paginate(self::PER_PAGE)->withQueryString();
        $selectedId = $request->integer('selected');
        $selected = $selectedId
            ? $page->getCollection()->firstWhere('id', $selectedId)
            : null;
        $selected ??= $page->getCollection()->first();

        $counts = [
            'all' => $user->bloodRequests()->where('status', '!=', BloodRequest::STATUS_DRAFT)->count(),
            'active' => $user->bloodRequests()->active()->count(),
            'completed' => $user->bloodRequests()->where('status', BloodRequest::STATUS_COMPLETED)->count(),
            'cancelled' => $user->bloodRequests()->where('status', BloodRequest::STATUS_CANCELLED)->count(),
        ];

        $appointment = null;
        if ($selected?->hospital_id && in_array($selected->status, [
            BloodRequest::STATUS_VERIFIED,
            BloodRequest::STATUS_MATCHED,
            BloodRequest::STATUS_READY,
            BloodRequest::STATUS_IN_TRANSIT,
        ], true)) {
            $appointment = $user->appointments()
                ->with('hospital')
                ->where('status', Appointment::STATUS_SCHEDULED)
                ->where('hospital_id', $selected->hospital_id)
                ->where('scheduled_at', '>=', now())
                ->orderBy('scheduled_at')
                ->first();
        }

        return view('receiver.requests', [
            'user' => $user,
            'requests' => $page,
            'selected' => $selected,
            'counts' => $counts,
            'filters' => $filters,
            'appointment' => $appointment,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validatedFilters($request);
        $requests = $this->filteredRequests($request->user()->id, $filters)
            ->orderByDesc('created_at')
            ->get();

        return response()->streamDownload(function () use ($requests) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Request ID', 'Created', 'Blood group', 'Requirement', 'Facility', 'Status', 'Needed by']);

            foreach ($requests as $bloodRequest) {
                fputcsv($output, array_map($this->safeCell(...), [
                    $bloodRequest->reference,
                    $bloodRequest->created_at?->format('Y-m-d H:i'),
                    $bloodRequest->bloodTypeLabel(),
                    $bloodRequest->units . ' ' . $bloodRequest->componentLabel(),
                    $bloodRequest->hospital?->name ?? 'Not assigned',
                    $bloodRequest->statusLabel(),
                    $bloodRequest->needed_by?->format('Y-m-d H:i'),
                ]));
            }

            fclose($output);
        }, 'bloodlink-request-history-' . now()->format('Ymd') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function validatedFilters(Request $request): array
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:all,active,completed,cancelled'],
            'q' => ['nullable', 'string', 'max:120'],
            'period' => ['nullable', 'in:all,30,90,365'],
            'blood_type' => ['nullable', 'in:' . implode(',', array_keys(config('bloodlink.blood_types')))],
            'selected' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return [
            'status' => $data['status'] ?? 'all',
            'q' => trim($data['q'] ?? ''),
            'period' => $data['period'] ?? 'all',
            'blood_type' => $data['blood_type'] ?? '',
        ];
    }

    private function filteredRequests(int $userId, array $filters): Builder
    {
        $query = BloodRequest::query()
            ->with('hospital')
            ->where('user_id', $userId)
            ->where('status', '!=', BloodRequest::STATUS_DRAFT);

        if ($filters['status'] === 'active') {
            $query->active();
        } elseif (in_array($filters['status'], [BloodRequest::STATUS_COMPLETED, BloodRequest::STATUS_CANCELLED], true)) {
            $query->where('status', $filters['status']);
        }

        if ($filters['q'] !== '') {
            $search = $filters['q'];
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('reference', 'like', '%' . $search . '%')
                    ->orWhere('patient_name', 'like', '%' . $search . '%')
                    ->orWhereHas('hospital', fn (Builder $hospitalQuery) => $hospitalQuery->where('name', 'like', '%' . $search . '%'));
            });
        }

        if ($filters['period'] !== 'all') {
            $query->where('created_at', '>=', now()->subDays((int) $filters['period']));
        }

        if ($filters['blood_type'] !== '') {
            $query->where('blood_type', $filters['blood_type']);
        }

        return $query
            ->orderByRaw("CASE WHEN status IN ('pending', 'verified', 'matched', 'ready', 'in_transit') THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function safeCell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
