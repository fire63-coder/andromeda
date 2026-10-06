<?php

namespace App\Models;

use App\Enums\AttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CertificationAttempt extends Model
{
    protected $fillable = [
        'certification_id', 'user_id', 'status', 'score', 'exercise_ids',
        'started_at', 'expires_at', 'completed_at', 'certificate_code', 'issued_at',
    ];

    protected $casts = [
        'status' => AttemptStatus::class,
        'exercise_ids' => 'array',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'issued_at' => 'datetime',
    ];

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
}
