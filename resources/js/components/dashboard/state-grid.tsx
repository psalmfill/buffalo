import { Link } from '@inertiajs/react';
import { useState } from 'react';
import PlaceDashboardController from '@/actions/App/Http/Controllers/Dashboard/PlaceDashboardController';
import StateLink from '@/components/local-government/state-link';
import { cn } from '@/lib/utils';
import type { StateGridCard } from '@/types/dashboard';

type Order = 'attention' | 'alphabetical' | 'zone';

const BORDER: Record<StateGridCard['attention']['level'], string> = {
    critical: 'border-red-600 border-2',
    high: 'border-orange-500 border-2',
    elevated: 'border-amber-400',
    none: '',
};

const LEVEL_LABEL: Record<StateGridCard['attention']['level'], string> = {
    critical: 'Critical attention',
    high: 'High attention',
    elevated: 'Elevated',
    none: 'Quiet',
};

function change(value: number | null): string {
    if (value === null) {
        return '';
    }

    return ` (${value > 0 ? '+' : ''}${value}%)`;
}

function Figure({
    label,
    value,
    suffix = '',
}: {
    label: string;
    value: number | null;
    suffix?: string;
}) {
    if (value === null) {
        return null;
    }

    return (
        <div className="flex justify-between gap-2">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium tabular-nums">
                {value}
                {suffix}
            </dd>
        </div>
    );
}

// The name opens the state page (Phase 11F); the figures open the state's dashboard.
function Card({ card }: { card: StateGridCard }) {
    return (
        <div
            className={cn(
                'rounded-xl border bg-card p-3 text-xs transition hover:bg-accent/40',
                BORDER[card.attention.level],
            )}
            data-test="state-card"
            data-attention={card.attention.level}
        >
            <div className="mb-2 flex items-baseline justify-between gap-2">
                <h3 className="text-sm font-medium">
                    <StateLink state={card.state} />
                </h3>
                <span
                    className="text-muted-foreground"
                    title={`Attention score ${card.attention.score}`}
                >
                    {LEVEL_LABEL[card.attention.level]}
                </span>
            </div>
            <Link
                href={PlaceDashboardController.state.url(card.state.ulid)}
                className="block"
                aria-label={`${card.state.name} dashboard`}
            >
                <dl className="space-y-0.5">
                    {card.incidents7 !== null && (
                        <div className="flex justify-between gap-2">
                            <dt className="text-muted-foreground">
                                Incidents 7 / 30 days
                            </dt>
                            <dd className="font-medium tabular-nums">
                                {card.incidents7}
                                {change(card.incidents7Change)} /{' '}
                                {card.incidents30}
                                {change(card.incidents30Change)}
                            </dd>
                        </div>
                    )}
                    <Figure label="Critical open" value={card.criticalOpen} />
                    <Figure
                        label="Escalations unacknowledged"
                        value={card.escalationsUnacknowledged}
                    />
                    <Figure
                        label="Immediate threats"
                        value={card.immediateThreats}
                    />
                    <Figure
                        label="Overdue verifications"
                        value={card.overdueVerifications}
                    />
                    <Figure label="Killed (30 days)" value={card.killed30} />
                    <Figure
                        label="Abducted (30 days)"
                        value={card.abducted30}
                    />
                    <Figure label="Arrests (30 days)" value={card.arrests30} />
                    <Figure
                        label="Coverage compliance"
                        value={card.coverageCompliance}
                        suffix="%"
                    />
                </dl>
            </Link>
        </div>
    );
}

/**
 * The national dashboard's state grid (Phase 11A.2): one card per state
 * within reach, most urgent first; or alphabetical, or grouped by zone.
 * Each card's figures open that state's dashboard, its name the state page.
 */
export default function StateGrid({ cards }: { cards: StateGridCard[] }) {
    const [order, setOrder] = useState<Order>('attention');

    const sorted =
        order === 'attention'
            ? cards
            : [...cards].sort((a, b) =>
                  a.state.name.localeCompare(b.state.name),
              );
    const groups =
        order === 'zone'
            ? Object.entries(
                  sorted.reduce<Record<string, StateGridCard[]>>(
                      (all, card) => {
                          const zone = card.zone?.name ?? 'No zone';
                          (all[zone] ??= []).push(card);

                          return all;
                      },
                      {},
                  ),
              ).sort(([a], [b]) => a.localeCompare(b))
            : [['', sorted] as [string, StateGridCard[]]];

    return (
        <section className="space-y-3" aria-labelledby="state-grid-title">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="state-grid-title" className="text-sm font-medium">
                    States
                </h2>
                <div
                    className="flex gap-1 text-xs"
                    role="group"
                    aria-label="Order states"
                >
                    {(
                        [
                            ['attention', 'Needs attention'],
                            ['alphabetical', 'A–Z'],
                            ['zone', 'By zone'],
                        ] as [Order, string][]
                    ).map(([value, label]) => (
                        <button
                            key={value}
                            type="button"
                            aria-pressed={order === value}
                            onClick={() => setOrder(value)}
                            className={cn(
                                'rounded-md border px-2 py-1',
                                order === value
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'hover:bg-accent',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>
            {groups.map(([zone, items]) => (
                <div key={zone || 'all'} className="space-y-2">
                    {zone !== '' && (
                        <h3 className="text-xs font-medium text-muted-foreground">
                            {zone}
                        </h3>
                    )}
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                        {items.map((card) => (
                            <Card key={card.state.ulid} card={card} />
                        ))}
                    </div>
                </div>
            ))}
        </section>
    );
}
