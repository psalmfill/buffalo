<?php

namespace App\Models\Concerns;

use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use App\Support\Jurisdiction\JurisdictionScope;
use LogicException;

/**
 * Jurisdiction scoping for every model whose records belong to a place
 * (CLAUDE.md section 4). The model must implement JurisdictionScoped.
 *
 * Optional properties on the model:
 *   protected string $jurisdictionStateColumn = 'state_id';
 *   protected ?string $jurisdictionLgaColumn = 'lga_id';   // null: state-level records
 *   protected bool $visibleToSuperAdmin = true;             // default false
 */
trait HasJurisdiction
{
    public static function bootHasJurisdiction(): void
    {
        // Runtime guard for models added later: fail loudly, never unscoped.
        if (! in_array(JurisdictionScoped::class, class_implements(static::class) ?: [], true)) {
            throw new LogicException(static::class.' uses HasJurisdiction but does not implement '.JurisdictionScoped::class.'.');
        }

        static::addGlobalScope(new JurisdictionScope);
    }

    public function jurisdictionStateColumn(): string
    {
        $column = $this->jurisdictionOption('jurisdictionStateColumn');

        return is_string($column) ? $column : 'state_id';
    }

    public function jurisdictionLgaColumn(): ?string
    {
        $options = get_object_vars($this);

        if (array_key_exists('jurisdictionLgaColumn', $options)) {
            return is_string($options['jurisdictionLgaColumn']) ? $options['jurisdictionLgaColumn'] : null;
        }

        return 'lga_id';
    }

    public function isVisibleToSuperAdmin(): bool
    {
        return $this->jurisdictionOption('visibleToSuperAdmin') === true;
    }

    private function jurisdictionOption(string $name): mixed
    {
        return get_object_vars($this)[$name] ?? null;
    }
}
