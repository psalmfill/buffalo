import NativeSelect from '@/components/native-select';
import { Label } from '@/components/ui/label';
import type { PlaceOptions } from '@/types/reports';

type Props = {
    options: PlaceOptions;
    state: string;
    lga: string;
    onChange: (place: { state: string; lga: string }) => void;
    /** An LGA is required (LGA profile). */
    lgaOnly?: boolean;
};

/**
 * State and LGA within the viewer's reach (Phase 7D). Empty means the
 * viewer's whole jurisdiction; the server checks every choice again.
 */
export default function PlaceFields({
    options,
    state,
    lga,
    onChange,
    lgaOnly = false,
}: Props) {
    const lgas = options.lgas.filter((l) => state === '' || l.state === state);

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {options.states.length > 0 && (
                <div className="grid gap-2">
                    <Label htmlFor="report-state">State</Label>
                    <NativeSelect
                        id="report-state"
                        value={state}
                        onChange={(e) =>
                            onChange({ state: e.target.value, lga: '' })
                        }
                    >
                        <option value="">
                            {lgaOnly
                                ? 'Any state'
                                : 'All within my jurisdiction'}
                        </option>
                        {options.states.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
            )}
            {lgas.length > 1 || lgaOnly ? (
                <div className="grid gap-2">
                    <Label htmlFor="report-lga">LGA</Label>
                    <NativeSelect
                        id="report-lga"
                        value={lga}
                        onChange={(e) =>
                            onChange({ state, lga: e.target.value })
                        }
                    >
                        <option value="">
                            {lgaOnly ? 'Choose an LGA' : 'All LGAs'}
                        </option>
                        {lgas.map((l) => (
                            <option key={l.value} value={l.value}>
                                {l.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
            ) : null}
        </div>
    );
}
