<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prescreening extends Model
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SUBMITTED = 'submitted';

    protected $fillable = ['user_id', 'status', 'current_step', 'answers', 'details', 'submitted_at'];

    protected function casts(): array
    {
        return [
            'current_step' => 'integer',
            'answers'      => 'array',
            'details'      => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Question steps (2 and 3). */
    public static function questionSteps(): array
    {
        return array_keys(config('bloodlink.prescreening.sections'));
    }

    public static function questionsFor(int $step): array
    {
        return config("bloodlink.prescreening.sections.{$step}.questions", []);
    }

    public static function sectionTitle(int $step): string
    {
        return config("bloodlink.prescreening.sections.{$step}.title")
            ?? config("bloodlink.prescreening.steps.{$step}", '');
    }

    public function answer(string $key): ?string
    {
        return $this->answers[$key] ?? null;
    }

    public function detail(string $key): ?string
    {
        return $this->details[$key] ?? null;
    }

    public function answeredCount(int $step): int
    {
        return collect(self::questionsFor($step))
            ->filter(fn ($q) => in_array($this->answer($q['key']), ['yes', 'no'], true))
            ->count();
    }

    /** Every question answered, and details written where a "yes" needs them. */
    public function isStepComplete(int $step): bool
    {
        foreach (self::questionsFor($step) as $q) {
            $answer = $this->answer($q['key']);

            if (! in_array($answer, ['yes', 'no'], true)) {
                return false;
            }

            if (isset($q['details']) && $answer === 'yes' && trim((string) $this->detail($q['key'])) === '') {
                return false;
            }
        }

        return true;
    }

    public function isComplete(): bool
    {
        foreach (self::questionSteps() as $step) {
            if (! $this->isStepComplete($step)) {
                return false;
            }
        }

        return true;
    }

    /** The questions whose answer sends them to the screening team. */
    public function flaggedQuestions(): array
    {
        $flagged = [];

        foreach (self::questionSteps() as $step) {
            foreach (self::questionsFor($step) as $q) {
                if ($this->answer($q['key']) === ($q['flag'] ?? null)) {
                    $flagged[] = $q;
                }
            }
        }

        return $flagged;
    }

    public function isFlagged(array $question): bool
    {
        return $this->answer($question['key']) === ($question['flag'] ?? null);
    }
}
