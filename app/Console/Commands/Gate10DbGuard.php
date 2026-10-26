<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Strong, read-only guard. Check the actual PostgreSQL connection, not only .env. */
class Gate10DbGuard extends Command
{
    protected $signature = 'manga:gate10-db-guard {--expected=manga_gate07_test} {--allow-dev}';
    protected $description = 'Read-only confirmation of the isolated Gate10 database connection';

    public function handle(): int
    {
        $expected = (string) $this->option('expected');
        $allowDev = (bool) $this->option('allow-dev');
        $envExpected = $allowDev ? 'local' : 'testing';
        if (! in_array($expected, ['manga_gate07_test', 'manga_gate07_dev'], true)
            || ($allowDev && $expected !== 'manga_gate07_dev')
            || (! $allowDev && $expected !== 'manga_gate07_test')
            || app()->environment() !== $envExpected
            || DB::getDriverName() !== 'pgsql'
            || (string) config('database.connections.pgsql.host') !== '127.0.0.1') {
            $this->error('GATE10_DB_GUARD=FAIL environment/host/connection mismatch');
            return self::FAILURE;
        }
        $actual = DB::selectOne('SELECT current_database() AS name')->name;
        if ($actual !== $expected) {
            $this->error('GATE10_DB_GUARD=FAIL connected to unexpected database');
            return self::FAILURE;
        }
        $this->info('GATE10_DB_GUARD=PASS database='.$actual);
        return self::SUCCESS;
    }
}
