import { Head, Link, router, useForm } from '@inertiajs/react';
import { CheckCircle2, Plus, Upload } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import CommunityController from '@/actions/App/Http/Controllers/LocalGovernment/CommunityController';
import CommunityImportController from '@/actions/App/Http/Controllers/LocalGovernment/CommunityImportController';
import LgaController from '@/actions/App/Http/Controllers/LocalGovernment/LgaController';
import EmptyState from '@/components/empty-state';
import { FilterBar, useFilters } from '@/components/filter-bar';
import InputError from '@/components/input-error';
import ReligiousInstitutionsPanel from '@/components/community-information/religious-institutions-panel';
import SecurityNotesPanel from '@/components/community-information/security-notes-panel';
import VulnerablePopulationPanel from '@/components/community-information/vulnerable-population-panel';
import CompletenessBar from '@/components/local-government/completeness-bar';
import BuffaloMap from '@/components/map/BuffaloMap';
import type { MapMarker } from '@/components/map/BuffaloMap';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/time';
import LeadershipPanel from '@/components/political/leadership-panel';
import SecurityPresencePanel from '@/components/security/security-presence-panel';
import type { SecurityPresenceTab } from '@/components/security/security-presence-panel';
import { cn } from '@/lib/utils';
import type {
    InformationCan,
    InformationOptions,
    InstitutionsTab,
    NotesTab,
    VulnerableTab,
} from '@/types/community-information';
import type {
    Completeness,
    LgaCommunitiesTab,
    LgaFacts,
    LgaProfileData,
    NamedRef,
} from '@/types/local-government';
import type { LgaLeadership } from '@/types/political';

type Tab =
    | 'overview'
    | 'profile'
    | 'communities'
    | 'leadership'
    | 'religious_institutions'
    | 'vulnerable_population'
    | 'security_notes'
    | 'security_presence';

type Props = {
    lga: LgaFacts;
    tab: Tab;
    tabs: Tab[];
    completeness: Completeness;
    communityCount: number;
    lastReviewed: { at: string | null; by: string | null };
    can: InformationCan & {
        updateProfile: boolean;
        addCommunity: boolean;
        importCommunities: boolean;
        viewLeadership: boolean;
        viewFormations: boolean;
        addFormation: boolean;
    };
    options: InformationOptions;
    overview: { chief: NamedRef | null } | null;
    profile: LgaProfileData | null;
    communities:
        (LgaCommunitiesTab & { institutionMarkers: MapMarker[] }) | null;
    religiousInstitutions: InstitutionsTab | null;
    vulnerablePopulation: VulnerableTab | null;
    securityNotes: NotesTab | null;
    leadership: LgaLeadership | null;
    securityPresence: SecurityPresenceTab | null;
};

// Later phases add their tabs here and in LgaController::TABS.
const TAB_LABELS: Record<Tab, string> = {
    overview: 'Overview',
    profile: 'Profile',
    communities: 'Communities',
    leadership: 'Leadership',
    religious_institutions: 'Religious institutions',
    vulnerable_population: 'Vulnerable population',
    security_notes: 'Security notes',
    security_presence: 'Security presence',
};

export default function LgaShow(props: Props) {
    const { lga, tab, tabs, completeness } = props;

    return (
        <>
            <Head title={`${lga.name} LGA`} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold">
                            {lga.name} LGA
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {lga.state.name} State
                            {lga.district ? ` · ${lga.district}` : ''}
                            {!lga.is_active && ' · inactive'}
                        </p>
                    </div>
                    <div className="flex items-center gap-2 text-sm">
                        <span className="text-muted-foreground">Profile</span>
                        <CompletenessBar percent={completeness.percent} />
                    </div>
                </div>

                <nav className="flex gap-1 border-b" aria-label="LGA sections">
                    {tabs
                        .filter(
                            (name) =>
                                (name !== 'leadership' ||
                                    props.can.viewLeadership) &&
                                (name !== 'security_presence' ||
                                    props.can.viewFormations),
                        )
                        .map((name) => (
                            <Link
                                key={name}
                                href={LgaController.show(lga.ulid, {
                                    query: { tab: name },
                                })}
                                preserveScroll
                                className={cn(
                                    '-mb-px border-b-2 px-3 py-2 text-sm',
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
                    <OverviewTab {...props} chief={props.overview.chief} />
                )}
                {tab === 'profile' && props.profile && (
                    <ProfileTab
                        lga={lga}
                        profile={props.profile}
                        canUpdate={props.can.updateProfile}
                    />
                )}
                {tab === 'communities' && props.communities && (
                    <CommunitiesTab
                        lga={lga}
                        data={props.communities}
                        can={props.can}
                    />
                )}
                {tab === 'religious_institutions' &&
                    props.religiousInstitutions && (
                        <ReligiousInstitutionsPanel
                            lgaUlid={lga.ulid}
                            baseUrl={tabUrl(lga.ulid, tab)}
                            data={props.religiousInstitutions}
                            options={props.options}
                            can={props.can}
                        />
                    )}
                {tab === 'vulnerable_population' &&
                    props.vulnerablePopulation && (
                        <VulnerablePopulationPanel
                            lgaUlid={lga.ulid}
                            baseUrl={tabUrl(lga.ulid, tab)}
                            data={props.vulnerablePopulation}
                            can={props.can}
                        />
                    )}
                {tab === 'leadership' && props.leadership && (
                    <LeadershipPanel data={props.leadership} />
                )}
                {tab === 'security_presence' && props.securityPresence && (
                    <SecurityPresencePanel
                        lgaUlid={lga.ulid}
                        data={props.securityPresence}
                        canAdd={props.can.addFormation}
                    />
                )}
                {tab === 'security_notes' && props.securityNotes && (
                    <SecurityNotesPanel
                        lgaUlid={lga.ulid}
                        baseUrl={tabUrl(lga.ulid, tab)}
                        data={props.securityNotes}
                        options={props.options}
                        can={props.can}
                    />
                )}
            </div>
        </>
    );
}

function tabUrl(ulid: string, tab: Tab): string {
    return LgaController.show(ulid, { query: { tab } }).url;
}

function Fact({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{value ?? '—'}</dd>
        </div>
    );
}

function OverviewTab({
    lga,
    completeness,
    communityCount,
    lastReviewed,
    can,
    chief,
}: Props & { chief: NamedRef | null }) {
    return (
        <div className="grid gap-4 lg:grid-cols-3">
            <section className="rounded-lg border p-4 lg:col-span-2">
                <h2 className="mb-3 text-sm font-semibold">Basic facts</h2>
                <dl className="grid gap-4 sm:grid-cols-2">
                    <Fact label="Code" value={lga.code} />
                    <Fact label="State" value={lga.state.name} />
                    <Fact label="Senatorial district" value={lga.district} />
                    <Fact label="Headquarters" value={lga.headquarters} />
                    <Fact
                        label="Area"
                        value={lga.area_km2 ? `${lga.area_km2} km²` : null}
                    />
                    <Fact
                        label="Population (estimate)"
                        value={
                            lga.population_estimate?.toLocaleString('en-NG') ??
                            null
                        }
                    />
                    <Fact
                        label="LGA Security Chief"
                        value={chief?.name ?? 'Vacant'}
                    />
                    <Fact label="Communities" value={String(communityCount)} />
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
                                LgaController.markReviewed(lga.ulid).url,
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
    ['borders', 'Borders (neighbouring LGAs, states, countries)'],
    ['general_notes', 'General notes'],
] as const;

type ProfileTextField = (typeof PROFILE_TEXTS)[number][0];

function ProfileTab({
    lga,
    profile,
    canUpdate,
}: {
    lga: LgaFacts;
    profile: LgaProfileData;
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
        headquarters: lga.headquarters ?? '',
        area_km2: lga.area_km2 ?? '',
        population_estimate:
            lga.population_estimate === null
                ? ''
                : String(lga.population_estimate),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(LgaController.updateProfile(lga.ulid).url, {
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
                        <dd className="text-sm whitespace-pre-line">
                            {profile[field] ?? '—'}
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
            <Textarea
                id={field}
                rows={3}
                value={form.data[field]}
                onChange={(event) => form.setData(field, event.target.value)}
            />
            <InputError message={form.errors[field]} />
        </div>
    );

    return (
        <form onSubmit={submit} className="max-w-3xl space-y-8">
            <section className="grid gap-4 md:grid-cols-3">
                <div className="grid gap-2">
                    <Label htmlFor="headquarters">Headquarters</Label>
                    <Input
                        id="headquarters"
                        value={form.data.headquarters}
                        onChange={(event) =>
                            form.setData('headquarters', event.target.value)
                        }
                    />
                    <InputError message={form.errors.headquarters} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="area_km2">Area (km²)</Label>
                    <Input
                        id="area_km2"
                        inputMode="decimal"
                        value={form.data.area_km2}
                        onChange={(event) =>
                            form.setData('area_km2', event.target.value)
                        }
                    />
                    <InputError message={form.errors.area_km2} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="population_estimate">
                        Population (estimate)
                    </Label>
                    <Input
                        id="population_estimate"
                        inputMode="numeric"
                        value={form.data.population_estimate}
                        onChange={(event) =>
                            form.setData(
                                'population_estimate',
                                event.target.value,
                            )
                        }
                    />
                    <InputError message={form.errors.population_estimate} />
                </div>
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

function CommunitiesTab({
    lga,
    data,
    can,
}: {
    lga: LgaFacts;
    data: LgaCommunitiesTab & { institutionMarkers: MapMarker[] };
    can: Props['can'];
}) {
    const { values, setValues, apply, reset } = useFilters(
        LgaController.show(lga.ulid, { query: { tab: 'communities' } }).url,
        data.filters,
    );
    const [showInstitutions, setShowInstitutions] = useState(false);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div className="flex-1">
                    <FilterBar onApply={() => apply()} onReset={reset}>
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="search">Search</Label>
                            <Input
                                id="search"
                                placeholder="Name or other name"
                                value={values.search}
                                onChange={(event) =>
                                    setValues({
                                        ...values,
                                        search: event.target.value,
                                    })
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="type">Type</Label>
                            <NativeSelect
                                id="type"
                                value={values.type}
                                onChange={(event) =>
                                    apply({ type: event.target.value })
                                }
                            >
                                <option value="">All types</option>
                                {data.types.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    </FilterBar>
                </div>
                <div className="flex gap-2">
                    {can.importCommunities && (
                        <Button variant="outline" asChild>
                            <Link
                                href={CommunityImportController.create({
                                    query: { lga: lga.ulid },
                                })}
                            >
                                <Upload /> Import CSV
                            </Link>
                        </Button>
                    )}
                    {can.addCommunity && (
                        <Button asChild>
                            <Link href={CommunityController.create(lga.ulid)}>
                                <Plus /> Add community
                            </Link>
                        </Button>
                    )}
                </div>
            </div>

            <div className="grid gap-4 xl:grid-cols-2">
                <div className="space-y-3">
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Community
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Type
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Ward
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Location
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {data.list.data.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="p-0">
                                            <EmptyState
                                                title="No communities"
                                                description={
                                                    values.search || values.type
                                                        ? 'No communities match these filters.'
                                                        : 'No communities have been recorded for this LGA yet.'
                                                }
                                            />
                                        </td>
                                    </tr>
                                )}
                                {data.list.data.map((community) => (
                                    <tr key={community.ulid}>
                                        <td className="px-3 py-2">
                                            <Link
                                                href={CommunityController.show(
                                                    community.ulid,
                                                )}
                                                className="font-medium hover:underline"
                                            >
                                                {community.name}
                                            </Link>
                                            {community.other_names.length >
                                                0 && (
                                                <div className="text-xs text-muted-foreground">
                                                    Also:{' '}
                                                    {community.other_names.join(
                                                        ', ',
                                                    )}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {community.type.label}
                                        </td>
                                        <td className="px-3 py-2">
                                            {community.ward ?? '—'}
                                        </td>
                                        <td className="px-3 py-2 text-muted-foreground">
                                            {community.has_location
                                                ? 'On map'
                                                : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination page={data.list} />
                </div>

                <div className="space-y-2">
                    {data.institutionMarkers.length > 0 && (
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={showInstitutions}
                                onCheckedChange={(checked) =>
                                    setShowInstitutions(checked === true)
                                }
                            />
                            Show religious institutions (
                            {data.institutionMarkers.length})
                            <span
                                aria-hidden
                                className="inline-block size-3 rounded-full border-2 border-white bg-violet-600 ring-1 ring-violet-900"
                            />
                        </label>
                    )}
                    <BuffaloMap
                        center={data.map.center ?? undefined}
                        zoom={data.map.zoom}
                        markers={
                            showInstitutions
                                ? [...data.markers, ...data.institutionMarkers]
                                : data.markers
                        }
                        fitMarkers
                        className="h-[480px] w-full rounded-lg border"
                    />
                </div>
            </div>
        </div>
    );
}

LgaShow.layout = {
    breadcrumbs: [{ title: 'LGAs', href: LgaController.index() }],
};
