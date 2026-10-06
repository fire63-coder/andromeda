<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExerciseChoice extends Model
{
    protected $fillable = ['exercise_id', 'body', 'is_correct', 'explanation', 'position'];

    protected $hidden = ['is_correct', 'explanation'];

    protected $casts = [
        'is_correct' => 'boolean',
    ];

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
