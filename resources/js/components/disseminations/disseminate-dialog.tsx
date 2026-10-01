import { router } from '@inertiajs/react';
import { TriangleAlert, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { Audience, Place, TargetOption } from '@/types/disseminations';

type Mode = 'disseminate' | 'request' | 'onward';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    mode: Mode;
    /** POST target (Wayfinder URL). */
    url: string;
    /** Suggested places the record's area reaches. */
    suggestions: TargetOption[];
    /** Tick the uncovered suggestions on opening (immediate threats). */
    preselect?: boolean;
    /** Every state and LGA, for adding a target by hand (disseminate / request). */
    places?: Place[] | null;
    /** LGAs of the recipient's own state (onward). */
    lgas?: { ulid: string; name: string }[];
    audience: Audience;
    /** The LGA chief's request being actioned. */
    requestUlid?: string;
    initialTargets?: TargetOption[];
};

const TITLES: Record<Mode, string> = {
    disseminate: 'Share with other states or LGAs',
    request: 'Request dissemination',
    onward: 'Forward to LGAs of your state',
};

const DESCRIPTIONS: Record<Mode, string> = {
    disseminate:
        'Recipients get read-only access and a notification. They see linked records only if they could already see them.',
    request:
        'The State Director and Deputy are asked to share this record with the places you suggest.',
    onward: 'The LGA chiefs get read-only access until the original sharing ends.',
};

const key = (t: { level: string; ulid: string }) => `${t.level}:${t.ulid}`;

/**
 * Choose targets and give a reason (Phase 7A): disseminate, request
 * dissemination (LGA chief) or forward onward (recipient state). Warns
 * when some officers of a target cannot see the record at its
 * classification, which is a hard ceiling.
 */
export default function DisseminateDialog({
    open,
    onOpenChange,
    mode,
    url,
    suggestions,
    preselect = false,
    places,
    lgas,
    audience,
    requestUlid,
    initialTargets,
}: Props) {
    const initial = useMemo(() => {
        if (initialTargets) {
            return initialTargets;
        }

        return preselect ? suggestions.filter((s) => !s.covered) : [];
    }, [initialTargets, preselect, suggestions]);
    const [selected, setSelected] = useState<TargetOption[]>(initial);
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [state, setState] = useState('');
    const [lga, setLga] = useState('');

    const chosen = new Set(selected.map(key));
    const toggle = (t: TargetOption, on: boolean) =>
        setSelected((list) =>
            on ? [...list, t] : list.filter((x) => key(x) !== key(t)),
        );
    const statePlace = places?.find((p) => p.ulid === state);

    const add = () => {
        if (mode === 'onward') {
            const found = lgas?.find((l) => l.ulid === lga);

            if (found) {
                toggle(
                    {
                        level: 'lga',
                        ulid: found.ulid,
                        name: `${found.name} LGA`,
                        state: '',
                    },
                    true,
                );
            }
        } else if (statePlace) {
            const found = statePlace.lgas.find((l) => l.ulid === lga);
            toggle(
                found
                    ? {
                          level: 'lga',
                          ulid: found.ulid,
                          name: `${found.name} LGA, ${statePlace.name}`,
                          state: statePlace.name,
                      }
                    : {
                          level: 'state',
                          ulid: statePlace.ulid,
                          name: `${statePlace.name} State`,
                          state: statePlace.name,
                      },
                true,
            );
        }

        setLga('');
    };

    const canAdd =
        mode === 'onward'
            ? lga !== '' && !chosen.has(`lga:${lga}`)
            : statePlace !== undefined &&
              (lga !== ''
                  ? !chosen.has(`lga:${lga}`)
                  : statePlace.selectable &&
                    !chosen.has(`state:${statePlace.ulid}`));

    const hidden = (level: 'state' | 'lga') => audience.hidden[level];
    const warnings = selected
        .filter((t) => hidden(t.level).length > 0)
        .map((t) => ({
            name: t.name,
            hidden: hidden(t.level),
            visible: audience[t.level],
        }));

    const submit = () => {
        router.post(
            url,
            {
                targets: selected.map((t) => ({
                    level: t.level,
                    ulid: t.ulid,
                })),
                reason,
                ...(requestUlid ? { request: requestUlid } : {}),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (e) => setErrors(e),
                onSuccess: () => {
                    setErrors({});
                    setReason('');
                    onOpenChange(false);
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{TITLES[mode]}</DialogTitle>
                    <DialogDescription>{DESCRIPTIONS[mode]}</DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    {suggestions.length > 0 && (
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium">
                                The area reaches
                            </legend>
                            {suggestions.map((s) => (
                                <label
                                    key={key(s)}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <Checkbox
                                        checked={chosen.has(key(s))}
                                        disabled={s.covered}
                                        onCheckedChange={(v) =>
                                            toggle(s, v === true)
                                        }
                                    />
                                    <span>{s.name}</span>
                                    {s.covered && (
                                        <span className="text-xs text-muted-foreground">
                                            already shared
                                        </span>
                                    )}
                                </label>
                            ))}
                        </fieldset>
                    )}

                    {(places || lgas) && (
                        <div className="space-y-2">
                            <Label>Add a place</Label>
                            <div className="flex flex-wrap gap-2">
                                {mode !== 'onward' && places && (
                                    <NativeSelect
                                        aria-label="State"
                                        className="w-auto min-w-40"
                                        value={state}
                                        onChange={(e) => {
                                            setState(e.target.value);
                                            setLga('');
                                        }}
                                    >
                                        <option value="">State…</option>
                                        {places.map((p) => (
                                            <option key={p.ulid} value={p.ulid}>
                                                {p.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                )}
                                {(mode === 'onward'
                                    ? lgas
                                    : statePlace?.lgas) && (
                                    <NativeSelect
                                        aria-label="LGA"
                                        className="w-auto min-w-40"
                                        value={lga}
                                        onChange={(e) => setLga(e.target.value)}
                                    >
                                        <option value="">
                                            {mode === 'onward'
                                                ? 'LGA…'
                                                : 'Whole state'}
                                        </option>
                                        {(mode === 'onward'
                                            ? lgas
                                            : statePlace?.lgas
                                        )?.map((l) => (
                                            <option key={l.ulid} value={l.ulid}>
                                                {l.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                )}
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={!canAdd}
                                    onClick={add}
                                >
                                    Add
                                </Button>
                            </div>
                        </div>
                    )}

                    {selected.length > 0 && (
                        <ul
                            className="flex flex-wrap gap-2"
                            aria-label="Chosen places"
                        >
                            {selected.map((t) => (
                                <li
                                    key={key(t)}
                                    className="flex items-center gap-1 rounded-md border px-2 py-1 text-sm"
                                >
                                    {t.name}
                                    <button
                                        type="button"
                                        aria-label={`Remove ${t.name}`}
                                        onClick={() => toggle(t, false)}
                                    >
                                        <X className="size-3" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <InputError message={errors.targets} />

                    {warnings.length > 0 && (
                        <div
                            role="alert"
                            className="space-y-1 rounded-md border border-amber-500/50 bg-amber-50 p-3 text-sm dark:bg-amber-950/30"
                        >
                            <p className="flex items-center gap-2 font-medium">
                                <TriangleAlert className="size-4" />
                                Some officers will not see this record
                            </p>
                            {warnings.map((w) => (
                                <p key={w.name}>
                                    {w.name}: the {w.hidden.join(' and ')}{' '}
                                    {w.hidden.length === 1 ? 'is' : 'are'} not
                                    cleared for its classification.{' '}
                                    {w.visible.length > 0
                                        ? `Only the ${w.visible.join(' and ')} can see it.`
                                        : 'Nobody there can see it.'}
                                </p>
                            ))}
                        </div>
                    )}

                    <div className="space-y-2">
                        <Label htmlFor="dissemination-reason">Reason</Label>
                        <Textarea
                            id="dissemination-reason"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder="Why these places need to know (at least 20 characters)."
                            rows={3}
                        />
                        <InputError message={errors.reason} />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={
                            processing ||
                            selected.length === 0 ||
                            reason.trim().length < 20
                        }
                    >
                        {mode === 'request'
                            ? 'Send request'
                            : mode === 'onward'
                              ? 'Forward'
                              : 'Share'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
