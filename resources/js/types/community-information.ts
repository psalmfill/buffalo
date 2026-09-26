import type { MapMarker } from '@/components/map/BuffaloMap';
import type { ClassificationLevel } from '@/types/auth';
import type { MapView } from '@/types/dashboard';
import type { NamedRef, Option } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';

export type InformationOptions = {
    institutionTypes: Option[];
    vulnerableCategories: Option[];
    noteCategories: Option[];
    noteStatuses: Option[];
    classifications: Option[];
};

export type InstitutionRow = {
    ulid: string;
    name: string;
    type: Option;
    religion: NamedRef;
    denomination: NamedRef | null;
    community: NamedRef;
    estimated_congregation: number | null;
    latitude: number | null;
    longitude: number | null;
    notes: string | null;
};

export type InstitutionsTab = {
    list: Paginated<InstitutionRow>;
    filters: { religion: string; type: string; community: string };
    summary: {
        total: number;
        byReligion: { label: string; count: number }[];
        byType: { label: string; count: number }[];
    };
    religions: Option[];
    communities: Option[];
    markers: MapMarker[];
    map: MapView;
};

export type Figure = {
    ulid: string;
    category: Option;
    community: NamedRef | null;
    adult_male: number;
    adult_female: number;
    child_male: number;
    child_female: number;
    total: number;
    as_of_date: string;
    source: string;
};

export type FigureTotals = {
    adult_male: number;
    adult_female: number;
    child_male: number;
    child_female: number;
    total: number;
};

export type VulnerableTab = {
    latest: Figure[];
    totals: FigureTotals;
    byCommunity: Figure[];
    history: Figure[] | null;
    historyCategory: string | null;
};

export type NoteRow = {
    ulid: string;
    title: string;
    category: Option;
    status: Option;
    classification: { value: ClassificationLevel; label: string };
    community: NamedRef;
    observed_at: string;
    resolved_at: string | null;
};

export type NoteDetail = NoteRow & {
    description: string;
    lga: NamedRef;
    created_by: string | null;
    created_at: string | null;
    updated_at: string | null;
};

export type NotesTab = {
    list: Paginated<NoteRow>;
    filters: { status: string; category: string };
};

export type InformationCan = {
    addReligiousInstitution: boolean;
    addVulnerableFigures: boolean;
    addSecurityNote: boolean;
    viewReligiousInstitutions: boolean;
    viewVulnerableFigures: boolean;
    viewSecurityNotes: boolean;
    deleteReligiousInstitutions: boolean;
    deleteVulnerableFigures: boolean;
};
