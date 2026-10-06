<?php

namespace App\Services\Datasets;

use App\Enums\DatasetFormat;
use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Models\DatasetImport;
use App\Models\SqlDialect;
use App\Services\Datasets\Parsers\CsvParser;
use App\Services\Datasets\Parsers\JsonParser;
use App\Services\Datasets\Parsers\SqlDumpParser;
use App\Services\Datasets\Writers\SqlWriter;
use App\Services\Sandbox\Exceptions\SandboxUnavailable;
use App\Services\Sandbox\SandboxManager;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Import d'un jeu de données : lecture des fichiers → jeu canonique → SQL par dialecte
 * → construction et vérification des bases sandbox.
 */
class DatasetImporter
{
    public function __construct(
        private readonly JsonParser $json,
        private readonly SqlDumpParser $dump,
        private readonly DatasetAssembler $assembler,
        private readonly SchemaIntrospector $introspector,
        private readonly SandboxManager $sandbox,
    ) {}

    /**
     * Lit et assemble les fichiers, sans rien enregistrer (aperçu de l'assistant).
     *
     * @param  list<array{path: string, name: string}>  $files  chemins sur le disque « local »
     * @param  array{delimiter?: ?string, has_header?: bool, renames?: array<string, string>}  $options
     *
     * @throws ImportException
     */
    public function analyze(DatasetFormat $format, array $files, array $options = []): AssembledDataset
    {
        if ($files === []) {
            throw new ImportException('Aucun fichier fourni.');
        }

        $warnings = [];

        $tables = match ($format) {
            DatasetFormat::Csv => array_map(function (array $file) use ($options, &$warnings) {
                $parser = new CsvParser;
                $table = $parser->parse(
                    Storage::path($file['path']),
                    pathinfo($file['name'], PATHINFO_FILENAME),
                    $options['delimiter'] ?? null,
                    $options['has_header'] ?? true,
                );
                array_push($warnings, ...$parser->warnings);

                return $table;
            }, $files),
            DatasetFormat::Json => $this->json->parse(Storage::get($files[0]['path']), pathinfo($files[0]['name'], PATHINFO_FILENAME)),
            DatasetFormat::SqlDump => $this->dump->parse(Storage::get($files[0]['path'])),
            DatasetFormat::Builder => throw new ImportException('Format non importable.'),
        };

        $assembled = $this->assembler->assemble($tables, $options['renames'] ?? [], $format === DatasetFormat::SqlDump);

        return new AssembledDataset($assembled->tables, [...$warnings, ...$assembled->warnings]);
    }

    /**
     * Exécute un import enregistré (appelé par ImportDatasetJob).
     */
    public function run(DatasetImport $import): void
    {
        $import->update(['status' => DatasetStatus::Processing, 'started_at' => now()]);
        $dataset = $import->dataset;

        try {
            $assembled = $this->analyze($import->format, $import->options['files'] ?? [], $import->options ?? []);
            $failures = $this->build($dataset, $assembled);

            $import->update([
                'status' => DatasetStatus::Ready,
                'rows_imported' => $assembled->totalRows(),
                'errors' => ['warnings' => $assembled->warnings, 'builds' => $failures] ?: null,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            if (! $e instanceof ImportException) {
                report($e);
            }

            $import->update([
                'status' => DatasetStatus::Failed,
                'errors' => ['message' => $e instanceof ImportException ? $e->getMessage() : 'Erreur interne pendant l\'import.'],
                'finished_at' => now(),
            ]);

            if (! $dataset->builds()->where('status', DatasetStatus::Ready)->exists()) {
                $dataset->update(['status' => DatasetStatus::Failed]);
            }
        }
    }

    /**
     * @return array<string, string> erreurs de construction par dialecte
     */
    private function build(Dataset $dataset, AssembledDataset $assembled): array
    {
        $tables = $assembled->tablesMeta();
        $failures = [];
        $sqlite = null;

        foreach (SqlDialect::whereIn('slug', SqlWriter::DIALECTS)->get() as $dialect) {
            $sql = (new SqlWriter($dialect->slug))->write($assembled);
            $sqlite ??= $dialect->slug === 'sqlite' ? $sql : null;

            $build = $dataset->builds()->updateOrCreate(['sql_dialect_id' => $dialect->id], [
                'schema_sql' => $sql['schema'],
                'seed_sql' => $sql['seed'],
                'seed_path' => null,
                'status' => DatasetStatus::Processing,
                'error_message' => null,
            ]);
            $build->touch(); // nouvelle version → nouvelle base sandbox

            try {
                // Les moteurs actifs construisent la base tout de suite : une erreur est visible à l'import.
                if ($dialect->is_sandbox_enabled && config("sandbox.drivers.{$dialect->slug}")) {
                    $this->sandbox->driver($dialect)->prepare($build);
                }

                $build->update(['status' => DatasetStatus::Ready, 'built_at' => now()]);
            } catch (SandboxUnavailable $e) {
                $failures[$dialect->slug] = $e->getMessage();
                $build->update(['status' => DatasetStatus::Failed, 'error_message' => $e->getMessage()]);
            }
        }

        $dataset->update([
            'tables_meta' => $tables,
            'schema_diagram' => $this->introspector->mermaid($tables),
            'total_rows' => $assembled->totalRows(),
            'size_bytes' => strlen(($sqlite['schema'] ?? '').($sqlite['seed'] ?? '')),
            'checksum' => hash('sha256', ($sqlite['schema'] ?? '').($sqlite['seed'] ?? '')),
            'status' => $dataset->builds()->where('status', DatasetStatus::Ready)->exists() ? DatasetStatus::Ready : DatasetStatus::Failed,
        ]);

        return $failures;
    }
}
