<?php

namespace App\Support\Database;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;

/**
 * Schema changes run as buffalo_owner (Phase 8A):
 * - commands that change or inspect the schema run with `pgsql_migrations`
 *   as the default connection when no `--database` is given, so a plain
 *   `php artisan migrate` (or RefreshDatabase's migrate:fresh) uses the
 *   owner role and the application connection never needs DDL rights.
 *   The default is restored when the command finishes. (Setting the
 *   option itself does not work: Symfony re-binds the input in run().);
 * - after migrations run, buffalo_app's table privileges are re-applied
 *   (append-only tables lose UPDATE and DELETE), as the owner.
 */
final class MigrationConnection
{
    public const NAME = 'pgsql_migrations';

    /** @var list<string|null> default connection to restore per running command (null: unchanged) */
    private static array $previous = [];

    public const COMMANDS = [
        'migrate', 'migrate:fresh', 'migrate:install', 'migrate:refresh', 'migrate:reset',
        'migrate:rollback', 'migrate:status', 'db:wipe', 'schema:dump',
    ];

    public static function register(Dispatcher $events): void
    {
        $events->listen(CommandStarting::class, function (CommandStarting $event): void {
            if (! in_array($event->command, self::COMMANDS, true)) {
                return;
            }

            if ($event->input->hasParameterOption('--database') || (string) config('database.connections.'.self::NAME.'.username') === '') {
                self::$previous[] = null;

                return;
            }

            self::$previous[] = DB::getDefaultConnection();
            DB::setDefaultConnection(self::NAME);
        });

        $events->listen(CommandFinished::class, function (CommandFinished $event): void {
            if (in_array($event->command, self::COMMANDS, true) && self::$previous !== []) {
                $previous = array_pop(self::$previous);

                if ($previous !== null) {
                    DB::setDefaultConnection($previous);
                }
            }
        });

        $events->listen(MigrationsEnded::class, function (MigrationsEnded $event): void {
            if ($event->method !== 'up') {
                return;
            }

            $connection = DB::connection(app(Migrator::class)->getConnection());

            if ($connection->getDriverName() === 'pgsql' && $connection->getConfig('username') === config('database.connections.'.self::NAME.'.username')) {
                DatabaseRoles::fromConfig()->applyTablePrivileges($connection);
            }
        });
    }
}
