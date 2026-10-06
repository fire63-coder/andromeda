<?php

namespace App\Livewire\Admin\Datasets;

use App\Enums\DatasetFormat;
use App\Enums\DatasetStatus;
use App\Jobs\ImportDatasetJob;
use App\Models\Dataset;
use App\Models\SqlDialect;
use App\Services\Datasets\Column;
use App\Services\Datasets\DatasetAssembler;
use App\Services\Datasets\DatasetImporter;
use App\Services\Datasets\ImportException;
use App\Services\Datasets\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Assistant d'import : 1) fichiers et métadonnées → analyse ; 2) aperçu, renommage des tables → import.
 */
#[Title('Importer un jeu de données')]
class DatasetImportWizard extends Component
{
    use WithFileUploads;

    private const MAX_FILE_KB = 20 * 1024;

    public string $name = '';

    public string $slug = '';

    public string $description = '';

    public string $domain = '';

    public bool $isPublic = true;

    public string $format = 'csv';

    /** @var list<TemporaryUploadedFile> */
    public array $files = [];

    public string $delimiter = 'auto';

    public bool $hasHeader = true;

    /** @var array<string, string> nom de table détecté => nom souhaité */
    public array $renames = [];

    #[Locked]
    public int $step = 1;

    /** Dossier de stockage des fichiers analysés (disque « local »). */
    #[Locked]
    public ?string $token = null;

    /** @var list<array{path: string, name: string}> */
    #[Locked]
    public array $storedFiles = [];

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    public function mount(): void
    {
        $this->authorize('create', Dataset::class);
    }

    public function updatedName(): void
    {
        $this->slug = Str::slug($this->name);
    }

    public function updatedFormat(): void
    {
        $this->reset('files');
    }

    public function analyze(DatasetImporter $importer): void
    {
        $this->validate($this->metadataRules() + [
            'format' => ['required', Rule::in(['csv', 'json', 'sql_dump'])],
            'files' => ['required', 'array', 'min:1', $this->format === 'csv' ? 'max:30' : 'max:1'],
            'files.*' => ['file', 'max:'.self::MAX_FILE_KB, 'extensions:'.$this->extensions()],
        ], attributes: ['files' => 'fichiers', 'files.*' => 'fichier', 'name' => 'nom', 'slug' => 'identifiant']);

        $this->token = (string) Str::uuid();
        $this->storedFiles = array_map(fn (TemporaryUploadedFile $file) => [
            'path' => $file->storeAs("dataset-imports/{$this->token}", Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$file->getClientOriginalExtension(), 'local'),
            'name' => $file->getClientOriginalName(),
        ], $this->files);

        $this->runAnalysis($importer);
    }

    public function reanalyze(DatasetImporter $importer): void
    {
        $this->runAnalysis($importer);
    }

    public function import(): void
    {
        $this->authorize('create', Dataset::class);
        abort_unless($this->step === 2 && $this->storedFiles !== [], 400);
        $this->validate($this->metadataRules());

        $dataset = Dataset::create([
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description ?: null,
            'domain' => $this->domain ?: null,
            'source_format' => DatasetFormat::from($this->format),
            'source_dialect_id' => SqlDialect::where('slug', 'sqlite')->value('id'),
            'status' => DatasetStatus::Processing,
            'is_public' => $this->isPublic,
            'created_by' => auth()->id(),
        ]);

        $import = $dataset->imports()->create([
            'user_id' => auth()->id(),
            'format' => DatasetFormat::from($this->format),
            'original_filename' => implode(', ', array_column($this->storedFiles, 'name')),
            'file_path' => "dataset-imports/{$this->token}",
            'file_size' => array_sum(array_map(fn (array $file) => Storage::size($file['path']), $this->storedFiles)),
            'options' => $this->options(),
            'status' => DatasetStatus::Pending,
        ]);

        ImportDatasetJob::dispatch($import);

        $this->redirectRoute('admin.datasets.show', $dataset, navigate: true);
    }

    public function back(): void
    {
        $this->step = 1;
        $this->preview = null;
        $this->renames = [];
    }

    public function render()
    {
        return view('livewire.admin.datasets.import-wizard');
    }

    private function runAnalysis(DatasetImporter $importer): void
    {
        try {
            $assembled = $importer->analyze(DatasetFormat::from($this->format), $this->storedFiles, $this->options());
        } catch (ImportException $e) {
            $this->addError('files', $e->getMessage());
            $this->step = 1;

            return;
        }

        $this->preview = [
            'total_rows' => $assembled->totalRows(),
            'warnings' => $assembled->warnings,
            'tables' => array_map(fn (Table $table) => [
                'name' => $table->name,
                'rows' => count($table->rows),
                'columns' => array_map(fn (Column $column) => [
                    'name' => $column->name,
                    'type' => DatasetAssembler::displayType($column),
                    'primary' => $column->primary,
                    'references' => $column->references,
                ], $table->columns),
                'sample' => array_map(
                    fn (array $row) => array_map(fn ($value) => is_bool($value) ? ($value ? 'true' : 'false') : $value, $row),
                    array_slice($table->rows, 0, 5),
                ),
            ], $assembled->tables),
        ];

        foreach ($assembled->tables as $table) {
            $this->renames[$table->name] ??= $table->name;
        }

        $this->step = 2;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'files' => $this->storedFiles,
            'delimiter' => $this->delimiter === 'auto' ? null : ($this->delimiter === 'tab' ? "\t" : $this->delimiter),
            'has_header' => $this->hasHeader,
            // Clés = noms nettoyés affichés dans l'aperçu (voir DatasetAssembler::assemble()).
            'renames' => array_filter($this->renames, fn ($new, $old) => $new !== '' && $new !== $old, ARRAY_FILTER_USE_BOTH),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash', 'max:120', Rule::notIn(['importer']), Rule::unique('datasets', 'slug')],
            'description' => ['nullable', 'string', 'max:2000'],
            'domain' => ['nullable', 'string', 'max:50'],
            'delimiter' => ['required', Rule::in(['auto', ',', ';', 'tab', '|'])],
        ];
    }

    private function extensions(): string
    {
        return match ($this->format) {
            'csv' => 'csv,txt,tsv',
            'json' => 'json',
            default => 'sql,txt',
        };
    }
}
