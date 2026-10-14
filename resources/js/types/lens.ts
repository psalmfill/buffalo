/** The state lens (Phase 11A.2): shared with national users only. */
export type LensPlace = { type: 'state' | 'zone'; ulid: string; name: string };

export type Lens = {
    current: LensPlace | null;
    zones: { ulid: string; name: string }[];
    states: { ulid: string; name: string; zone: string | null }[];
};
