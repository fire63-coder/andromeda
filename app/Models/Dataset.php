<?php

namespace App\Models;

use App\Enums\DatasetFormat;
use App\Enums\DatasetStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dataset extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description', 'domain', 'source_format', 'source_dialect_id',
        'schema_diagram', 'tables_meta', 'total_rows', 'size_bytes', 'checksum',
        'status', 'is_public', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_format' => DatasetFormat::class,
            'status' => DatasetStatus::class,
            'tables_meta' => 'array',
            'is_public' => 'boolean',
        ];
    }

    public function sourceDialect(): BelongsTo
    {
        return $this->belongsTo(SqlDialect::class, 'source_dialect_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function builds(): HasMany
    {
        return $this->hasMany(DatasetBuild::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(DatasetImport::class);
    }

    public function exercises(): BelongsToMany
    {
        return $this->belongsToMany(Exercise::class)
            ->withPivot(['role', 'position'])
            ->withTimestamps();
    }

    public function buildFor(SqlDialect $dialect): ?DatasetBuild
    {
        return $this->builds()->where('sql_dialect_id', $dialect->id)->first();
    }
}
