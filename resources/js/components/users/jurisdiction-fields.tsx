import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type { JurisdictionFormOptions } from '@/types/users';

export type JurisdictionValues = {
    role: string;
    state: string;
    lga: string;
    zones: string[];
};

type Props = {
    options: JurisdictionFormOptions;
    values: JurisdictionValues;
    errors: Partial<Record<string, string>>;
    onChange: (values: JurisdictionValues) => void;
};

/**
 * Role, then the jurisdiction fields that role needs: nothing (super admin,
 * DG), optional zones (DDG), a state (state roles), or a state and an LGA
 * (LGA chief). The server validates the combination again.
 */
export default function JurisdictionFields({
    options,
    values,
    errors,
    onChange,
}: Props) {
    const role = options.roles.find((r) => r.value === values.role);
    const level = role?.level;
    const lgas = options.lgas.filter((lga) => lga.state === values.state);

    const set = (patch: Partial<JurisdictionValues>) =>
        onChange({ ...values, ...patch });

    const changeRole = (value: string) => {
        const next = options.roles.find((r) => r.value === value);

        set({
            role: value,
            zones: next?.allowsZones ? values.zones : [],
            state:
                next?.level === 'state' || next?.level === 'lga'
                    ? values.state
                    : '',
            lga: next?.level === 'lga' ? values.lga : '',
        });
    };

    return (
        <div className="grid gap-4">
            <div className="grid gap-2">
                <Label htmlFor="role">Role</Label>
                <NativeSelect
                    id="role"
                    value={values.role}
                    onChange={(event) => changeRole(event.target.value)}
                    required
                >
                    <option value="">Select a role</option>
                    {options.roles.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </NativeSelect>
                <InputError message={errors.role} />
            </div>

            {role?.allowsZones && (
                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">
                        Geopolitical zones{' '}
                        <span className="font-normal text-muted-foreground">
                            (optional; none means national)
                        </span>
                    </legend>
                    <div className="grid grid-cols-2 gap-2">
                        {options.zones.map((zone) => (
                            <label
                                key={zone.ulid}
                                className="flex items-center gap-2 text-sm"
                            >
                                <Checkbox
                                    checked={values.zones.includes(zone.ulid)}
                                    onCheckedChange={(checked) =>
                                        set({
                                            zones: checked
                                                ? [...values.zones, zone.ulid]
                                                : values.zones.filter(
                                                      (z) => z !== zone.ulid,
                                                  ),
                                        })
                                    }
                                />
                                {zone.name}
                            </label>
                        ))}
                    </div>
                    <InputError message={errors.zones} />
                </fieldset>
            )}

            {(level === 'state' || level === 'lga') && (
                <div className="grid gap-2">
                    <Label htmlFor="state">State</Label>
                    <NativeSelect
                        id="state"
                        value={values.state}
                        onChange={(event) =>
                            set({ state: event.target.value, lga: '' })
                        }
                        required
                    >
                        <option value="">Select a state</option>
                        {options.states.map((state) => (
                            <option key={state.ulid} value={state.ulid}>
                                {state.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={errors.state} />
                </div>
            )}

            {level === 'lga' && (
                <div className="grid gap-2">
                    <Label htmlFor="lga">LGA</Label>
                    <NativeSelect
                        id="lga"
                        value={values.lga}
                        onChange={(event) => set({ lga: event.target.value })}
                        disabled={values.state === ''}
                        required
                    >
                        <option value="">
                            {values.state === ''
                                ? 'Select a state first'
                                : 'Select an LGA'}
                        </option>
                        {lgas.map((lga) => (
                            <option key={lga.ulid} value={lga.ulid}>
                                {lga.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={errors.lga} />
                </div>
            )}
        </div>
    );
}
