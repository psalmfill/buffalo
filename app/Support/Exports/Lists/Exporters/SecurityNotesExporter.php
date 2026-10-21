<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Http\Presenters\CommunityInformationPresenter;
use App\Models\CommunitySecurityNote;
use App\Models\User;
use App\Support\Exports\Lists\LgaTabExporter;
use App\Support\Exports\Lists\ListColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Community security notes: the LGA (or community) page tab (Phase 2C
 * list, 11E export).
 */
final class SecurityNotesExporter extends LgaTabExporter
{
    public function key(): string
    {
        return 'security_notes';
    }

    public function label(): string
    {
        return 'Community security notes';
    }

    public function page(): string
    {
        return 'lgas.show#security_notes';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', CommunitySecurityNote::class);
    }

    protected function tabRules(User $user): array
    {
        $options = CommunityInformationPresenter::options();

        return [
            'status' => ['nullable', 'string', Rule::in(array_column($options['noteStatuses'], 'value'))],
            'category' => ['nullable', 'string', Rule::in(array_column($options['noteCategories'], 'value'))],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CommunitySecurityNote>
     */
    public function query(User $user, array $filters): Builder
    {
        [$lga, $community] = $this->place($user, $filters);

        return CommunitySecurityNote::query()
            ->when($lga === null, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->where('community_security_notes.lga_id', $lga?->id)
            ->when($community, fn (Builder $query) => $query->where('community_security_notes.community_id', $community?->id))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('community_security_notes.status', $status))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('community_security_notes.category', $category))
            ->orderByRaw("CASE community_security_notes.status WHEN 'active' THEN 0 WHEN 'monitoring' THEN 1 ELSE 2 END")
            ->orderByDesc('community_security_notes.observed_at')
            ->orderBy('community_security_notes.id');
    }

    public function classificationColumn(): string
    {
        return 'community_security_notes.classification';
    }

    public function with(): array
    {
        return ['lga', 'community'];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('title', 'Title', fn (CommunitySecurityNote $n) => $n->title),
            ListColumn::make('category', 'Category', fn (CommunitySecurityNote $n) => $n->category->label()),
            ListColumn::make('status', 'Status', fn (CommunitySecurityNote $n) => $n->status->label()),
            ListColumn::make('community', 'Community', fn (CommunitySecurityNote $n) => $n->community->name),
            ListColumn::make('lga', 'LGA', fn (CommunitySecurityNote $n) => $n->lga->name),
            ListColumn::make('observed_at', 'Observed', fn (CommunitySecurityNote $n) => $n->observed_at->format('Y-m-d')),
            ListColumn::make('resolved_at', 'Resolved', fn (CommunitySecurityNote $n) => $n->resolved_at?->format('Y-m-d')),
            ListColumn::make('description', 'Description', fn (CommunitySecurityNote $n) => self::text($n, 'description')),
            ListColumn::make('classification', 'Classification', fn (CommunitySecurityNote $n) => $n->classificationLevel()->label()),
        ];
    }

    public function defaultColumns(): array
    {
        return ['title', 'category', 'status', 'community', 'observed_at', 'classification'];
    }
}
