<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Assignment extends Model
{
    protected $fillable = ['organization_id', 'created_by', 'title', 'instructions', 'due_at', 'published_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function exercises(): BelongsToMany
    {
        return $this->belongsToMany(Exercise::class)->withPivot('position')->orderByPivot('position');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null && $this->due_at->isPast();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    /**
     * Devoirs publiés des organisations de l'utilisateur.
     */
    public function scopeFor(Builder $query, User $user): Builder
    {
        return $query->published()->whereIn('organization_id', $user->organizations()->select('organizations.id'));
    }
}
