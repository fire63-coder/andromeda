<?php

namespace App\Models;

use App\Actions\Certifications\RecordCertificationAnswer;
use App\Contracts\ExerciseContext;
use App\Enums\AttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CertificationAttempt extends Model implements ExerciseContext
{
    protected $fillable = [
        'certification_id', 'user_id', 'status', 'score', 'exercise_ids',
        'started_at', 'expires_at', 'completed_at', 'certificate_code', 'issued_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'exercise_ids' => 'array',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    public function certification(): BelongsTo
    {
        return $this->belongsTo(Certification::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function submissions(): MorphMany
    {
        return $this->morphMany(UserSubmission::class, 'context');
    }

    public function isExpired(): bool
    {
        return $this->status === AttemptStatus::InProgress && $this->expires_at->isPast();
    }

    public function includes(Exercise $exercise): bool
    {
        return $this->status === AttemptStatus::InProgress && in_array($exercise->id, $this->exercise_ids, true);
    }

    public function imposedDialectId(): ?int
    {
        return $this->certification->sql_dialect_id;
    }

    public function recordAnswer(Exercise $exercise, SqlDialect $dialect, ?string $sql, array $choiceIds = []): UserSubmission
    {
        return app(RecordCertificationAnswer::class)->handle($this, $exercise, $dialect, $sql, $choiceIds);
    }

    public function secondsLeft(): int
    {
        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }
}
