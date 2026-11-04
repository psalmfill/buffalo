// Security reports (coverage reports in code; Phase 4A, renamed in 11G).
// Identified by ULID only.

import type { ClassificationLevel } from '@/types/auth';
import type { AgencyRef } from '@/types/formations';
import type { NamedRef, Option } from '@/types/local-government';

export type CoverageStatus = 'draft' | 'submitted' | 'returned' | 'reviewed';
export type CoverageRating = 'adequate' | 'partial' | 'inadequate' | 'critical';

export type CoverageReportRow = {
    ulid: string;
    title: string | null;
    /** The title, or "Security report — [LGA] — [date]". */
    display_title: string;
    report_date: string;
    lga: NamedRef;
    state: NamedRef;
    period_type: Option;
    period_start: string;
    period_end: string;
    period: string;
    overall_rating: { value: CoverageRating; label: string } | null;
    status: { value: CoverageStatus; label: string };
    classification: { value: ClassificationLevel; label: string };
    submitted_at: string | null;
    updated_at: string | null;
};

export type CoverageReportDetail = CoverageReportRow & {
    summary: string | null;
    gaps_identified: string | null;
    recommendations: string | null;
    created_by: { name: string } | null;
    submitted_by: { name: string } | null;
    reviewed_by: { name: string } | null;
    reviewed_at: string | null;
    review_comment: string | null;
    returned_by: { name: string } | null;
    returned_at: string | null;
    return_reason: string | null;
};

export type Deployment = {
    ulid: string;
    agency: AgencyRef;
    formation: NamedRef | null;
    /** A formation is recorded that the viewer cannot see. */
    formation_hidden: boolean;
    personnel_count: number;
    vehicles_count: number | null;
    patrol_frequency: Option;
    areas_covered: string | null;
    remarks: string | null;
    communities: NamedRef[];
};

export type CoverageOptions = {
    periodTypes: Option[];
    ratings: Option[];
    statuses: Option[];
    patrolFrequencies: Option[];
    classifications: Option[];
};

export type CoverageTotals = {
    personnel: number;
    vehicles: number;
    by_agency: {
        agency: AgencyRef;
        deployments: number;
        personnel: number;
        vehicles: number;
    }[];
};

export type UncoveredCommunity = {
    ulid: string;
    name: string;
    latitude: number | null;
    longitude: number | null;
};

export type CoverageComparison = {
    report: { ulid: string; period: string };
    personnel_change: number;
    rating: {
        previous: string | null;
        current: string | null;
        change: 'improved' | 'worsened' | 'unchanged' | 'unknown';
    };
    newly_uncovered: NamedRef[];
};

export type TimelineEntry = {
    event: string;
    label: string;
    by: string | null;
    at: string | null;
    note: string | null;
};

export type SuggestedPeriod = { start: string; end: string; label: string };

/** A photo or video on a security report (Phase 11G). */
export type ReportAttachment = {
    ulid: string;
    caption: string | null;
    type: Option;
    mime_type: string;
    original_filename: string;
    size_bytes: number;
    sha256: string;
    captured_at: string | null;
    captured_at_local: string | null;
    latitude: number | null;
    longitude: number | null;
    classification: { value: ClassificationLevel; label: string };
    processing: boolean;
    scan: Option;
    has_thumb: boolean;
    has_preview: boolean;
    playable: boolean;
    uploaded_by: string | null;
    uploaded_at: string | null;
    can: { update: boolean; download: boolean; delete: boolean };
};

export type ReportLimits = {
    maxCharacters: number;
    maxAttachments: number;
    imageMb: number;
    videoMb: number;
};
