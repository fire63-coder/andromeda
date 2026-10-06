<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Level extends Model
{
    use HasFactory;

    protected $fillable = ['position', 'slug', 'name', 'description', 'color', 'icon', 'min_xp_to_unlock'];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class)->orderBy('position');
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(Exercise::class);
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(Certification::class);
    }
}
