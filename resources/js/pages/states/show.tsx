import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    ChevronDown,
    LayoutDashboard,
    Map as MapIcon,
} from 'lucide-react';
import type { FormEvent } from 'react';
import AgencyFormationController from '@/actions/App/Http/Controllers/Security/AgencyFormationController';
import FlashpointController from '@/actions/App/Http/Controllers/Flashpoints/FlashpointController';
import IncidentListController from '@/actions/App/Http/Controllers/Incidents/IncidentListController';
import LgaController from '@/actions/App/Http/Controllers/LocalGovernment/LgaController';
import StateController from '@/actions/App/Http/Controllers/LocalGovernment/StateController';
import OrganizationSiteController from '@/actions/App/Http/Controllers/Organizations/OrganizationSiteController';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import SenatorialDistrictController from '@/actions/App/Http/Controllers/Political/SenatorialDistrictController';
import ThreatReportController from '@/actions/App/Http/Controllers/Threats/ThreatReportController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import ConcernBadge from '@/components/flashpoints/concern-badge';
import InputError from '@/components/input-error';
import CompletenessBar from '@/components/local-government/completeness-bar';
import BuffaloMap from '@/components/map/BuffaloMap';
import type { GeoLayer } from '@/components/map/BuffaloMap';
import Pagination from '@/components/pagination';
import OfficeHoldersTable from '@/components/political/office-holders-table';
import ExportReportButton from '@/components/reports/export-report-button';
import RichTextEditor from '@/components/rich-text/rich-text-editor';
import RichTextView from '@/components/rich-text/rich-text-view';
import { UrgencyBadge } from '@/components/threats/threat-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import ActivityTable from '@/components/users/activity-table';
import { formatDateTime, timeAgo } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { Completeness, NamedRef } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';
import type {
    StateDistrict,
    StateFacts,
    StateIncidents,
    StateLeadership,
    StateLgaRow,
    StateOrganizations,
    StateOverview,
    StateProfileData,
    StateSecurityPresence,
} from '@/types/states';
import type { ActivityEntry } from '@/types/users';

type Tab =
    | 'overview'
    | 'profile'
    | 'lgas'
    | 'districts'
    | 'leadership'
    | 'security_presence'
    | 'organizations'
    | 'incidents'
    | 'history';

type Props = {
    state: StateFacts;
    tab: Tab;
    tabs: Tab[];
    completeness: Completeness;
    lastReviewed: { at: string | null; by: string | null };
    can: { updateProfile: boolean; operational: boolean };
    overview: StateOverview | null;
    profile: StateProfileData | null;
    lgas: StateLgaRow[] | null;
    districts: StateDistrict[] | null;
    leadership: StateLeadership | null;
    securityPresence: StateSecurityPresence | null;
    organizations: StateOrganizations | null;
    incidents: StateIncidents | null;
    history: Paginated<ActivityEntry> | null;
};

// In the order of StateController::TABS.
const TAB_LABELS: Record<Tab, string> = {
    overview: 'Overview',
    profile: 'Profile',
    lgas: 'LGAs',
    districts: 'Senatorial districts',
    leadership: 'Leadership',
    security_presence: 'Security presence',
    organizations: 'Organizations',
    incidents: 'Incidents',
    history: 'History',
};

export default function StateShow(props: Props) {
    const { state, tab, tabs, completeness } = props;

    return (
        <>
            <Head title={`${state.name} State`} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold">
                            {state.name} State
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {state.zone.name}
                            {state.capital
                                ? ` · Capital: ${state.capital}`
                                : ''}
                            {!state.is_active && ' · inactive'}
                        </p>
                    </div>
                    <div className="flex items-center gap-4 text-sm">
                        <div className="flex items-center gap-2">
                            <span className="text-muted-foreground">
                                Profile
                            </span>
                            <CompletenessBar percent={completeness.percent} />
                        </div>
                        <ExportReportButton
                            type="state_profile"
                            parameters={{ state: state.ulid }}
                            label="Export PDF"
                        />
                    </div>
                </div>

                <nav
                    className="flex gap-1 overflow-x-auto border-b"
                    aria-label="State sections"
                >
                    {tabs.map((name) => (
                        <Link
                            key={name}
                            href={StateController.show(state.ulid, {
                                query: { tab: name },
                            })}
                            preserveScroll
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm whitespace-nowrap',
                                tab === name
                                    ? 'border-primary font-medium'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                            aria-current={tab === name ? 'page' : undefined}
                        >
                            {TAB_LABELS[name]}
                        </Link>
                    ))}
                </nav>

                {tab === 'overview' && props.overview && (
                    <OverviewTab {...props} overview={props.overview} />
                )}
                {tab === 'profile' && props.profile && (
                    <ProfileTab
                        state={state}
                        profile={props.profile}
                        canUpdate={props.can.updateProfile}
                    />
                )}
                {tab === 'lgas' && props.lgas && <LgasTab rows={props.lgas} />}
                {tab === 'districts' && props.districts && (
                    <DistrictsTab districts={props.districts} />
                )}
                {tab === 'leadership' && props.leadership && (
                    <LeadershipTab data={props.leadership} />
                )}
                {tab === 'security_presence' && props.securityPresence && (
                    <SecurityPresenceTab data={props.securityPresence} />
                )}
                {tab === 'organizations' && props.organizations && (
                    <OrganizationsTab groups={props.organizations} />
                )}
                {tab === 'incidents' && props.incidents && (
                    <IncidentsTab state={state} data={props.incidents} />
                )}
                {tab === 'history' && props.history && (
                    <div className="space-y-4">
                        <ActivityTable entries={props.history.data} />
                        <Pagination page={props.history} />
                    </div>
                )}
            </div>
        </>
    );
}

function Fact({
    label,
    value,
    note,
}: {
    label: string;
    value: string | null;
    note?: string | null;
}) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">
                {value ?? '—'}
                {note && (
                    <span className="ml-1 text-xs text-muted-foreground">
                        {note}
                    </span>
                )}
            </dd>
        </div>
    );
}

function missingNote(missing: number | undefined): string | null {
    if (!missing) {
        return null;
    }

    return `(${missing} ${missing === 1 ? 'LGA' : 'LGAs'} without a figure)`;
}

function names(refs: NamedRef[]): string {
    return refs.length === 0 ? 'Vacant' : refs.map((r) => r.name).join(', ');
}

const OUTLINE_STYLE = {
    color: '#0f766e',
    weight: 1,
    fillColor: '#14b8a6',
    fillOpacity: 0.06,
};

function OverviewTab({
    state,
    completeness,
    lastReviewed,
    can,
    overview,
}: Props & { overview: StateOverview }) {
    const { facts, map } = overview;
    const layers: GeoLayer[] = overview.lgaOutlines
        ? [
              {
                  id: 'lgas',
                  data: overview.lgaOutlines,
                  style: () => OUTLINE_STYLE,
                  tooltip: (properties) =>
                      typeof properties.name === 'string'
                          ? properties.name
                          : null,
              },
          ]
        : [];

    return (
        <div className="grid gap-4 lg:grid-cols-3">
            <section
                className="space-y-2 rounded-lg border p-4 lg:col-span-3"
                aria-label="Boundary"
            >
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-sm font-semibold">Boundary and LGAs</h2>
                    <div className="flex flex-wrap gap-2">
                        {overview.mapUrl && (
                            <Button variant="outline" size="sm" asChild>
                                <a href={overview.mapUrl}>
                                    <MapIcon /> Operational map
                                </a>
                            </Button>
                        )}
                        {overview.dashboardUrl && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={overview.dashboardUrl}>
                                    <LayoutDashboard /> Open state dashboard
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
                {map.outline || overview.lgaOutlines ? (
                    <BuffaloMap
                        className="h-80 w-full rounded-lg border"
                        center={map.center ?? undefined}
                        zoom={map.zoom}
                        outline={map.outline}
                        fitOutline
                        geoLayers={layers}
                    />
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No boundary has been imported for this state yet.
                    </p>
                )}
            </section>

            <section className="rounded-lg border p-4 lg:col-span-2">
                <h2 className="mb-3 text-sm font-semibold">Basic facts</h2>
                <dl className="grid gap-4 sm:grid-cols-2">
                    <Fact label="Code" value={state.code} />
                    <Fact label="Geopolitical zone" value={state.zone.name} />
                    <Fact label="Capital" value={state.capital} />
                    {facts.area_km2 !== undefined && (
                        <Fact
                            label="Area (sum of LGAs)"
                            value={
                                facts.area_km2 === null
                                    ? null
                                    : `${facts.area_km2.toLocaleString('en-NG')} km²`
                            }
                            note={missingNote(facts.area_missing)}
                        />
                    )}
                    {facts.population !== undefined && (
                        <Fact
                            label="Population (sum of LGA estimates)"
                            value={
                                facts.population?.toLocaleString('en-NG') ??
                                null
                            }
                            note={missingNote(facts.population_missing)}
                        />
                    )}
                    <Fact label="LGAs" value={String(facts.lgas)} />
                    <Fact
                        label="Senatorial districts"
                        value={String(facts.districts)}
                    />
                    {facts.communities !== undefined && (
                        <Fact
                            label="Communities"
                            value={facts.communities.toLocaleString('en-NG')}
                        />
                    )}
                    {overview.officers && (
                        <>
                            <Fact
                                label="State Director"
                                value={names(overview.officers.director)}
                            />
                            <Fact
                                label="Deputy State Director"
                                value={names(overview.officers.deputies)}
                            />
                        </>
                    )}
                </dl>
            </section>

            <section className="space-y-4 rounded-lg border p-4">
                <div>
                    <h2 className="mb-2 text-sm font-semibold">
                        Profile completeness
                    </h2>
                    <CompletenessBar percent={completeness.percent} />
                    {completeness.missing.length > 0 && (
                        <ul className="mt-2 list-inside list-disc text-sm text-muted-foreground">
                            {completeness.missing.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    )}
                </div>
                <div>
                    <h2 className="mb-1 text-sm font-semibold">
                        Last reviewed
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {lastReviewed.at
                            ? `${formatDateTime(lastReviewed.at)}${lastReviewed.by ? ` by ${lastReviewed.by}` : ''}`
                            : 'Never reviewed'}
                    </p>
                </div>
                {can.updateProfile && (
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.post(
                                StateController.markReviewed(state.ulid).url,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <CheckCircle2 /> Mark as reviewed
                    </Button>
                )}
            </section>
        </div>
    );
}

const PROFILE_TEXTS = [
    ['history', 'History'],
    ['economic_activities', 'Economic activities'],
    ['major_roads', 'Major roads'],
    ['waterways', 'Waterways'],
    [
        'borders',
        'Borders (neighbouring states, international borders, border crossings)',
    ],
    ['general_notes', 'General notes'],
] as const;

type ProfileTextField = (typeof PROFILE_TEXTS)[number][0];

function ProfileTab({
    state,
    profile,
    canUpdate,
}: {
    state: StateFacts;
    profile: StateProfileData;
    canUpdate: boolean;
}) {
    const form = useForm({
        history: profile.history ?? '',
        economic_activities: profile.economic_activities ?? '',
        terrain: profile.terrain,
        major_roads: profile.major_roads ?? '',
        waterways: profile.waterways ?? '',
        borders: profile.borders ?? '',
        general_notes: profile.general_notes ?? '',
        capital: state.capital ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(StateController.updateProfile(state.ulid).url, {
            preserveScroll: true,
        });
    };

    if (!canUpdate) {
        return (
            <dl className="grid max-w-3xl gap-4">
                {PROFILE_TEXTS.map(([field, label]) => (
                    <div key={field}>
                        <dt className="text-xs text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="text-sm">
                            <RichTextView html={profile[field]} empty="—" />
                        </dd>
                    </div>
                ))}
                <div>
                    <dt className="text-xs text-muted-foreground">Terrain</dt>
                    <dd className="flex flex-wrap gap-1">
                        {profile.terrain.length === 0
                            ? '—'
                            : profile.terrainOptions
                                  .filter((option) =>
                                      profile.terrain.includes(option.value),
                                  )
                                  .map((option) => (
                                      <Badge
                                          key={option.value}
                                          variant="secondary"
                                      >
                                          {option.label}
                                      </Badge>
                                  ))}
                    </dd>
                </div>
            </dl>
        );
    }

    const text = (field: ProfileTextField, label: string) => (
        <div key={field} className="grid gap-2">
            <Label htmlFor={field}>{label}</Label>
            <RichTextEditor
                id={field}
                value={form.data[field]}
                onChange={(html) => form.setData(field, html)}
                limit={20000}
                invalid={!!form.errors[field]}
            />
            <InputError message={form.errors[field]} />
        </div>
    );

    return (
        <form onSubmit={submit} className="max-w-3xl space-y-8">
            <section className="grid gap-4 md:grid-cols-3">
                <div className="grid gap-2">
                    <Label htmlFor="capital">Capital</Label>
                    <Input
                        id="capital"
                        value={form.data.capital}
                        onChange={(event) =>
                            form.setData('capital', event.target.value)
                        }
                    />
                    <InputError message={form.errors.capital} />
                </div>
                <p className="text-sm text-muted-foreground md:col-span-2 md:self-end">
                    Area and population are the sums of the LGAs&apos; figures,
                    edited on each LGA&apos;s profile. The name, code and zone
                    are reference data.
                </p>
            </section>

            <fieldset className="space-y-2">
                <legend className="text-sm font-medium">Terrain</legend>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {profile.terrainOptions.map((option) => (
                        <label
                            key={option.value}
                            className="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                checked={form.data.terrain.includes(
                                    option.value,
                                )}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'terrain',
                                        checked === true
                                            ? [
                                                  ...form.data.terrain,
                                                  option.value,
                                              ]
                                            : form.data.terrain.filter(
                                                  (item) =>
                                                      item !== option.value,
                                              ),
                                    )
                                }
                            />
                            {option.label}
                        </label>
                    ))}
                </div>
                <InputError message={form.errors.terrain} />
            </fieldset>

            <section className="grid gap-4">
                {PROFILE_TEXTS.map(([field, label]) => text(field, label))}
            </section>

            <Button type="submit" disabled={form.processing}>
                {form.processing && <Spinner />}
                Save profile
            </Button>
        </form>
    );
}

function Empty({ children }: { children: string }) {
    return <p className="text-sm text-muted-foreground">{children}</p>;
}

function LgasTab({ rows }: { rows: StateLgaRow[] }) {
    const showIncidents = rows.some((row) => row.incidents30 !== null);

    if (rows.length === 0) {
        return <Empty>No LGAs recorded.</Empty>;
    }

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="px-3 py-2 font-medium">LGA</th>
                        <th className="px-3 py-2 font-medium">
                            Senatorial district
                        </th>
                        <th className="px-3 py-2 font-medium">Headquarters</th>
                        <th className="px-3 py-2 font-medium">
                            LGA Security Chief
                        </th>
                        <th className="px-3 py-2 font-medium">Profile</th>
                        {showIncidents && (
                            <th className="px-3 py-2 text-right font-medium">
                                Incidents (30 days)
                            </th>
                        )}
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((lga) => (
                        <tr key={lga.ulid}>
                            <td className="px-3 py-2">
                                <Link
                                    href={LgaController.show(lga.ulid)}
                                    className="font-medium hover:underline"
                                >
                                    {lga.name}
                                </Link>
                            </td>
                            <td className="px-3 py-2">{lga.district ?? '—'}</td>
                            <td className="px-3 py-2">
                                {lga.headquarters ?? '—'}
                            </td>
                            <td className="px-3 py-2">
                                {lga.chief?.name ?? (
                                    <span className="text-muted-foreground">
                                        Vacant
                                    </span>
                                )}
                            </td>
                            <td className="px-3 py-2">
                                <CompletenessBar percent={lga.completeness} />
                            </td>
                            {showIncidents && (
                                <td className="px-3 py-2 text-right tabular-nums">
                                    {lga.incidents30 ?? '—'}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function DistrictsTab({ districts }: { districts: StateDistrict[] }) {
    if (districts.length === 0) {
        return <Empty>No senatorial districts recorded.</Empty>;
    }

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="px-3 py-2 font-medium">District</th>
                        <th className="px-3 py-2 font-medium">LGAs</th>
                        <th className="px-3 py-2 font-medium">
                            Current senator
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {districts.map((district) => (
                        <tr key={district.ulid} className="align-top">
                            <td className="px-3 py-2">
                                <Link
                                    href={SenatorialDistrictController.show(
                                        district.ulid,
                                    )}
                                    className="font-medium hover:underline"
                                >
                                    {district.name}
                                </Link>
                                <div className="text-xs text-muted-foreground">
                                    {district.code}
                                </div>
                            </td>
                            <td className="px-3 py-2">
                                {district.lgas.map((l) => l.name).join(', ') ||
                                    '—'}
                            </td>
                            <td className="px-3 py-2">
                                {district.senators.length === 0
                                    ? '—'
                                    : district.senators.map((s, i) => (
                                          <span key={s.ulid}>
                                              {i > 0 && ', '}
                                              <Link
                                                  href={PersonController.show(
                                                      s.ulid,
                                                  )}
                                                  className="hover:underline"
                                              >
                                                  {s.name}
                                              </Link>
                                          </span>
                                      ))}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function LeadershipTab({ data }: { data: StateLeadership }) {
    return (
        <div className="space-y-6">
            {data.groups.length === 0 && (
                <Empty>No current state-level office holders recorded.</Empty>
            )}
            {data.groups.map((group) => (
                <section
                    key={group.office.value}
                    className="space-y-2"
                    aria-label={group.office.label}
                >
                    <h2 className="text-sm font-semibold">
                        {group.office.label}
                    </h2>
                    <OfficeHoldersTable offices={group.holders} />
                </section>
            ))}
            {data.past.length > 0 && (
                <details className="rounded-lg border p-4">
                    <summary className="flex cursor-pointer items-center gap-2 text-sm font-semibold">
                        <ChevronDown className="size-4" /> Past holders (
                        {data.past.length})
                    </summary>
                    <div className="mt-3">
                        <OfficeHoldersTable offices={data.past} />
                    </div>
                </details>
            )}
        </div>
    );
}

function SecurityPresenceTab({ data }: { data: StateSecurityPresence }) {
    return (
        <div className="space-y-8">
            {data.formations && (
                <section className="space-y-3" aria-label="Agency formations">
                    <h2 className="text-sm font-semibold">Agency formations</h2>
                    {data.formations.length === 0 && (
                        <Empty>No formations recorded.</Empty>
                    )}
                    {data.formations.map((group) => (
                        <div key={group.agency.ulid} className="space-y-1">
                            <h3 className="text-sm font-medium">
                                {group.agency.name}
                                {group.agency.short_name &&
                                    ` (${group.agency.short_name})`}
                            </h3>
                            <ul className="divide-y rounded-lg border text-sm">
                                {group.formations.map((f) => (
                                    <li
                                        key={f.ulid}
                                        className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
                                    >
                                        <Link
                                            href={AgencyFormationController.show(
                                                f.ulid,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {f.name}
                                        </Link>
                                        <span className="text-muted-foreground">
                                            {f.formation_type.label} ·{' '}
                                            {f.lga.name} · {f.status.label}
                                        </span>
                                        <ClassificationBadge
                                            level={f.classification.value}
                                            className="ml-auto"
                                        />
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </section>
            )}

            {data.flashpoints && (
                <section
                    className="space-y-3"
                    aria-label="Flashpoints and blackspots"
                >
                    <h2 className="text-sm font-semibold">
                        Active flashpoints and blackspots
                    </h2>
                    {data.flashpoints.length === 0 && (
                        <Empty>None active.</Empty>
                    )}
                    {data.flashpoints.map((group) => (
                        <div key={group.value} className="space-y-1">
                            <h3 className="text-sm font-medium">
                                {group.label} concern ({group.rows.length})
                            </h3>
                            <ul className="divide-y rounded-lg border text-sm">
                                {group.rows.map((f) => (
                                    <li
                                        key={f.ulid}
                                        className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
                                    >
                                        <ConcernBadge level={f.concern_level} />
                                        <Link
                                            href={FlashpointController.show(
                                                f.ulid,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {f.name}
                                        </Link>
                                        <span className="text-muted-foreground">
                                            {f.kind.label} ·{' '}
                                            {f.threat_type.name} · {f.lga.name}{' '}
                                            · {f.status.label}
                                        </span>
                                        <ClassificationBadge
                                            level={f.classification.value}
                                            className="ml-auto"
                                        />
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </section>
            )}

            {data.threats && (
                <section className="space-y-3" aria-label="Threat reports">
                    <h2 className="text-sm font-semibold">
                        Active threat reports
                    </h2>
                    {data.threats.length === 0 && <Empty>None active.</Empty>}
                    {data.threats.map((group) => (
                        <div key={group.value} className="space-y-1">
                            <h3 className="text-sm font-medium">
                                {group.label} ({group.rows.length})
                            </h3>
                            <ul className="divide-y rounded-lg border text-sm">
                                {group.rows.map((t) => (
                                    <li
                                        key={t.ulid}
                                        className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
                                    >
                                        <UrgencyBadge urgency={t.urgency} />
                                        <Link
                                            href={ThreatReportController.show(
                                                t.ulid,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {t.reference_no}
                                        </Link>
                                        <span>{t.title}</span>
                                        <span className="text-muted-foreground">
                                            {t.threat_type.name} · {t.lga.name}{' '}
                                            · expires {timeAgo(t.expires_at)}
                                        </span>
                                        <ClassificationBadge
                                            level={t.classification.value}
                                            className="ml-auto"
                                        />
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </section>
            )}

            {data.disseminations && (
                <section
                    className="space-y-3"
                    aria-label="Disseminations received"
                >
                    <h2 className="text-sm font-semibold">
                        Shared with the state by other states
                    </h2>
                    {data.disseminations.length === 0 ? (
                        <Empty>None active.</Empty>
                    ) : (
                        <ul className="divide-y rounded-lg border text-sm">
                            {data.disseminations.map((d) => (
                                <li
                                    key={d.ulid}
                                    className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
                                >
                                    <span className="text-muted-foreground">
                                        {d.kind}
                                    </span>
                                    <Link
                                        href={d.href}
                                        className="font-medium hover:underline"
                                    >
                                        {d.label}
                                    </Link>
                                    <span className="text-muted-foreground">
                                        from {d.from} · to {d.to} · shared{' '}
                                        {timeAgo(d.disseminated_at)} · expires{' '}
                                        {timeAgo(d.expires_at)}
                                    </span>
                                    {!d.acknowledged && (
                                        <Badge
                                            variant="outline"
                                            className="ml-auto"
                                        >
                                            Not acknowledged
                                        </Badge>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            )}
        </div>
    );
}

function OrganizationsTab({ groups }: { groups: StateOrganizations }) {
    if (groups.length === 0) {
        return (
            <Empty>
                No high-relevance or critical-infrastructure sites recorded.
            </Empty>
        );
    }

    return (
        <div className="space-y-6">
            <p className="text-sm text-muted-foreground">
                High-relevance and critical-infrastructure sites, by LGA.
            </p>
            {groups.map((group) => (
                <section
                    key={group.lga.ulid}
                    className="space-y-1"
                    aria-label={group.lga.name}
                >
                    <h2 className="text-sm font-semibold">
                        <Link
                            href={LgaController.show(group.lga.ulid, {
                                query: { tab: 'organizations' },
                            })}
                            className="hover:underline"
                        >
                            {group.lga.name}
                        </Link>
                    </h2>
                    <ul className="divide-y rounded-lg border text-sm">
                        {group.sites.map((site) => (
                            <li
                                key={site.ulid}
                                className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
                            >
                                <Link
                                    href={OrganizationSiteController.show(
                                        site.ulid,
                                    )}
                                    className="font-medium hover:underline"
                                >
                                    {site.name}
                                </Link>
                                <span className="text-muted-foreground">
                                    {site.organization.name} ·{' '}
                                    {site.site_type.label}
                                </span>
                                <Badge
                                    variant={
                                        site.security_relevance.value ===
                                        'critical_infrastructure'
                                            ? 'destructive'
                                            : 'secondary'
                                    }
                                >
                                    {site.security_relevance.label}
                                </Badge>
                                <ClassificationBadge
                                    level={site.classification.value}
                                    className="ml-auto"
                                />
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
        </div>
    );
}

function IncidentsTab({
    state,
    data,
}: {
    state: StateFacts;
    data: StateIncidents;
}) {
    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm text-muted-foreground">
                    {data.total} incident{data.total === 1 ? '' : 's'} in the
                    last 90 days (drafts and invalid reports are not counted).
                </p>
                <Button variant="outline" size="sm" asChild>
                    <Link
                        href={IncidentListController.index({
                            query: { state: state.ulid },
                        })}
                    >
                        Open the incident list for {state.name}
                    </Link>
                </Button>
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                <section
                    className="rounded-lg border p-4"
                    aria-label="Last 90 days by type"
                >
                    <h2 className="mb-2 text-sm font-semibold">By type</h2>
                    {data.byType.length === 0 ? (
                        <Empty>No incidents in the last 90 days.</Empty>
                    ) : (
                        <ul className="divide-y text-sm">
                            {data.byType.map((row) => (
                                <li
                                    key={row.type}
                                    className="flex justify-between py-1.5"
                                >
                                    <span>{row.type}</span>
                                    <span className="font-semibold tabular-nums">
                                        {row.count}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
                <section
                    className="rounded-lg border p-4"
                    aria-label="Last 90 days by LGA"
                >
                    <h2 className="mb-2 text-sm font-semibold">By LGA</h2>
                    {data.byLga.length === 0 ? (
                        <Empty>No incidents in the last 90 days.</Empty>
                    ) : (
                        <ul className="divide-y text-sm">
                            {data.byLga.map((row) => (
                                <li
                                    key={row.lga.ulid}
                                    className="flex justify-between py-1.5"
                                >
                                    <Link
                                        href={IncidentListController.index({
                                            query: { lga: row.lga.ulid },
                                        })}
                                        className="hover:underline"
                                    >
                                        {row.lga.name}
                                    </Link>
                                    <span className="font-semibold tabular-nums">
                                        {row.count}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </div>
    );
}

StateShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'States', href: StateController.index() },
        {
            title: `${props.state.name} State`,
            href: StateController.show(props.state.ulid),
        },
    ],
});
