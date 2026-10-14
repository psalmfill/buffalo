/** The configurable colours (Phase 11A.3). */
export type ThemeMode = 'light' | 'dark';

export type ModeColours = Record<ThemeMode, string>;

export type ThemeColours = {
    brand: { primary: ModeColours; accent: ModeColours };
    chart: Record<ThemeMode, string[]>;
    kpi: Record<string, ModeColours>;
};

export type FixedColourGroup = {
    group: string;
    colours: { name: string; colour: string }[];
};
