<?php

namespace App\Models;

use App\Enums\DatasetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatasetBuild extends Model
{
    protected $fillable = [
        'dataset_id', 'sql_dialect_id', 'schema_sql', 'seed_sql', 'seed_path',
        'status', 'error_message', 'built_at',
    ];

    protected $casts = [
        'status' => DatasetStatus::class,
        'built_at' => 'datetime',
    ];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function dialect(): BelongsTo
    {
        return $this->belongsTo(SqlDialect::class, 'sql_dialect_id');
    }
}
