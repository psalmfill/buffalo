<?php

namespace Database\Factories;

use App\Enums\JurisdictionLevel;
use App\Enums\Role;
use App\Models\GeopoliticalZone;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Every state produces a valid role/jurisdiction combination:
 *
 *   User::factory()->superAdmin()
 *   User::factory()->directorGeneral()
 *   User::factory()->ddg(zones: [$zone])      // no zones = national
 *   User::factory()->stateDirector($state)    // state optional
 *   User::factory()->deputyStateDirector($state)
 *   User::factory()->lgaChief($lga)           // LGA optional
 *
 * The default state is a system-level user with NO role, which the
 * jurisdiction scope and policies deny everything.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'jurisdiction_level' => JurisdictionLevel::System,
            'state_id' => null,
            'lga_id' => null,
            'is_active' => true,
        ];
    }

    public function superAdmin(): static
    {
        return $this->withRole(Role::SuperAdmin);
    }

    public function directorGeneral(): static
    {
        return $this->withRole(Role::DirectorGeneral);
    }

    /**
     * @param  iterable<GeopoliticalZone>  $zones  empty = national
     */
    public function ddg(iterable $zones = []): static
    {
        return $this->withRole(Role::DeputyDirectorGeneral)
            ->afterCreating(function (User $user) use ($zones): void {
                $ids = collect($zones)->map(fn (GeopoliticalZone $zone): int => $zone->id)->all();
                $user->zones()->sync($ids);
            });
    }

    public function stateDirector(?State $state = null): static
    {
        return $this->withRole(Role::StateDirector, $state);
    }

    public function deputyStateDirector(?State $state = null): static
    {
        return $this->withRole(Role::DeputyStateDirector, $state);
    }

    public function lgaChief(?Lga $lga = null): static
    {
        return $this->withRole(Role::LgaSecurityChief, lga: $lga);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'deactivated_at' => now(),
            'deactivation_reason' => 'Factory: inactive user',
        ]);
    }

    public function locked(): static
    {
        return $this->state(fn (array $attributes) => [
            'locked_until' => now()->addHour(),
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lgaAttributes(Lga $lga): array
    {
        return [
            'jurisdiction_level' => JurisdictionLevel::Lga,
            'state_id' => $lga->state_id,
            'lga_id' => $lga->id,
        ];
    }

    private function withRole(Role $role, ?State $state = null, ?Lga $lga = null): static
    {
        $level = $role->jurisdictionLevel();

        return $this
            ->state(fn (array $attributes) => match ($level) {
                JurisdictionLevel::System, JurisdictionLevel::National => [
                    'jurisdiction_level' => $level,
                    'state_id' => null,
                    'lga_id' => null,
                ],
                JurisdictionLevel::State => [
                    'jurisdiction_level' => $level,
                    'state_id' => $state->id ?? State::factory(),
                    'lga_id' => null,
                ],
                JurisdictionLevel::Lga => $this->lgaAttributes($lga ?? Lga::factory()->create()),
            })
            ->afterCreating(function (User $user) use ($role): void {
                SpatieRole::findOrCreate($role->value, 'web');
                $user->syncRoles([$role->value]);
            });
    }
}
