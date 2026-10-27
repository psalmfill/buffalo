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
    // Exports review (Phase 8B.3): who exported, downloaded or printed what.
    case AuditExports = 'audit.exports';

    // System administration (Phase 1)
    case SystemHorizon = 'system.horizon';
    case SystemSettings = 'system.settings';
    // Brand, chart and KPI colours (Phase 11A.3).
    case ThemeManage = 'theme.manage';

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

    // State pages and profiles (Phase 11F): intelligence, limited to the
    // states within reach (an LGA chief: their own state, read-only)
    case StatesView = 'states.view';
    case StateProfilesUpdate = 'state_profiles.update';

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

    // Merging duplicate persons (Phase 10A): candidates for every
    // intelligence role in its own jurisdiction; LGA chiefs and Deputy
    // State Directors request; State Directors and national users execute,
    // undo and dismiss (with step-up).
    case PersonsDuplicatesView = 'persons.duplicates.view';
    case PersonsMergeRequest = 'persons.merge_request';
    case PersonsMerge = 'persons.merge';

    // Political information, persons of interest and criminal groups
    // (Phase 3B). Clearing a POI and deleting need State Director or above.
    case PoliticalView = 'political.view';
    case PoliticalManage = 'political.manage';
    case PoliticalDelete = 'political.delete';
    // Phase 10B: correct an office recorded against the wrong person.
    case PoliticalReassign = 'political.reassign';
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

    // Incident workflow (Phase 5C).
    case IncidentsSubmit = 'incidents.submit';
    case IncidentsVerify = 'incidents.verify';
    case IncidentsInvestigate = 'incidents.investigate';
    case IncidentsClose = 'incidents.close';
    case IncidentsInvalidate = 'incidents.invalidate';
    case IncidentsEscalate = 'incidents.escalate';
    case IncidentsAcknowledge = 'incidents.acknowledge';
    case IncidentsAmend = 'incidents.amend';

    // Incident evidence (Phase 5B).
    case EvidenceUpload = 'evidence.upload';
    case EvidenceDownloadOriginal = 'evidence.download_original';
    case EvidenceVerify = 'evidence.verify';
    case EvidenceDelete = 'evidence.delete';

    // Flashpoints and blackspots (Phase 6A).
    case FlashpointsView = 'flashpoints.view';
    case FlashpointsManage = 'flashpoints.manage';
    case FlashpointsAssess = 'flashpoints.assess';
    case FlashpointsDelete = 'flashpoints.delete';

    // Threat reports (Phase 6B).
    case ThreatsView = 'threats.view';
    case ThreatsCreate = 'threats.create';
    case ThreatsManage = 'threats.manage';
    case ThreatsReactivate = 'threats.reactivate';
    case ThreatsDelete = 'threats.delete';

    // Arrests (Phase 11C): intelligence, always also scoped and classified
    case ArrestsView = 'arrests.view';
    case ArrestsCreate = 'arrests.create';
    case ArrestsManage = 'arrests.manage';
    case ArrestsDelete = 'arrests.delete';

    // Operational map and blackspot suggestions (Phase 6C).
    case MapView = 'map.view';
    case BlackspotsSuggestions = 'blackspots.suggestions';
    case BlackspotsRefresh = 'blackspots.refresh';

    // Dissemination of threat reports and flashpoints (Phase 7A).
    case DisseminationsView = 'disseminations.view';
    case DisseminationsCreate = 'disseminations.create';
    case DisseminationsRequest = 'disseminations.request';
    case DisseminationsRevoke = 'disseminations.revoke';

    // Analytics (Phase 7C).
    case AnalyticsView = 'analytics.view';
    case AnalyticsExport = 'analytics.export';

    // Reports and exports (Phase 7D).
    case ReportsGenerate = 'reports.generate';
    case ReportsSchedule = 'reports.schedule';

    // List exports (Phase 11E): every list page (reports.export_data, the
    // incident list export, folded in); the sensitive lists need the second.
    case ExportsLists = 'exports.lists';
    case ExportsSensitive = 'exports.sensitive';
}
