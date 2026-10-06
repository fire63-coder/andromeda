<?php

namespace App\Models;

use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'level_id', 'sql_dialect_id', 'author_id', 'title', 'slug', 'summary', 'description',
        'cover_image_path', 'estimated_minutes', 'position', 'status', 'published_at',
    ];

    protected $casts = [
        'status' => ContentStatus::class,
        'published_at' => 'datetime',
    ];

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function dialect(): BelongsTo
    {
        return $this->belongsTo(SqlDialect::class, 'sql_dialect_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('position');
    }

    public function lessons(): HasManyThrough
    {
        return $this->hasManyThrough(Lesson::class, Chapter::class);
    }

    public function progress(): MorphMany
    {
        return $this->morphMany(UserProgress::class, 'progressable');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ContentStatus::Published);
    }
}
