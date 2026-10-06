<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rank extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name', 'min_xp', 'icon', 'color'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public static function forXp(int $xp): ?self
    {
        return static::query()->where('min_xp', '<=', $xp)->orderByDesc('min_xp')->first();
    }
}
