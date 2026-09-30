import { Search, X } from 'lucide-react';
import type { KeyboardEvent } from 'react';
import { useEffect, useId, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { postJson } from '@/lib/http';
import { cn } from '@/lib/utils';

/** A picked entity: its ULID and the label shown, plus endpoint extras. */
export type PickedEntity = {
    value: string;
    label: string;
    meta?: Record<string, string | boolean | null>;
};

type Result = PickedEntity & { secondary: string | null };

type Common = {
    id?: string;
    /** A /pickers/... endpoint URL. */
    endpoint: string;
    /** Extra filters sent with each search (e.g. { agency: ulid }). */
    params?: Record<string, string | null | undefined>;
    placeholder?: string;
    /** Characters needed before searching (0: list on focus). */
    minChars?: number;
    disabled?: boolean;
    'aria-invalid'?: boolean;
};

type Props = Common &
    (
        | {
              multiple?: false;
              value: PickedEntity | null;
              onChange: (value: PickedEntity | null) => void;
          }
        | {
              multiple: true;
              value: PickedEntity[];
              onChange: (value: PickedEntity[]) => void;
          }
    );

/**
 * Searches an entity endpoint as the user types (debounced, POSTed), with
 * keyboard navigation (arrows, Enter, Escape). Single or multiple
 * selection; selected values show as chips with a clear button. Values are
 * ULIDs. The server decides what the user may see.
 */
export default function EntityPicker(props: Props) {
    const {
        id,
        endpoint,
        params,
        placeholder = 'Type to search',
        minChars = 0,
        disabled = false,
    } = props;
    const listId = useId();
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [results, setResults] = useState<Result[]>([]);
    const [searching, setSearching] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [active, setActive] = useState(0);
    const latest = useRef(0);
    const paramsKey = JSON.stringify(params ?? {});

    const selected: PickedEntity[] = props.multiple
        ? props.value
        : props.value
          ? [props.value]
          : [];

    useEffect(() => {
        const term = query.trim();

        if (!open || term.length < minChars) {
            return;
        }

        const request = ++latest.current;
        const timer = window.setTimeout(() => {
            setSearching(true);
            postJson<{ results: Result[] }>(endpoint, {
                ...(JSON.parse(paramsKey) as Record<string, unknown>),
                q: term,
            })
                .then((data) => {
                    if (request === latest.current) {
                        setResults(data.results);
                        setActive(0);
                        setError(null);
                    }
                })
                .catch(() => {
                    if (request === latest.current) {
                        setError('The search failed. Try again.');
                    }
                })
                .finally(() => {
                    if (request === latest.current) {
                        setSearching(false);
                    }
                });
        }, 250);

        return () => window.clearTimeout(timer);
    }, [query, open, endpoint, paramsKey, minChars]);

    const choose = (result: Result) => {
        const picked: PickedEntity = {
            value: result.value,
            label: result.label,
            meta: result.meta,
        };

        if (props.multiple) {
            if (!props.value.some((v) => v.value === picked.value)) {
                props.onChange([...props.value, picked]);
            }
        } else {
            props.onChange(picked);
            setOpen(false);
        }

        setQuery('');
    };

    const remove = (value: string) => {
        if (props.multiple) {
            props.onChange(props.value.filter((v) => v.value !== value));
        } else {
            props.onChange(null);
        }
    };

    const visible = results.filter(
        (r) => !selected.some((s) => s.value === r.value),
    );

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setOpen(true);
            setActive((i) => Math.min(i + 1, visible.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((i) => Math.max(i - 1, 0));
        } else if (event.key === 'Enter' && open && visible[active]) {
            event.preventDefault();
            choose(visible[active]);
        } else if (event.key === 'Escape') {
            setOpen(false);
        }
    };

    const showInput = props.multiple || props.value === null;
    const showList = open && query.trim().length >= minChars;

    return (
        <div className="space-y-1">
            {selected.length > 0 && (
                <ul className="flex flex-wrap gap-1" aria-label="Selected">
                    {selected.map((item) => (
                        <li
                            key={item.value}
                            className="flex items-center gap-1 rounded-md border bg-muted/50 py-0.5 pr-0.5 pl-2 text-sm"
                        >
                            <span>{item.label}</span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-6"
                                disabled={disabled}
                                onClick={() => remove(item.value)}
                                aria-label={`Remove ${item.label}`}
                            >
                                <X />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            {showInput && (
                <div className="relative">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        id={id}
                        className="pl-8"
                        value={query}
                        placeholder={placeholder}
                        autoComplete="off"
                        disabled={disabled}
                        role="combobox"
                        aria-expanded={showList}
                        aria-controls={listId}
                        aria-autocomplete="list"
                        aria-invalid={props['aria-invalid']}
                        aria-activedescendant={
                            showList && visible[active]
                                ? `${listId}-${visible[active].value}`
                                : undefined
                        }
                        onFocus={() => setOpen(true)}
                        onBlur={() =>
                            window.setTimeout(() => setOpen(false), 150)
                        }
                        onKeyDown={onKeyDown}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setOpen(true);
                        }}
                    />
                </div>
            )}
            {error && <p className="text-sm text-destructive">{error}</p>}
            {showInput && showList && (
                <ul
                    id={listId}
                    role="listbox"
                    className="max-h-64 divide-y overflow-y-auto rounded-md border"
                >
                    {searching && visible.length === 0 && (
                        <li className="px-3 py-2 text-sm text-muted-foreground">
                            Searching…
                        </li>
                    )}
                    {!searching && visible.length === 0 && (
                        <li className="px-3 py-2 text-sm text-muted-foreground">
                            Nothing found.
                        </li>
                    )}
                    {visible.map((result, index) => (
                        <li
                            key={result.value}
                            id={`${listId}-${result.value}`}
                            role="option"
                            aria-selected={index === active}
                        >
                            <button
                                type="button"
                                className={cn(
                                    'block w-full px-3 py-2 text-left text-sm hover:bg-muted',
                                    index === active && 'bg-muted',
                                )}
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => choose(result)}
                            >
                                <span className="block font-medium">
                                    {result.label}
                                </span>
                                {result.secondary && (
                                    <span className="block text-xs text-muted-foreground">
                                        {result.secondary}
                                    </span>
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
