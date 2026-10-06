<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;

/**
 * Isole les bases SQLite des tests de celles du poste de développement.
 */
trait UsesSandbox
{
    protected function setUpSandbox(): void
    {
        $path = storage_path('framework/testing/sandbox-'.getmypid());
        File::ensureDirectoryExists($path);
        config(['sandbox.drivers.sqlite.path' => $path]);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($path));
    }
}
