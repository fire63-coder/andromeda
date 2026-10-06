<?php

namespace App\Models;

use App\Actions\Challenges\SubmitChallengeAnswer;
use App\Contracts\ExerciseContext;
use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

class ChallengeParticipation extends Model implements ExerciseContext
{
    protected $fillable = [
        'challenge_id', 'user_id', 'score', 'solved_count', 'total_time_ms',
        'final_rank', 'started_at', 'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function submissions(): MorphMany
    {
        return $this->morphMany(UserSubmission::class, 'context');
    }

    /**
     * Échéance personnelle : fin du chrono individuel (contre-la-montre) ou fin du défi.
     */
    public function deadline(): ?Carbon
    {
        $challenge = $this->challenge;
        $personal = $challenge->duration_seconds ? $this->started_at->copy()->addSeconds($challenge->duration_seconds) : null;

        return collect([$personal, $challenge->ends_at])->filter()->min();
    }

    public function isOpen(): bool
    {
        return $this->finished_at === null
            && $this->challenge->status === ContentStatus::Published
            && ($this->deadline() === null || $this->deadline()->isFuture());
    }

    public function includes(Exercise $exercise): bool
    {
        return $this->isOpen() && $this->challenge->exercises()->whereKey($exercise->id)->exists();
    }

    public function imposedDialectId(): ?int
    {
        return null;
    }

    public function recordAnswer(Exercise $exercise, SqlDialect $dialect, ?string $sql, array $choiceIds = []): UserSubmission
    {
        return app(SubmitChallengeAnswer::class)->handle($this, $exercise, $dialect, $sql, $choiceIds);
    }
}
