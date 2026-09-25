import type { MapMarker } from '@/components/map/BuffaloMap';
import type { MapView } from '@/types/dashboard';
import type { Paginated } from '@/types/pagination';

export type Option = { value: string; label: string };
export type NamedRef = { ulid: string; name: string };

export type LgaRow = {
    ulid: string;
    name: string;
    state: string;
    district: string | null;
    headquarters: string | null;
    communities: number;
    completeness: number;
    last_reviewed_at: string | null;
};

export type LgaFacts = {
    ulid: string;
    name: string;
    code: string;
    state: NamedRef;
    district: string | null;
    headquarters: string | null;
    area_km2: string | null;
    population_estimate: number | null;
    is_active: boolean;
};

export type LgaProfileData = {
    exists: boolean;
    history: string | null;
    economic_activities: string | null;
    terrain: string[];
    major_roads: string | null;
    waterways: string | null;
    borders: string | null;
    general_notes: string | null;
    last_reviewed_at: string | null;
    last_reviewed_by: string | null;
    terrainOptions: Option[];
};

export type Completeness = { percent: number; missing: string[] };

export type CommunityRow = {
    ulid: string;
    name: string;
    other_names: string[];
    type: Option;
    lga: NamedRef;
    state: string;
    ward: string | null;
    population_estimate: number | null;
    has_location: boolean;
};

export type CommunityDetail = CommunityRow & {
    ward_ulid: string | null;
    latitude: number | null;
    longitude: number | null;
    notes: string | null;
    created_at: string | null;
    updated_at: string | null;
};

export type LgaCommunitiesTab = {
    list: Paginated<CommunityRow>;
    filters: { search: string; type: string };
    types: Option[];
    markers: MapMarker[];
    map: MapView;
};

export type ImportRow = {
    line: number;
    name: string;
    type: string;
    ward_code: string;
    latitude: string;
    longitude: string;
    population_estimate: string;
    other_names: string[];
    notes: string;
    status: 'new' | 'duplicate' | 'error';
    errors: string[];
    match: { ulid: string; name: string; similarity: number } | null;
};
