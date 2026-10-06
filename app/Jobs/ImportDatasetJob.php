<?php

namespace App\Jobs;

use App\Models\DatasetImport;
use App\Services\Datasets\DatasetImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportDatasetJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public DatasetImport $import) {}

    public function handle(DatasetImporter $importer): void
    {
        $importer->run($this->import);
    }
}
