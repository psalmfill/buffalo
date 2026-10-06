<?php

namespace App\Console\Commands;

use App\Support\Security\RouteInventory;
use Illuminate\Console\Command;

/**
 * Writes docs/security/route-inventory.md (Phase 8B.6): every route, its
 * methods, middleware and how it is authenticated and authorised. Run it
 * after changing routes; RouteInventoryTest fails while it is out of date.
 */
class RouteInventoryCommand extends Command
{
    protected $signature = 'buffalo:security:route-inventory {--check : Fail if the file is out of date instead of writing it}';

    protected $description = 'Regenerate docs/security/route-inventory.md';

    public const PATH = 'docs/security/route-inventory.md';

    public function handle(RouteInventory $inventory): int
    {
        $rows = $inventory->rows();
        $markdown = RouteInventory::markdown($rows);
        $path = base_path(self::PATH);
        $unprotected = array_filter($rows, fn (array $row) => $row['authorisation'] === null || $row['authentication'] === 'none');

        if ($this->option('check')) {
            $current = is_file($path) ? (string) file_get_contents($path) : '';
            if ($current !== $markdown) {
                $this->components->error(self::PATH.' is out of date: run php artisan buffalo:security:route-inventory');

                return self::FAILURE;
            }
        } else {
            file_put_contents($path, $markdown);
            $this->components->info(count($rows).' routes written to '.self::PATH.'.');
        }

        foreach ($unprotected as $row) {
            $this->components->warn("No authentication or authorisation detected: {$row['method']} {$row['uri']}");
        }

        return $unprotected === [] ? self::SUCCESS : self::FAILURE;
    }
}
