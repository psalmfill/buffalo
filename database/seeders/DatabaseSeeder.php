<?php

namespace Database\Seeders;

use App\Actions\Hierarchy\ReadHierarchyFiles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Seeds roles, permissions and reference data. No user accounts are ever
 * seeded: the first super admin is created with
 * `php artisan buffalo:create-super-admin`.
 *
 * Model events are left on: HasUlidRouteKey generates ULIDs in `creating`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(ZonesAndStatesSeeder::class);

        if (is_file(database_path('data/'.ReadHierarchyFiles::LGAS_FILE))) {
            $exitCode = Artisan::call('buffalo:import-hierarchy', [], $this->command->getOutput());

            if ($exitCode !== 0) {
                throw new RuntimeException('Hierarchy import failed; see the errors above.');
            }

            return;
        }

        if (app()->environment('local')) {
            $this->call(DevelopmentHierarchySeeder::class);

            return;
        }

        throw new RuntimeException(sprintf(
            'Cannot seed the administrative hierarchy in the "%s" environment: database/data/%s and %s are missing. '
            .'Supply them (format: database/data/README.md). Synthetic data is only used when APP_ENV=local.',
            app()->environment(),
            ReadHierarchyFiles::DISTRICTS_FILE,
            ReadHierarchyFiles::LGAS_FILE,
        ));
    }
}
