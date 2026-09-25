<?php

use Illuminate\Support\Facades\DB;

test('the database uses PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

test('the required PostgreSQL extensions are installed', function (string $extension) {
    $installed = DB::table('pg_extension')->where('extname', $extension)->exists();

    expect($installed)->toBeTrue("PostgreSQL extension [{$extension}] is not installed.");
})->with(['postgis', 'pg_trgm', 'unaccent']);
