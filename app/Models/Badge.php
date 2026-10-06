<?php

namespace App\Models;

use App\Enums\BadgeTier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Badge extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'name', 'description', 'icon', 'tier', 'category', 'criteria',
        'xp_bonus', 'is_secret', 'is_active', 'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tier' => BadgeTier::class,
            'criteria' => 'array',
            'is_secret' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['awarded_at', 'context']);
    }
}
