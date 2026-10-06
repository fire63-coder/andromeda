<?php

namespace App\Models;

use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Certification extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'level_id', 'sql_dialect_id', 'title', 'slug', 'description', 'passing_score',
        'duration_minutes', 'exercises_count', 'max_attempts', 'cooldown_hours', 'xp_reward', 'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
        ];
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function dialect(): BelongsTo
    {
        return $this->belongsTo(SqlDialect::class, 'sql_dialect_id');
    }

    public function exercisePool(): BelongsToMany
    {
        return $this->belongsToMany(Exercise::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CertificationAttempt::class);
    }
}
