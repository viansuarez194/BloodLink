<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HistoryController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request)
    {
        $user = $request->user();

        // A donor's history is small, so one query is enough for everything on this page.
        $all = $user->donations()->completed()->with('hospital')
            ->orderByDesc('donated_at')->orderByDesc('id')->get();

        $total        = $all->count();
        $livesPerGift = (int) config('bloodlink.lives_per_donation');

        // ----- year tabs + filtering -----
        $years = $all->map(fn ($d) => $d->donated_at->year)->unique()->sortDesc()->values();
        $year  = (int) $request->query('year');
        $year  = $years->contains($year) ? $year : null;

        $filtered = $year ? $all->filter(fn ($d) => $d->donated_at->year === $year)->values() : $all;
        $count    = $filtered->count();

        // ----- paging -----
        $lastPage = max(1, (int) ceil($count / self::PER_PAGE));
        $page     = min(max(1, (int) $request->query('page', 1)), $lastPage);
        $rows     = $filtered->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();

        $selected = $rows->firstWhere('id', (int) $request->query('donation')) ?? $rows->first();

        // ----- journey: the three most recent years -----
        $journey = $all->groupBy(fn ($d) => $d->donated_at->year)->take(3)->map(fn ($group, $y) => [
            'year'  => $y,
            'count' => $group->count(),
            'lives' => $group->count() * $livesPerGift,
        ])->values();

        $centers = $all->map(fn ($d) => $d->hospital_id ? 'h' . $d->hospital_id : 'c:' . mb_strtolower((string) $d->center_name))
            ->unique()->count();

        // ----- next eligibility (from the donations we already loaded) -----
        $last         = $all->first()?->donated_at;
        $nextEligible = $last?->copy()->addDays((int) config('bloodlink.donation_interval_days'));
        $isEligible   = $nextEligible === null || $nextEligible->lte(today());

        $query = fn (array $extra) => array_filter(
            array_merge(['year' => $year, 'page' => $page > 1 ? $page : null], $extra),
            fn ($value) => $value !== null
        );

        return view('donor.history', [
            'user'         => $user,
            'tier'         => $user->donorTier(),
            'total'        => $total,
            'lives'        => $total * $livesPerGift,
            'years'        => $years,
            'year'         => $year,
            'count'        => $count,
            'rows'         => $rows,
            'selected'     => $selected,
            'page'         => $page,
            'lastPage'     => $lastPage,
            'from'         => $count === 0 ? 0 : ($page - 1) * self::PER_PAGE + 1,
            'to'           => ($page - 1) * self::PER_PAGE + $rows->count(),
            'journey'      => $journey,
            'centers'      => $centers,
            'nextEligible' => $nextEligible,
            'isEligible'   => $isEligible,
            'rowUrl'       => fn ($donation) => route('donor.history', $query(['donation' => $donation->id])),
            'tabUrl'       => fn ($y) => route('donor.history', array_filter(['year' => $y])),
            'pageUrl'      => fn ($p) => route('donor.history', array_filter(['year' => $year, 'page' => $p > 1 ? $p : null])),
        ]);
    }

    /** CSV file the donor can open in Excel or Google Sheets. */
    public function export(Request $request): StreamedResponse
    {
        $user      = $request->user();
        $donations = $user->donations()->completed()->with('hospital')
            ->orderByDesc('donated_at')->orderByDesc('id')->get();

        $filename = 'bloodlink-donation-history-' . now()->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($donations, $user) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // lets Excel read the file as UTF-8

            fputcsv($out, ['Receipt reference', 'Date', 'Hospital / center', 'Type', 'Blood group', 'Volume (mL)', 'Status']);

            foreach ($donations as $d) {
                fputcsv($out, array_map(fn ($cell) => $this->safeCell($cell), [
                    $d->receiptReference(),
                    $d->donated_at->format('Y-m-d'),
                    $d->centerLabel(),
                    $d->typeLabel(),
                    $user->blood_type,
                    $d->volume_ml,
                    ucfirst($d->status),
                ]));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A clean, printable receipt. The donor can use "Save as PDF" in the print dialog. */
    public function receipt(Request $request, Donation $donation)
    {
        abort_unless(
            $donation->user_id === $request->user()->id && $donation->status === Donation::STATUS_COMPLETED,
            404
        );

        return view('donor.receipt', [
            'donation' => $donation->load('hospital'),
            'user'     => $request->user(),
        ]);
    }

    /** Stops spreadsheet programs from running a cell that starts with = + - or @. */
    private function safeCell($value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
