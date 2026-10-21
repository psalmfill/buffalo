<?php

namespace App\Support\Exports\Lists;

use App\Support\Exports\Lists\Exporters as E;

/**
 * The list exporter registry (Phase 11E): one exporter per list page.
 * ListExporterCoverageTest fails when a list page has neither an exporter
 * nor an entry in EXCLUDED with its reason.
 */
final class ListExporters
{
    /** @var list<class-string<ListExporter>> */
    public const CLASSES = [
        E\IncidentsExporter::class,
        E\ArrestsExporter::class,
        E\ThreatReportsExporter::class,
        E\FlashpointsExporter::class,
        E\BlackspotSuggestionsExporter::class,
        E\CoverageReportsExporter::class,
        E\FormationsExporter::class,
        E\OrganizationsExporter::class,
        E\OrganizationSitesExporter::class,
        E\CommunitiesExporter::class,
        E\ReligiousInstitutionsExporter::class,
        E\VulnerableFiguresExporter::class,
        E\SecurityNotesExporter::class,
        E\LgasExporter::class,
        E\SenatorialDistrictsExporter::class,
        E\PoliticalOfficesExporter::class,
        E\AccessRequestsExporter::class,
        E\AccessGrantsExporter::class,
        E\DisseminationsExporter::class,
        E\CriminalGroupsExporter::class,
        E\UsersExporter::class,
        E\PersonsExporter::class,
        E\PersonsOfInterestExporter::class,
        E\CriminalGroupMembersExporter::class,
        E\OrganizationContactsExporter::class,
    ];

    /**
     * List pages deliberately without an exporter, with the reason
     * (index routes of BUFFALO's own controllers).
     *
     * @var array<string, string>
     */
    public const EXCLUDED = [
        'audit.index' => 'The audit log has its own review screens and is never exported in bulk.',
        'login-history.index' => 'Sign-in history is a security audit screen, reviewed in place.',
        'notifications.index' => 'The user\'s own notification inbox, not a list of records.',
        'help.index' => 'Help articles, not records.',
        'analytics.index' => 'Charts, not a list: exported as an analytics report (Phase 7D).',
        'search.index' => 'Global search results across modules: each module\'s own list is exportable.',
        'reports.index' => 'The user\'s own report runs: the export pipeline itself.',
        'reports.schedules.index' => 'The user\'s own report schedules.',
        'reference-data.index' => 'Reference values administered in place (configuration, not intelligence records).',
        'persons.duplicates.index' => 'A review queue of possible duplicate pairs, worked in place.',
        'persons.merge-requests.index' => 'A workflow queue of merge requests, decided in place.',
        'uat.feedback.index' => 'UAT feedback triage (UAT environment only), not intelligence records.',
    ];

    /**
     * @return list<ListExporter>
     */
    public static function all(): array
    {
        return array_map(fn (string $class) => app($class), self::CLASSES);
    }

    public static function find(string $key): ?ListExporter
    {
        foreach (self::all() as $exporter) {
            if ($exporter->key() === $key) {
                return $exporter;
            }
        }

        return null;
    }
}
