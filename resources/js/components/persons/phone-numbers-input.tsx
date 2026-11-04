import { Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type PhoneRow = { number: string; label: string; is_primary: boolean };

export const PHONE_LABELS = [
    { value: 'mobile', label: 'Mobile' },
    { value: 'work', label: 'Work' },
    { value: 'home', label: 'Home' },
    { value: 'other', label: 'Other' },
];

/**
 * A person's phone numbers (Phase 11G.4): any number of rows, each with a
 * label, one marked primary. Every number is stored encrypted and matched
 * exactly (blind index) when searching or adding a person.
 */
export default function PhoneNumbersInput({
    value,
    onChange,
    errors,
}: {
    value: PhoneRow[];
    onChange: (rows: PhoneRow[]) => void;
    errors: Record<string, string | undefined>;
}) {
    const update = (index: number, changes: Partial<PhoneRow>) =>
        onChange(
            value.map((row, i) =>
                i === index
                    ? { ...row, ...changes }
                    : changes.is_primary
                      ? { ...row, is_primary: false }
                      : row,
            ),
        );

    return (
        <fieldset
            className="grid content-start gap-2"
            data-test="phone-numbers"
        >
            <legend className="text-sm font-medium">Phone numbers</legend>
            {value.length === 0 && (
                <p className="text-xs text-muted-foreground">None recorded.</p>
            )}
            {value.map((row, index) => (
                <div
                    key={index}
                    className="grid grid-cols-[1fr_auto_auto_auto] items-center gap-2"
                >
                    <Input
                        aria-label={`Phone number ${index + 1}`}
                        inputMode="tel"
                        autoComplete="off"
                        value={row.number}
                        placeholder="0803 123 4567"
                        onChange={(e) =>
                            update(index, { number: e.target.value })
                        }
                    />
                    <NativeSelect
                        aria-label={`Label of phone number ${index + 1}`}
                        value={row.label}
                        onChange={(e) =>
                            update(index, { label: e.target.value })
                        }
                    >
                        {PHONE_LABELS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <Label className="flex items-center gap-1 text-xs font-normal">
                        <input
                            type="radio"
                            name="primary-phone"
                            checked={row.is_primary}
                            onChange={() => update(index, { is_primary: true })}
                        />
                        Primary
                    </Label>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={`Remove phone number ${index + 1}`}
                        onClick={() => {
                            const rest = value.filter((_, i) => i !== index);
                            onChange(
                                row.is_primary && rest.length > 0
                                    ? rest.map((r, i) => ({
                                          ...r,
                                          is_primary: i === 0,
                                      }))
                                    : rest,
                            );
                        }}
                    >
                        <Trash2 />
                    </Button>
                    <div className="col-span-4">
                        <InputError
                            message={
                                errors[`phones.${index}.number`] ??
                                errors[`phones.${index}.label`]
                            }
                        />
                    </div>
                </div>
            ))}
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={value.length >= 10}
                    onClick={() =>
                        onChange([
                            ...value,
                            {
                                number: '',
                                label: 'mobile',
                                is_primary: value.length === 0,
                            },
                        ])
                    }
                >
                    <Plus /> Add phone number
                </Button>
            </div>
            <p className="text-xs text-muted-foreground">
                Nigerian numbers, e.g. 0803 123 4567 or +234 803 123 4567.
            </p>
            <InputError message={errors.phones} />
        </fieldset>
    );
}
