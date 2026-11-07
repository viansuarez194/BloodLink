<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Models\Prescreening;
use Illuminate\Http\Request;

class PrescreeningController extends Controller
{
    private const FIRST_STEP = 2;   // step 1 (personal details) comes from the donor's profile
    private const LAST_STEP  = 4;

    public function show(Request $request)
    {
        $user   = $request->user();
        $draft  = $this->currentDraft($request);
        $submitted = null;

        if (! $draft) {
            $submitted = $user->prescreenings()
                ->where('status', Prescreening::STATUS_SUBMITTED)
                ->orderByDesc('submitted_at')->first();
        }

        // The donor may open any step up to the furthest one they have reached.
        $furthest = $draft?->current_step ?? self::FIRST_STEP;
        $step     = (int) $request->query('step', $furthest);
        $step     = max(self::FIRST_STEP, min($step, $furthest, self::LAST_STEP));

        $screening = $draft ?? $submitted;      // what the page reads answers from
        $donation  = $user->donationStatus();

        $showMissing = $screening && in_array($step, Prescreening::questionSteps(), true)
            && ($screening->answeredCount($step) > 0 || $request->session()->has('errors'));

        return view('donor.prescreening', [
            'user'        => $user,
            'tier'        => $user->donorTier(),
            'screening'   => $screening,
            'isSubmitted' => $submitted !== null,
            'step'        => $step,
            'furthest'    => $submitted ? self::LAST_STEP : $furthest,
            'steps'       => config('bloodlink.prescreening.steps'),
            'questions'   => Prescreening::questionsFor($step),
            'sections'    => Prescreening::questionSteps(),
            'showMissing' => $showMissing,
            'lastDonation'=> $donation['last'],
            'nextEligible'=> $donation['next'],
            'isEligible'  => $donation['eligible'],
        ]);
    }

    /** Start over with an empty draft (after a screening was already submitted). */
    public function start(Request $request)
    {
        $user = $request->user();

        if (! $this->currentDraft($request)) {
            $user->prescreenings()->create([
                'status'       => Prescreening::STATUS_DRAFT,
                'current_step' => self::FIRST_STEP,
                'answers'      => [],
                'details'      => [],
            ]);
        }

        return redirect()->route('donor.pre-screening');
    }

    public function store(Request $request)
    {
        $user   = $request->user();
        $step   = (int) $request->input('step');
        $action = (string) $request->input('action', 'next');

        abort_unless($step >= self::FIRST_STEP && $step <= self::LAST_STEP, 422);
        abort_unless(in_array($action, ['back', 'draft', 'next', 'submit'], true), 422);

        $screening = $this->currentDraft($request) ?? $user->prescreenings()->make([
            'status'       => Prescreening::STATUS_DRAFT,
            'current_step' => self::FIRST_STEP,
            'answers'      => [],
            'details'      => [],
        ]);

        // Keep stale forms or out-of-order submissions on the furthest saved step.
        if ($step > $screening->current_step) {
            return redirect()
                ->route('donor.pre-screening', ['step' => $screening->current_step])
                ->with('status', 'Please complete the screening sections in order before continuing.');
        }

        // ----- question steps: sanitize, validate on "next", then store -----
        if (in_array($step, Prescreening::questionSteps(), true)) {
            $questions = Prescreening::questionsFor($step);

            if ($action === 'next') {
                $this->validateStep($request, $questions);
            }

            $answers = $screening->answers ?? [];
            $details = $screening->details ?? [];

            foreach ($questions as $q) {
                $key   = $q['key'];
                $value = $request->input("answers.{$key}");

                if (in_array($value, ['yes', 'no'], true)) {
                    $answers[$key] = $value;
                } else {
                    unset($answers[$key]);
                }

                // Keep details only while they still apply (answer is "yes").
                $text = trim((string) $request->input("details.{$key}"));

                if (isset($q['details']) && ($answers[$key] ?? null) === 'yes' && $text !== '') {
                    $details[$key] = mb_substr($text, 0, 500);
                } else {
                    unset($details[$key]);
                }
            }

            $screening->answers = $answers;
            $screening->details = $details;
        }

        // ----- where to go next -----
        $redirect = redirect()->route('donor.pre-screening', ['step' => $step]);
        $message  = null;

        if ($action === 'next') {
            $screening->current_step = max($screening->current_step, min($step + 1, self::LAST_STEP));
            $redirect = redirect()->route('donor.pre-screening', ['step' => min($step + 1, self::LAST_STEP)]);
        } elseif ($action === 'back') {
            $redirect = redirect()->route('donor.pre-screening', ['step' => max($step - 1, self::FIRST_STEP)]);
        } elseif ($action === 'draft') {
            $message = 'Draft saved. You can come back and finish anytime.';
        } elseif ($action === 'submit') {
            $request->validate(['consent' => ['accepted']], [
                'consent.accepted' => 'Please confirm your answers and consent before submitting.',
            ]);

            // Every question step must be complete.
            foreach (Prescreening::questionSteps() as $questionStep) {
                if (! $screening->isStepComplete($questionStep)) {
                    $screening->save();

                    return redirect()
                        ->route('donor.pre-screening', ['step' => $questionStep])
                        ->with('status', 'Please answer every question in "' . Prescreening::sectionTitle($questionStep) . '" before submitting.');
                }
            }

            $screening->status       = Prescreening::STATUS_SUBMITTED;
            $screening->submitted_at = now();
            $screening->save();

            return redirect()->route('donor.pre-screening')->with('status', 'Your pre-screening was submitted.');
        }

        $screening->save();

        return $message ? $redirect->with('status', $message) : $redirect;
    }

    private function currentDraft(Request $request): ?Prescreening
    {
        return $request->user()->prescreenings()
            ->where('status', Prescreening::STATUS_DRAFT)
            ->orderByDesc('id')
            ->first();
    }

    private function validateStep(Request $request, array $questions): void
    {
        $rules    = [];
        $messages = [];

        foreach ($questions as $q) {
            $key = $q['key'];

            $rules["answers.{$key}"]              = ['required', 'in:yes,no'];
            $messages["answers.{$key}.required"]  = 'Please choose Yes or No.';
            $messages["answers.{$key}.in"]        = 'Please choose Yes or No.';

            if (isset($q['details'])) {
                $rules["details.{$key}"]             = ["required_if:answers.{$key},yes", 'nullable', 'string', 'max:500'];
                $messages["details.{$key}.required_if"] = 'Please add a few details so the screening team can review them.';
            }
        }

        $request->validate($rules, $messages);
    }
}
