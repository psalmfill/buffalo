import { Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { PickerLga } from '@/types/taskings';

/**
 * The one recipient picker for taskings (Phase 11H): every active LGA of
 * the director's state with its current chief. Tick one LGA, several, use
 * "Select by senatorial district" (adds that district's LGAs; can be used
 * again), or "Select all", which always means the WHOLE state, never just
 * the rows a search shows. The server checks every LGA again.
 */
export default function RecipientPicker({
    lgas,
    districts,
    selected,
    onChange,
    error,
}: {
    lgas: PickerLga[];
    districts: { ulid: string; name: string }[];
    selected: string[];
    onChange: (ulids: string[]) => void;
    error?: string;
}) {
    const [query, setQuery] = useState('');
    const [district, setDistrict] = useState('');
    const chosen = useMemo(() => new Set(selected), [selected]);
    const total = lgas.length;
    const count = lgas.filter((l) => chosen.has(l.ulid)).length;
    const all = total > 0 && count === total;
    const visible = useMemo(() => {
        const q = query.trim().toLowerCase();

        return q === ''
            ? lgas
            : lgas.filter(
                  (l) =>
                      l.name.toLowerCase().includes(q) ||
                      (l.chief ?? '').toLowerCase().includes(q),
              );
    }, [lgas, query]);

    const set = (next: Set<string>) =>
        // Keep the state's order (alphabetical).
        onChange(lgas.filter((l) => next.has(l.ulid)).map((l) => l.ulid));

    const toggle = (ulid: string, on: boolean) => {
        const next = new Set(chosen);

        if (on) {
            next.add(ulid);
        } else {
            next.delete(ulid);
        }

        set(next);
    };

    const addDistrict = (ulid: string) => {
        if (ulid === '') {
            return;
        }

        const next = new Set(chosen);
        lgas.filter((l) => l.district === ulid).forEach((l) =>
            next.add(l.ulid),
        );
        set(next);
        setDistrict('');
    };

    const chosenLgas = lgas.filter((l) => chosen.has(l.ulid));

    return (
        <fieldset
            className="space-y-3 rounded-lg border p-4"
            data-test="recipient-picker"
            aria-describedby="recipient-count"
        >
            <legend className="px-1 text-sm font-semibold">
                Send to LGA Security Chiefs
            </legend>

            <div className="flex flex-wrap items-center gap-3">
                <label className="flex items-center gap-2 text-sm font-medium">
                    <Checkbox
                        checked={
                            all ? true : count > 0 ? 'indeterminate' : false
                        }
                        onCheckedChange={(value) =>
                            set(
                                value === true
                                    ? new Set(lgas.map((l) => l.ulid))
                                    : new Set(),
                            )
                        }
                        aria-label={`Select all ${total} LGAs`}
                        data-test="select-all"
                    />
                    Select all {total} LGAs
                </label>

                <div className="flex items-center gap-2">
                    <Label htmlFor="district-pick" className="sr-only">
                        Select by senatorial district
                    </Label>
                    <NativeSelect
                        id="district-pick"
                        value={district}
                        onChange={(e) => addDistrict(e.target.value)}
                        data-test="district-pick"
                    >
                        <option value="">Select by senatorial district…</option>
                        {districts.map((d) => (
                            <option key={d.ulid} value={d.ulid}>
                                {d.name}
                            </option>
                        ))}
                    </NativeSelect>
                </div>

                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={count === 0}
                    onClick={() => set(new Set())}
                    data-test="clear-recipients"
                >
                    Clear
                </Button>
            </div>

            <p
                id="recipient-count"
                className="text-sm text-muted-foreground"
                aria-live="polite"
                data-test="recipient-count"
            >
                {count} of {total} LGAs selected
            </p>

            {count > 0 && (
                <ul className="flex flex-wrap gap-1" aria-label="Chosen LGAs">
                    {all ? (
                        <li className="rounded-full bg-primary/10 px-3 py-1 text-xs font-medium">
                            All {total} LGAs
                        </li>
                    ) : (
                        chosenLgas.map((l) => (
                            <li
                                key={l.ulid}
                                className="flex items-center gap-1 rounded-full bg-muted px-2 py-1 text-xs"
                            >
                                {l.name}
                                <button
                                    type="button"
                                    aria-label={`Remove ${l.name}`}
                                    onClick={() => toggle(l.ulid, false)}
                                >
                                    <X className="size-3" />
                                </button>
                            </li>
                        ))
                    )}
                </ul>
            )}

            <div className="relative">
                <Search className="absolute top-2.5 left-2 size-4 text-muted-foreground" />
                <Input
                    type="search"
                    className="pl-8"
                    placeholder="Search LGAs or chiefs"
                    aria-label="Search LGAs"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    data-test="recipient-search"
                />
            </div>

            <ul
                className="max-h-80 divide-y overflow-y-auto rounded-md border"
                aria-label="LGAs"
            >
                {visible.map((l) => (
                    <li key={l.ulid}>
                        <label className="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-muted/50">
                            <Checkbox
                                checked={chosen.has(l.ulid)}
                                onCheckedChange={(value) =>
                                    toggle(l.ulid, value === true)
                                }
                                aria-label={l.name}
                                data-test={`lga-${l.name}`}
                            />
                            <span className="flex-1 font-medium">{l.name}</span>
                            <span
                                className={
                                    l.chief
                                        ? 'text-muted-foreground'
                                        : 'text-amber-700 dark:text-amber-400'
                                }
                            >
                                {l.chief ?? 'No chief assigned'}
                            </span>
                        </label>
                    </li>
                ))}
                {visible.length === 0 && (
                    <li className="px-3 py-2 text-sm text-muted-foreground">
                        No LGA matches “{query}”.
                    </li>
                )}
            </ul>

            {error && <p className="text-sm text-destructive">{error}</p>}
        </fieldset>
    );
}

/**
 * The confirmation sentence: "Send to the Uyo LGA chief", "Send to 3 LGA
 * chiefs: Uyo, Ikot Ekpene, Eket", or "Send to all 31 LGA chiefs in Akwa
 * Ibom".
 */
export function recipientSentence(
    chosen: PickerLga[],
    total: number,
    state: string,
): { text: string; all: boolean } {
    if (chosen.length === 1) {
        return { text: `Send to the ${chosen[0].name} LGA chief`, all: false };
    }

    if (chosen.length === total && total > 1) {
        return {
            text: `Send to all ${total} LGA chiefs in ${state}`,
            all: true,
        };
    }

    return {
        text: `Send to ${chosen.length} LGA chiefs: ${chosen.map((l) => l.name).join(', ')}`,
        all: false,
    };
}
