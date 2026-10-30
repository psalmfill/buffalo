// Administration › Hierarchy (CRUD coverage step).

export type HierarchyCount = { active: number; inactive: number };

export type HierarchyStateRow = {
    ulid: string;
    code: string;
    name: string;
    zone: string;
    capital: string | null;
    districts: HierarchyCount;
    lgas: HierarchyCount;
    created_on_screen: number;
};

export type HierarchyTotals = Record<
    'states' | 'districts' | 'lgas',
    { active: number; expected: number }
>;

export type HierarchyStateSummary = {
    ulid: string;
    code: string;
    name: string;
    capital: string | null;
};

/** A district, LGA or ward. Screen-created entries carry who, when and why. */
export type HierarchyEntry = {
    ulid: string;
    code: string;
    name: string;
    is_active: boolean;
    created_on_screen: boolean;
    created_by: string | null;
    created_at: string | null;
    created_reason: string | null;
};

export type HierarchyDistrictRef = {
    ulid: string;
    name: string;
    is_active: boolean;
};

export type HierarchyDistrict = HierarchyEntry & { lgas_active: number };

export type HierarchyLga = HierarchyEntry & {
    headquarters: string | null;
    district: HierarchyDistrictRef;
    wards_active?: number;
};
