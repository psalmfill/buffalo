<?php

namespace App\Console\Commands;

use App\Actions\Auth\SetUserPassword;
use App\Actions\Users\CreateUser;
use App\Actions\Users\UserJurisdiction;
use App\Enums\Role;
use App\Models\User;
use App\Support\Auth\PasswordRules;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Creates a super admin interactively. The only supported way to create the
 * first account: no seeder ever creates users with known credentials.
 * The password is always prompted (hidden), never taken from arguments.
 */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'buffalo:create-super-admin
        {--name= : Full name (prompted if omitted)}
        {--email= : Email address (prompted if omitted)}';

    protected $description = 'Create a super admin account (password is prompted, hidden)';

    public function handle(CreateUser $createUser, SetUserPassword $setPassword): int
    {
        // Roles and permissions must exist, also on a fresh production database.
        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $name = $this->stringOption('name') ?? (string) $this->ask('Full name');
        $email = strtolower(trim($this->stringOption('email') ?? (string) $this->ask('Email address')));

        if (User::query()->withTrashed()->where('email', $email)->exists()) {
            $this->components->error("A user with the email address {$email} already exists. Nothing was created.");

            return self::FAILURE;
        }

        $this->line('Password requirements: '.implode('; ', PasswordRules::descriptions()).'.');

        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => PasswordRules::rules(),
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        try {
            $user = $createUser(['name' => $name, 'email' => $email], new UserJurisdiction(Role::SuperAdmin));
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            return self::FAILURE;
        }

        $setPassword($user, $password);

        $this->components->info("Super admin {$user->email} created.");
        $this->components->warn('They must enrol in two-factor authentication at first login.');

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
