<?php

namespace App\Enums;

/**
 * Every permission name used in the application. Always reference
 * permissions through this enum, never as string literals. Which roles hold
 * which permissions is defined in RolesAndPermissionsSeeder.
 */
enum Permission: string
{
    // Users (Phase 1)
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';

    // Audit (Phase 1)
    case AuditView = 'audit.view';
    case LoginHistoryView = 'login_history.view';

    // System administration (Phase 1)
    case SystemHorizon = 'system.horizon';
    case SystemSettings = 'system.settings';

    // Reference data (Phase 2A): configuration, not intelligence
    case ReferenceDataView = 'reference_data.view';
    case ReferenceDataManage = 'reference_data.manage';

    // LGA profiles and communities (Phase 2B): intelligence, always also
    // limited to the user's jurisdiction
    case LgasView = 'lgas.view';
    case LgaProfilesUpdate = 'lga_profiles.update';
    case CommunitiesView = 'communities.view';
    case CommunitiesManage = 'communities.manage';
    case CommunitiesImport = 'communities.import';
    case CommunitiesDelete = 'communities.delete';

    // Community information (Phase 2C): jurisdiction-scoped; security notes
    // are also classified. Deleting needs State Director or above.
    case ReligiousInstitutionsView = 'religious_institutions.view';
    case ReligiousInstitutionsManage = 'religious_institutions.manage';
    case ReligiousInstitutionsDelete = 'religious_institutions.delete';
    case VulnerableStatsView = 'vulnerable_stats.view';
    case VulnerableStatsManage = 'vulnerable_stats.manage';
    case VulnerableStatsDelete = 'vulnerable_stats.delete';
    case SecurityNotesView = 'security_notes.view';
    case SecurityNotesManage = 'security_notes.manage';
    case SecurityNotesDelete = 'security_notes.delete';

    // Persons (Phase 3A): jurisdiction-scoped and classified; encrypted
    // fields; every view access-logged. Deleting needs State Director or
    // above.
    case PersonsView = 'persons.view';
    case PersonsManage = 'persons.manage';
    case PersonsDelete = 'persons.delete';

    // Political information, persons of interest and criminal groups
    // (Phase 3B). Clearing a POI and deleting need State Director or above.
    case PoliticalView = 'political.view';
    case PoliticalManage = 'political.manage';
    case PoliticalDelete = 'political.delete';
    case PoiView = 'poi.view';
    case PoiManage = 'poi.manage';
    case PoiClear = 'poi.clear';
    case PoiDelete = 'poi.delete';
    case CriminalGroupsView = 'criminal_groups.view';
    case CriminalGroupsManage = 'criminal_groups.manage';
    case CriminalGroupsDelete = 'criminal_groups.delete';

    // Cross-jurisdiction access (Phase 3C). Deputies decide only while
    // holding an active delegation (AccessRequestApprovers).
    case AccessRequestsCreate = 'access_requests.create';
    case AccessRequestsDecide = 'access_requests.decide';
    case AccessRequestsOversee = 'access_requests.oversee';
    case DelegationsManage = 'delegations.manage';

    // Security agencies (Phase 4A): formations and coverage reports.
    case FormationsView = 'formations.view';
    case FormationsManage = 'formations.manage';
    case FormationsDelete = 'formations.delete';
    case CoverageView = 'coverage.view';
    case CoverageCreate = 'coverage.create';
    case CoverageReview = 'coverage.review';

    // Organizations (Phase 4B): the national directory, sites and contacts.
    case OrganizationsView = 'organizations.view';
    case OrganizationsManage = 'organizations.manage';
    case OrganizationsDelete = 'organizations.delete';
    case OrganizationSitesDelete = 'organization_sites.delete';

    // Incidents (Phase 5A). Workflow permissions arrive in 5C.
    case IncidentsView = 'incidents.view';
    case IncidentsCreate = 'incidents.create';
    case IncidentsUpdate = 'incidents.update';

    // Incident evidence (Phase 5B).
    case EvidenceUpload = 'evidence.upload';
    case EvidenceDownloadOriginal = 'evidence.download_original';
    case EvidenceVerify = 'evidence.verify';
    case EvidenceDelete = 'evidence.delete';
}
