<?php

namespace App\Models;

use App\Enums\DatasetFormat;
use App\Enums\DatasetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatasetImport extends Model
{
    protected $fillable = [
        'dataset_id', 'user_id', 'format', 'original_filename', 'file_path', 'file_size',
        'options', 'status', 'rows_imported', 'errors', 'started_at', 'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => DatasetFormat::class,
            'status' => DatasetStatus::class,
            'options' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
