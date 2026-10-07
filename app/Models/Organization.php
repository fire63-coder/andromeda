<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'owner_id', 'invite_code'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['role', 'joined_at']);
    }

    public function challenges(): HasMany
    {
        return $this->hasMany(Challenge::class);
    }

    public static function generateInviteCode(): string
    {
        do {
            $code = Str::upper(Str::random(4).'-'.Str::random(4));
        } while (static::where('invite_code', $code)->exists());

        return $code;
    }
}
