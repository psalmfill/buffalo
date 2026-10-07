import { Head, Link, router } from '@inertiajs/react';
import { Mail, Upload, Users } from 'lucide-react';
import { useState } from 'react';
import OnboardingController from '@/actions/App/Http/Controllers/Users/OnboardingController';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import UserImportController from '@/actions/App/Http/Controllers/Users/UserImportController';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type { OnboardingUser } from '@/types/onboarding';
import { STAGE_LABELS } from '@/types/onboarding';

type Props = {
    users: OnboardingUser[];
    total: number;
    limit: number;
    filters: { state: string; role: string; status: string };
    states: { ulid: string; name: string }[];
    roles: { value: string; label: string }[];
    mailEnabled: boolean;
};

/**
 * Onboarding (Phase 9B.2): choose users, then email each their own setup
 * link, or start a handover session to reveal links one at a time.
 */
export default function Onboarding({
    users,
    total,
    limit,
    filters,
    states,
    roles,
    mailEnabled,
}: Props) {
    const [selected, setSelected] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);

    const filter = (key: keyof Props['filters'], value: string) =>
        router.get(
            OnboardingController.index().url,
            { ...filters, [key]: value },
            { preserveState: false, replace: true },
        );

    const toggle = (ulid: string, checked: boolean) =>
        setSelected((current) =>
            checked
                ? [...current, ulid]
                : current.filter((item) => item !== ulid),
        );

    const send = (url: string) =>
        router.post(
            url,
            { users: selected },
            {
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );

    return (
        <>
            <Head title="Onboarding" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Onboarding"
                        description="Issue account setup links in batches. Each user gets their own one-time link; links are never listed or exported."
                    />
                    <Button variant="outline" asChild>
                        <Link href={UserImportController.create()}>
                            <Upload /> Import users
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="grid gap-1">
                        <Label htmlFor="state">State</Label>
                        <NativeSelect
                            id="state"
                            value={filters.state}
                            onChange={(e) => filter('state', e.target.value)}
                        >
                            <option value="">All states and national</option>
                            {states.map((state) => (
                                <option key={state.ulid} value={state.ulid}>
                                    {state.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="role">Role</Label>
                        <NativeSelect
                            id="role"
                            value={filters.role}
                            onChange={(e) => filter('role', e.target.value)}
                        >
                            <option value="">All roles</option>
                            {roles.map((role) => (
                                <option key={role.value} value={role.value}>
                                    {role.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="status">Status</Label>
                        <NativeSelect
                            id="status"
                            value={filters.status}
                            onChange={(e) => filter('status', e.target.value)}
                        >
                            <option value="setup_pending">Setup pending</option>
                            <option value="no_2fa">2FA not enrolled</option>
                            <option value="all">All active accounts</option>
                        </NativeSelect>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        onClick={() => send(OnboardingController.email().url)}
                        disabled={busy || selected.length === 0 || !mailEnabled}
                        title={
                            mailEnabled
                                ? undefined
                                : 'Email is not enabled in this deployment'
                        }
                        data-test="email-links"
                    >
                        <Mail /> Email setup links ({selected.length})
                    </Button>
                    <Button
                        variant="outline"
                        onClick={() =>
                            send(OnboardingController.startHandover().url)
                        }
                        disabled={busy || selected.length === 0}
                        data-test="start-handover"
                    >
                        <Users /> Start handover session ({selected.length})
                    </Button>
                    <span className="text-sm text-muted-foreground">
                        {total > users.length
                            ? `Showing the first ${limit} of ${total}; narrow the filters to see the rest.`
                            : `${total} account(s).`}
                        {!mailEnabled &&
                            ' Email is not enabled: use a handover session.'}
                    </span>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="w-10 px-3 py-2">
                                    <Checkbox
                                        checked={
                                            users.length > 0 &&
                                            selected.length === users.length
                                        }
                                        onCheckedChange={(checked) =>
                                            setSelected(
                                                checked === true
                                                    ? users.map((u) => u.ulid)
                                                    : [],
                                            )
                                        }
                                        aria-label="Select all"
                                    />
                                </th>
                                <th className="px-3 py-2 font-medium">Name</th>
                                <th className="px-3 py-2 font-medium">Role</th>
                                <th className="px-3 py-2 font-medium">
                                    Jurisdiction
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Progress
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {users.map((user) => (
                                <tr key={user.ulid}>
                                    <td className="px-3 py-2">
                                        <Checkbox
                                            checked={selected.includes(
                                                user.ulid,
                                            )}
                                            onCheckedChange={(checked) =>
                                                toggle(
                                                    user.ulid,
                                                    checked === true,
                                                )
                                            }
                                            aria-label={`Select ${user.name}`}
                                        />
                                    </td>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={UserController.show(
                                                user.ulid,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {user.name}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {user.email}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2">{user.role}</td>
                                    <td className="px-3 py-2">{user.place}</td>
                                    <td className="px-3 py-2">
                                        <Badge
                                            variant={
                                                user.stage === 'ready'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                        >
                                            {STAGE_LABELS[user.stage]}
                                        </Badge>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

Onboarding.layout = {
    breadcrumbs: [
        { title: 'Users', href: UserController.index() },
        { title: 'Onboarding', href: OnboardingController.index() },
    ],
};
