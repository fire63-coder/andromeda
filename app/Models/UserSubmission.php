<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class UserSubmission extends Model
{
    protected $fillable = [
        'user_id', 'exercise_id', 'sql_dialect_id', 'context_type', 'context_id', 'query_sql',
        'selected_choice_ids', 'status', 'is_correct', 'score', 'execution_ms', 'rows_returned',
        'result_preview', 'feedback', 'error_message', 'hints_used', 'xp_awarded',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'is_correct' => 'boolean',
            'selected_choice_ids' => 'array',
            'result_preview' => 'array',
            'feedback' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function dialect(): BelongsTo
    {
        return $this->belongsTo(SqlDialect::class, 'sql_dialect_id');
    }

    /** ChallengeParticipation | CertificationAttempt | null */
    public function context(): MorphTo
    {
        return $this->morphTo();
    }
}
