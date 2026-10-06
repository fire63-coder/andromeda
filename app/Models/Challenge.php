<?php

namespace App\Models;

use App\Enums\ChallengeType;
use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Challenge extends Model
{
    use HasFactory;

    protected $fillable = [
        'type', 'title', 'slug', 'description', 'organization_id', 'starts_at', 'ends_at',
        'duration_seconds', 'xp_multiplier', 'status', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ChallengeType::class,
            'status' => ContentStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'xp_multiplier' => 'decimal:2',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function exercises(): BelongsToMany
    {
        return $this->belongsToMany(Exercise::class)
            ->withPivot(['points', 'position'])
            ->orderByPivot('position');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(ChallengeParticipation::class);
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', ContentStatus::Published)
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
