<?php

namespace App\Models;

use App\Enums\ProgressStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class UserProgress extends Model
{
    protected $table = 'user_progress';

    protected $fillable = [
        'user_id', 'progressable_type', 'progressable_id', 'status', 'progress_percent',
        'attempts_count', 'best_score', 'started_at', 'completed_at', 'last_activity_at',
    ];

    protected $casts = [
        'status' => ProgressStatus::class,
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Course | Lesson | Exercise */
    public function progressable(): MorphTo
    {
        return $this->morphTo();
    }
}
