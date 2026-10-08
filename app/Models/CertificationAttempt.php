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
        'certification_id', 'user_id', 'status', 'score', 'exercise_ids', 'incidents', 'incidents_count',
        'started_at', 'expires_at', 'completed_at', 'closed_reason', 'certificate_code', 'issued_at',
    ];

    /** Incidents de surveillance comptés (les autres sont seulement journalisés). */
    public const COUNTED_INCIDENTS = ['fullscreen_exit', 'tab_hidden', 'window_blur', 'page_reload'];

    /** Incidents journalisés : bloqués côté navigateur, sans pénalité. */
    public const LOGGED_INCIDENTS = ['opened', 'copy_blocked', 'paste_blocked', 'drop_blocked', 'context_menu'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'exercise_ids' => 'array',
            'incidents' => 'array',
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

    /**
     * Épreuve surveillée en cours : plein écran, copier-coller bloqué, reste de l'application fermé.
     */
    public function isSecureExam(): bool
    {
        return $this->status === AttemptStatus::InProgress
            && $this->expires_at->isFuture()
            && $this->certification->exam_mode;
    }

    public static function incidentLabel(string $type): string
    {
        return match ($type) {
            'fullscreen_exit' => 'Sortie du plein écran',
            'tab_hidden' => 'Changement d\'onglet ou fenêtre réduite',
            'window_blur' => 'Fenêtre quittée (autre application)',
            'page_reload' => 'Page rechargée ou rouverte',
            'opened' => 'Épreuve ouverte',
            'copy_blocked' => 'Copie bloquée',
            'paste_blocked' => 'Collage bloqué',
            'drop_blocked' => 'Glisser-déposer bloqué',
            'context_menu' => 'Menu contextuel bloqué',
            default => $type,
        };
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
