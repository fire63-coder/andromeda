<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SqlDialect extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'name', 'version', 'driver', 'editor_mode', 'color',
        'is_sandbox_enabled', 'is_default', 'position',
    ];

    protected $casts = [
        'is_sandbox_enabled' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(Exercise::class);
    }

    public function datasetBuilds(): HasMany
    {
        return $this->hasMany(DatasetBuild::class);
    }

    public function scopeExecutable(Builder $query): Builder
    {
        return $query->where('is_sandbox_enabled', true);
    }
}
