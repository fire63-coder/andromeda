<?php

namespace App\Models;

use App\Enums\SandboxStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SandboxSession extends Model
{
    protected $fillable = [
        'uuid', 'user_id', 'dataset_id', 'sql_dialect_id', 'resource_name',
        'status', 'queries_count', 'last_used_at', 'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SandboxStatus::class,
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function dialect(): BelongsTo
    {
        return $this->belongsTo(SqlDialect::class, 'sql_dialect_id');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereIn('status', [SandboxStatus::Ready, SandboxStatus::Provisioning])
            ->where('expires_at', '<', now());
    }
}
