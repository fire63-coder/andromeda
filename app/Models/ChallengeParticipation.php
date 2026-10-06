<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ChallengeParticipation extends Model
{
    protected $fillable = [
        'challenge_id', 'user_id', 'score', 'solved_count', 'total_time_ms',
        'final_rank', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

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
}
