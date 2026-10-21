<?php

namespace App\Support\Exports\Lists;

use App\Models\Community;
use App\Models\Lga;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;

/**
 * A list shown as a tab of an LGA page (or a community page): the LGA is a
 * required filter (within the requester's reach), the community optional.
 */
abstract class LgaTabExporter extends ListExporter
{
    public function __construct(protected readonly JurisdictionOptions $options) {}

    /**
     * The tab's own filters.
     *
     * @return array<string, mixed>
     */
    abstract protected function tabRules(User $user): array;

    public function rules(User $user): array
    {
        return [
            'lga' => ['required', 'string', 'size:26'],
            'community' => ['nullable', 'string', 'size:26'],
            ...$this->tabRules($user),
        ];
    }

    /**
     * The LGA (null when out of reach: nothing is exported) and the
     * community, if any.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: Lga|null, 1: Community|null}
     */
    protected function place(User $user, array $filters): array
    {
        $lga = $this->options->lgaQuery($user)->where('ulid', (string) ($filters['lga'] ?? ''))->first();
        $community = $lga !== null && isset($filters['community'])
            ? Community::query()->where('lga_id', $lga->id)->where('ulid', (string) $filters['community'])->first()
            : null;

        return [$lga, $community];
    }
}
