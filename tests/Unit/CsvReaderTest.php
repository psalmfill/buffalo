<?php

use App\Support\Csv\CsvReader;

function csvFixture(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'csv').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

test('it strips a BOM, trims values and keys rows by header', function () {
    $file = (new CsvReader)->read(csvFixture("\u{FEFF}code, name \nAB ,  Alpha \n"), ['code', 'name']);

    expect($file->errors)->toBe([])
        ->and($file->rows)->toBe([['line' => 2, 'values' => ['code' => 'AB', 'name' => 'Alpha']]]);
});

test('header columns may be in any order', function () {
    $file = (new CsvReader)->read(csvFixture("name,code\nAlpha,AB\n"), ['code', 'name']);

    expect($file->rows[0]['values'])->toBe(['name' => 'Alpha', 'code' => 'AB']);
});

test('it reports physical line numbers across blank lines, CRLF and quoted line breaks', function () {
    $csv = "code,name\r\nAA,One\r\n\r\nBB,\"Two, with comma\"\r\nCC,\"Three\r\nspans lines\"\r\nDD,Four,extra\r\nEE,Five\r\n";

    $file = (new CsvReader)->read(csvFixture($csv), ['code', 'name']);

    expect(array_column($file->rows, 'line'))->toBe([2, 4, 5, 8])
        ->and($file->rows[1]['values']['name'])->toBe('Two, with comma')
        ->and($file->rows[2]['values']['name'])->toBe("Three\r\nspans lines")
        ->and($file->errors)->toHaveCount(1)
        ->and($file->errors[0])->toEndWith(':7: expected 2 fields but found 3 (quote values that contain commas).');
});

test('it rejects invalid UTF-8, empty files, missing files and bad headers', function () {
    $reader = new CsvReader;

    expect($reader->read(csvFixture("code\n\xe9\n"), ['code'])->errors[0])->toEndWith(': file is not valid UTF-8.')
        ->and($reader->read(csvFixture("  \n"), ['code'])->errors[0])->toEndWith(': file is empty; a header row is required.')
        ->and($reader->read('/nonexistent/x.csv', ['code'])->errors)->toBe(['x.csv: file not found or not readable.'])
        ->and($reader->read(csvFixture("code,code,other\n"), ['code', 'name'])->errors)->toHaveCount(3);
});
