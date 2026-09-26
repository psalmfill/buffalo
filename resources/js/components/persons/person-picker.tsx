import { Search, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import PersonPickerController from '@/actions/App/Http/Controllers/Persons/PersonPickerController';
import PersonAvatar from '@/components/persons/person-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { postJson } from '@/lib/http';
import type { NamedRef } from '@/types/local-government';
import type { PersonRow } from '@/types/persons';

/**
 * Picks a person the signed-in user can see, by name (fuzzy), NIN or phone
 * number. Searches are POSTed, so names and numbers stay out of URLs.
 * Optionally only persons holding an active role.
 */
export default function PersonPicker({
    id,
    value,
    onChange,
    role,
    except,
    placeholder = 'Search by name, NIN or phone',
}: {
    id?: string;
    value: NamedRef | null;
    onChange: (person: NamedRef | null) => void;
    role?: string;
    except?: string;
    placeholder?: string;
}) {
    const listId = useId();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<PersonRow[]>([]);
    const [searching, setSearching] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const latest = useRef(0);

    useEffect(() => {
        const term = query.trim();

        // Shorter terms are not searched (the list is hidden).
        if (term.length < 2) {
            return;
        }

        const request = ++latest.current;
        const timer = window.setTimeout(() => {
            setSearching(true);
            postJson<{ results: PersonRow[] }>(PersonPickerController.url(), {
                q: term,
                role: role ?? null,
                except: except ?? null,
            })
                .then((data) => {
                    if (request === latest.current) {
                        setResults(data.results);
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
        }, 300);

        return () => window.clearTimeout(timer);
    }, [query, role, except]);

    if (value !== null) {
        return (
            <div className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm">
                <span className="font-medium">{value.name}</span>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-7"
                    onClick={() => onChange(null)}
                    aria-label={`Remove ${value.name}`}
                >
                    <X />
                </Button>
            </div>
        );
    }

    return (
        <div className="space-y-1">
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    id={id}
                    className="pl-8"
                    value={query}
                    placeholder={placeholder}
                    autoComplete="off"
                    aria-controls={listId}
                    onChange={(event) => setQuery(event.target.value)}
                />
            </div>
            {error && <p className="text-sm text-destructive">{error}</p>}
            {query.trim().length >= 2 && (
                <ul
                    id={listId}
                    className="max-h-64 divide-y overflow-y-auto rounded-md border"
                >
                    {searching && results.length === 0 && (
                        <li className="px-3 py-2 text-sm text-muted-foreground">
                            Searching…
                        </li>
                    )}
                    {!searching && results.length === 0 && (
                        <li className="px-3 py-2 text-sm text-muted-foreground">
                            No persons found.
                        </li>
                    )}
                    {results.map((person) => (
                        <li key={person.ulid}>
                            <button
                                type="button"
                                className="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-muted"
                                onClick={() => {
                                    onChange({
                                        ulid: person.ulid,
                                        name: person.name,
                                    });
                                    setQuery('');
                                }}
                            >
                                <PersonAvatar
                                    src={person.thumbnail}
                                    name={person.name}
                                    className="size-8"
                                />
                                <span className="min-w-0">
                                    <span className="block font-medium">
                                        {person.name}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {[
                                            person.lga?.name
                                                ? `${person.lga.name} LGA`
                                                : null,
                                            ...person.roles.map((r) => r.label),
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
