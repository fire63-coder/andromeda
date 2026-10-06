<?php

namespace Database\Seeders;

use App\Models\SqlDialect;
use Illuminate\Database\Seeder;

class SqlDialectSeeder extends Seeder
{
    public function run(): void
    {
        $dialects = [
            ['slug' => 'sqlite', 'name' => 'SQLite (ANSI)', 'version' => '3', 'driver' => 'sqlite', 'editor_mode' => 'sqlite', 'color' => '#0f80cc', 'is_sandbox_enabled' => true, 'is_default' => true],
            ['slug' => 'mysql', 'name' => 'MySQL', 'version' => '8.4', 'driver' => 'mysql', 'editor_mode' => 'mysql', 'color' => '#00758f', 'is_sandbox_enabled' => false],
            ['slug' => 'mariadb', 'name' => 'MariaDB', 'version' => '11', 'driver' => 'mariadb', 'editor_mode' => 'mariadb', 'color' => '#c0765a', 'is_sandbox_enabled' => false],
            ['slug' => 'pgsql', 'name' => 'PostgreSQL', 'version' => '17', 'driver' => 'pgsql', 'editor_mode' => 'pgsql', 'color' => '#336791', 'is_sandbox_enabled' => true],
            ['slug' => 'sqlsrv', 'name' => 'SQL Server (T-SQL)', 'version' => '2022', 'driver' => 'sqlsrv', 'editor_mode' => 'mssql', 'color' => '#cc2927', 'is_sandbox_enabled' => false],
            ['slug' => 'oracle', 'name' => 'Oracle (PL/SQL)', 'version' => '23ai', 'driver' => 'oracle', 'editor_mode' => 'plsql', 'color' => '#f80000', 'is_sandbox_enabled' => false],
        ];

        foreach ($dialects as $position => $dialect) {
            SqlDialect::updateOrCreate(['slug' => $dialect['slug']], $dialect + ['position' => $position]);
        }
    }
}
