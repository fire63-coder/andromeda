<?php

namespace App\Models;

use App\Enums\DatasetStatus;
use App\Services\Sandbox\Exceptions\QueryRejected;
use App\Services\Sandbox\Exceptions\SandboxUnavailable;
use App\Services\Sandbox\QueryGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DatasetBuild extends Model
{
    protected $fillable = [
        'dataset_id', 'sql_dialect_id', 'schema_sql', 'seed_sql', 'seed_path',
        'status', 'error_message', 'built_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DatasetStatus::class,
            'built_at' => 'datetime',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function dialect(): BelongsTo
    {
        return $this->belongsTo(SqlDialect::class, 'sql_dialect_id');
    }

    /**
     * Script complet (schéma + données), validé par le QueryGuard : un jeu de données
     * importé ne doit pas pouvoir faire ce qu'une requête d'élève n'a pas le droit de faire.
     *
     * @throws SandboxUnavailable
     */
    public function validatedScript(): string
    {
        $script = implode("\n;\n", array_filter([
            $this->schema_sql,
            $this->seed_sql,
            $this->seed_path ? Storage::get($this->seed_path) : null,
        ]));

        try {
            app(QueryGuard::class)->inspectScript($script);
        } catch (QueryRejected $e) {
            throw new SandboxUnavailable("Le script du jeu de données est refusé : {$e->getMessage()}", previous: $e);
        }

        return $script;
    }
}
