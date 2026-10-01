// Incident evidence (Phase 5B). Identified by ULID only; files are fetched
// through the evidence routes, never by storage URL.

import type { ClassificationLevel } from '@/types/auth';
import type { Option } from '@/types/local-government';

export type EvidenceKind = 'image' | 'document' | 'video' | 'audio';

export type EvidenceItem = {
    ulid: string;
    title: string;
    description: string | null;
    type: { value: EvidenceKind; label: string };
    mime_type: string;
    original_filename: string;
    size_bytes: number;
    sha256: string;
    captured_at: string | null;
    /** For a datetime-local input (Africa/Lagos). */
    captured_at_local: string | null;
    latitude: number | null;
    longitude: number | null;
    camera: string | null;
    exif_time: string | null;
    classification: { value: ClassificationLevel; label: string };
    /** A large upload still being verified on the queue. */
    processing: boolean;
    has_thumb: boolean;
    has_preview: boolean;
    playable: boolean;
    uploaded_by: string | null;
    uploaded_at: string | null;
    can: {
        update: boolean;
        download: boolean;
        verify: boolean;
        delete: boolean;
    };
};

export type EvidenceProps = {
    items: EvidenceItem[];
    levels: Option[];
    extensions: string[];
    max_mb: Record<EvidenceKind, number>;
    can_upload: boolean;
};

export type CustodyEntry = {
    at: string | null;
    who: string | null;
    event: string;
    label: string;
    detail: string | null;
    ip: string | null;
};
