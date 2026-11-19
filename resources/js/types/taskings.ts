import type { ClassificationLevel } from '@/types/auth';
import type { ReportAttachment } from '@/types/coverage';

/** Taskings (Phase 11H). */

export type TaskingOption = { value: string; label: string };

export type TaskingProgress = {
    total: number;
    read: number;
    acknowledged: number;
    completed: number;
    overdue: number;
    /** "12 of 31 read · 5 acknowledged · 2 completed · 3 overdue" */
    line: string;
};

/** An LGA chief's own LGA's row (never other LGAs'). */
export type TaskingMine = {
    ulid: string;
    status: TaskingOption;
    unread: boolean;
    overdue: boolean;
};

export type TaskingRow = {
    ulid: string;
    reference: string | null;
    title: string;
    has_title: boolean;
    first_line: string;
    priority: TaskingOption;
    status: TaskingOption;
    /** Never sent to LGA chiefs. */
    target_mode: TaskingOption | null;
    state: string | null;
    classification: { value: ClassificationLevel; label: string };
    sent_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    response_due_at: string | null;
    /** Directors and national users only. */
    progress: TaskingProgress | null;
    /** LGA chiefs only. */
    mine: TaskingMine | null;
};

export type TaskingDetail = TaskingRow & {
    title_input: string | null;
    body: string;
    body_raw: string | null;
    response_due_local: string | null;
    sent_by: string | null;
    created_by: string | null;
    recall: {
        by: string | null;
        at: string | null;
        reason: string | null;
    } | null;
    is_past_due: boolean;
};

export type TaskingNote = {
    ulid: string;
    body: string;
    by: string | null;
    at: string;
};

/** A row of the directors' recipients table. */
export type TaskingRecipientRow = {
    ulid: string;
    lga: { ulid: string; name: string };
    district: string | null;
    chief: string | null;
    status: TaskingOption;
    read_at: string | null;
    acknowledged_at: string | null;
    completed_at: string | null;
    overdue: boolean;
};

/** One LGA's row in full: its report back and further responses. */
export type TaskingRecipientDetail = {
    ulid: string;
    lga: { ulid: string; name: string };
    status: TaskingOption;
    read: { at: string | null; by: string | null };
    acknowledged: { at: string | null; by: string | null };
    completed: { at: string | null; by: string | null };
    response: string;
    overdue: boolean;
    responses: { ulid: string; body: string; by: string | null; at: string }[];
    attachments: ReportAttachment[];
};

export type TaskingLimits = {
    maxCharacters: number;
    responseMaxCharacters: number;
    noteMaxCharacters: number;
    maxAttachments: number;
    imageMb: number;
    videoMb: number;
    documentMb: number;
};

export type TaskingOptions = {
    priorities: TaskingOption[];
    statuses: TaskingOption[];
    targetModes: TaskingOption[];
    recipientStatuses: TaskingOption[];
};

/** An LGA in the recipient picker. */
export type PickerLga = {
    ulid: string;
    name: string;
    /** The senatorial district's ULID. */
    district: string | null;
    chief: string | null;
};

export type DirectorTaskingCard = {
    canCreate: boolean;
    latest: TaskingRow[];
    drafts: number;
};

export type LgaTaskingInbox = {
    unread: number;
    latest: TaskingRow[];
};
