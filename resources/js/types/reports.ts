export type ReportTypeValue =
    | 'sitrep'
    | 'weekly'
    | 'monthly'
    | 'incident_list'
    | 'incident'
    | 'lga_profile'
    // Phase 11F.
    | 'state_profile'
    | 'coverage'
    | 'analytics'
    // Phase 11E: list exports and single-record PDFs, started on their pages.
    | 'list'
    | 'flashpoint'
    | 'organization_site'
    | 'arrest'
    | 'threat_report'
    | 'criminal_group'
    | 'person';

export type ReportFormatValue = 'pdf' | 'xlsx' | 'csv';

export type ReportTypeOption = {
    value: ReportTypeValue;
    label: string;
    formats: ReportFormatValue[];
    schedulable: boolean;
};

export type ReportRunStatusValue =
    'queued' | 'running' | 'completed' | 'failed' | 'expired';

export type ReportRunRow = {
    ulid: string;
    reference: string;
    type: { value: ReportTypeValue; label: string };
    format: ReportFormatValue;
    status: { value: ReportRunStatusValue; label: string };
    classification: string | null;
    summary: string;
    scheduled: boolean;
    created_at: string;
    completed_at: string | null;
    expires_at: string | null;
    size_bytes: number | null;
    row_count: number | null;
    page_count: number | null;
    error: string | null;
    downloadable: boolean;
};

export type ReportParameters = Record<string, unknown>;

export type ReportPrefill = {
    type: ReportTypeValue;
    parameters: ReportParameters;
    record?: { ulid: string; label: string } | null;
    tabs?: { value: string; label: string }[];
};

export type PlaceOptions = {
    states: { value: string; label: string }[];
    lgas: { value: string; label: string; state: string }[];
    /** States whose profile PDF the user may request (Phase 11F). */
    profileStates: { value: string; label: string }[];
};

export type ReportScheduleRow = {
    ulid: string;
    type: { value: ReportTypeValue; label: string };
    summary: string;
    cadence: 'daily' | 'weekly' | 'monthly';
    time_of_day: string;
    weekday: number | null;
    day_of_month: number | null;
    describe: string;
    is_active: boolean;
    /** Why it is paused (Phase 8B.5); null when active. */
    paused_reason: string | null;
    /** Paused by a posting change: the owner should review it. */
    review: boolean;
    last_run_at: string | null;
    next_run_at: string;
};
