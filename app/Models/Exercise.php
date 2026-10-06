<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Enums\DatasetRole;
use App\Enums\ExerciseType;
use App\Enums\ValidationStrategy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Exercise extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'lesson_id', 'level_id', 'sql_dialect_id', 'author_id', 'reviewer_id', 'type', 'title', 'slug',
        'statement', 'starter_sql', 'solution_sql', 'expected_result', 'validation_strategy',
        'validation_options', 'hints', 'difficulty', 'time_limit_seconds', 'max_execution_ms',
        'xp_reward', 'position', 'status', 'reviewed_at', 'published_at',
    ];

    /**
     * La solution et le résultat attendu ne doivent jamais partir vers le navigateur
     * (propriétés publiques Livewire, API...).
     */
    protected $hidden = ['solution_sql', 'expected_result'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ExerciseType::class,
            'validation_strategy' => ValidationStrategy::class,
            'status' => ContentStatus::class,
            'expected_result' => 'array',
            'validation_options' => 'array',
            'hints' => 'array',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function choices(): HasMany
    {
        return $this->hasMany(ExerciseChoice::class)->orderBy('position');
    }

    public function datasets(): BelongsToMany
    {
        return $this->belongsToMany(Dataset::class)
            ->withPivot(['role', 'position'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /** Jeu de données présenté à l'élève (schéma affiché, exécution "Run"). */
    public function primaryDataset(): ?Dataset
    {
        return $this->datasets->firstWhere('pivot.role', DatasetRole::Primary->value);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(UserSubmission::class);
    }

    public function progress(): MorphMany
    {
        return $this->morphMany(UserProgress::class, 'progressable');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ContentStatus::Published);
    }

    /**
     * Exercices d'entraînement : publiés et rattachés à une leçon. Les exercices sans leçon
     * sont réservés aux certifications et aux défis (pas d'entraînement préalable possible).
     */
    public function scopePractice(Builder $query): Builder
    {
        return $query->published()->whereNotNull('exercises.lesson_id');
    }

    public function isPractice(): bool
    {
        return $this->status === ContentStatus::Published && $this->lesson_id !== null;
    }
}
