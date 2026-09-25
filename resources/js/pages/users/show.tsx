import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ActivityTable from '@/components/users/activity-table';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import JurisdictionFields from '@/components/users/jurisdiction-fields';
import LoginHistoryTable from '@/components/users/login-history-table';
import SetupLinkDialog from '@/components/users/setup-link-dialog';
import UserStatusBadges from '@/components/users/user-status-badges';
import { formatDateTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types/pagination';
import type {
    ActivityEntry,
    JurisdictionFormOptions,
    LoginEntry,
    Posting,
    UserDetail,
} from '@/types/users';

type Tab = 'profile' | 'postings' | 'logins' | 'activity';

type Props = {
    user: UserDetail;
    tab: Tab;
    tabs: Tab[];
    postings: Posting[] | null;
    logins: Paginated<LoginEntry> | null;
    activity: Paginated<ActivityEntry> | null;
    userCreated: boolean;
    mailEnabled: boolean;
    can: {
        update: boolean;
        changeRole: boolean;
        deactivate: boolean;
        reactivate: boolean;
        unlock: boolean;
        resetTwoFactor: boolean;
        issueSetupLink: boolean;
    };
    jurisdictionOptions: JurisdictionFormOptions | null;
};

type DialogName =
    | 'profile'
    | 'jurisdiction'
    | 'deactivate'
    | 'reactivate'
    | 'unlock'
    | 'resetTwoFactor'
    | 'setupLink'
    | null;

const tabLabels: Record<Tab, string> = {
    profile: 'Profile',
    postings: 'Postings',
    logins: 'Login history',
    activity: 'Activity',
};

export default function ShowUser(props: Props) {
    const { user, tab, tabs, can } = props;
    const [dialog, setDialog] = useState<DialogName>(
        props.userCreated && can.issueSetupLink ? 'setupLink' : null,
    );
    const close = (open: boolean) => !open && setDialog(null);
    const locked = user.statuses.includes('locked');

    return (
        <>
            <Head title={user.name} />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <Heading
                            title={user.name}
                            description={`${user.role?.label ?? 'No role'} · ${user.jurisdiction}`}
                        />
                        <UserStatusBadges statuses={user.statuses} />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can.update && (
                            <Button
                                variant="outline"
                                onClick={() => setDialog('profile')}
                            >
                                Edit profile
                            </Button>
                        )}
                        {can.changeRole && (
                            <Button
                                variant="outline"
                                onClick={() => setDialog('jurisdiction')}
                            >
                                Change role / jurisdiction
                            </Button>
                        )}
                        {can.issueSetupLink && (
                            <Button
                                variant="outline"
                                onClick={() => setDialog('setupLink')}
                            >
                                Issue setup link
                            </Button>
                        )}
                        {can.unlock && locked && (
                            <Button
                                variant="outline"
                                onClick={() => setDialog('unlock')}
                            >
                                Unlock
                            </Button>
                        )}
                        {can.resetTwoFactor && user.two_factor_confirmed_at && (
                            <Button
                                variant="outline"
                                onClick={() => setDialog('resetTwoFactor')}
                            >
                                Reset 2FA
                            </Button>
                        )}
                        {can.deactivate && (
                            <Button
                                variant="destructive"
                                onClick={() => setDialog('deactivate')}
                            >
                                Deactivate
                            </Button>
                        )}
                        {can.reactivate && (
                            <Button onClick={() => setDialog('reactivate')}>
                                Reactivate
                            </Button>
                        )}
                    </div>
                </div>

                <nav className="flex gap-1 border-b" aria-label="User sections">
                    {tabs.map((name) => (
                        <Link
                            key={name}
                            href={UserController.show(user.ulid, {
                                query: { tab: name },
                            })}
                            preserveScroll
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm',
                                tab === name
                                    ? 'border-primary font-medium'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {tabLabels[name]}
                        </Link>
                    ))}
                </nav>

                {tab === 'profile' && <ProfileTab user={user} />}
                {tab === 'postings' && props.postings && (
                    <PostingsTab postings={props.postings} />
                )}
                {tab === 'logins' && props.logins && (
                    <div className="space-y-4">
                        <LoginHistoryTable entries={props.logins.data} />
                        <Pagination page={props.logins} />
                    </div>
                )}
                {tab === 'activity' && props.activity && (
                    <div className="space-y-4">
                        <ActivityTable entries={props.activity.data} />
                        <Pagination page={props.activity} />
                    </div>
                )}
            </div>

            {can.update && (
                <EditProfileDialog
                    user={user}
                    open={dialog === 'profile'}
                    onOpenChange={close}
                />
            )}
            {can.changeRole && props.jurisdictionOptions && (
                <ChangeJurisdictionDialog
                    user={user}
                    options={props.jurisdictionOptions}
                    open={dialog === 'jurisdiction'}
                    onOpenChange={close}
                />
            )}
            <SetupLinkDialog
                userUlid={user.ulid}
                userEmail={user.email}
                mailEnabled={props.mailEnabled}
                open={dialog === 'setupLink'}
                onOpenChange={close}
                intro={
                    props.userCreated
                        ? 'The account was created. Choose how the user receives their one-time setup link (valid for 72 hours).'
                        : undefined
                }
            />
            <ConfirmActionDialog
                open={dialog === 'deactivate'}
                onOpenChange={close}
                title={`Deactivate ${user.name}?`}
                description="The user is signed out immediately and cannot sign in until reactivated. Their current posting ends."
                confirmLabel="Deactivate"
                url={UserController.deactivate(user.ulid).url}
                destructive
                requireReason
            />
            <ConfirmActionDialog
                open={dialog === 'reactivate'}
                onOpenChange={close}
                title={`Reactivate ${user.name}?`}
                description="The user can sign in again, and a new posting starts with their current role and jurisdiction."
                confirmLabel="Reactivate"
                url={UserController.reactivate(user.ulid).url}
                requireReason
            />
            <ConfirmActionDialog
                open={dialog === 'unlock'}
                onOpenChange={close}
                title={`Unlock ${user.name}?`}
                description="Clears the lock after failed sign-in attempts and resets the failure counters."
                confirmLabel="Unlock"
                url={UserController.unlock(user.ulid).url}
            />
            <ConfirmActionDialog
                open={dialog === 'resetTwoFactor'}
                onOpenChange={close}
                title={`Reset two-factor authentication for ${user.name}?`}
                description="The user is signed out everywhere and must set up two-factor authentication again at their next sign-in."
                confirmLabel="Reset 2FA"
                url={UserController.resetTwoFactor(user.ulid).url}
                destructive
            />
        </>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{children ?? '—'}</dd>
        </div>
    );
}

function ProfileTab({ user }: { user: UserDetail }) {
    return (
        <dl className="grid gap-4 md:grid-cols-3">
            <Field label="Email">{user.email}</Field>
            <Field label="Phone">{user.phone}</Field>
            <Field label="Service number">{user.service_number}</Field>
            <Field label="Rank">{user.rank}</Field>
            <Field label="Role">{user.role?.label}</Field>
            <Field label="Jurisdiction">{user.jurisdiction}</Field>
            <Field label="Account created">
                {formatDateTime(user.created_at)}
            </Field>
            <Field label="Password set">
                {user.password_changed_at
                    ? formatDateTime(user.password_changed_at)
                    : 'Setup not completed'}
            </Field>
            <Field label="Two-factor authentication">
                {user.two_factor_confirmed_at
                    ? `Enrolled ${formatDateTime(user.two_factor_confirmed_at)}`
                    : 'Not enrolled'}
            </Field>
            <Field label="Last login">
                {user.last_login_at
                    ? `${formatDateTime(user.last_login_at)} from ${user.last_login_ip ?? '?'}`
                    : 'Never'}
            </Field>
            {user.locked_until && (
                <Field label="Locked until">
                    {formatDateTime(user.locked_until)}
                </Field>
            )}
            {!user.is_active && (
                <>
                    <Field label="Deactivated">
                        {formatDateTime(user.deactivated_at)}
                    </Field>
                    <Field label="Reason">{user.deactivation_reason}</Field>
                </>
            )}
        </dl>
    );
}

function PostingsTab({ postings }: { postings: Posting[] }) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="px-3 py-2 font-medium">Role</th>
                        <th className="px-3 py-2 font-medium">Jurisdiction</th>
                        <th className="px-3 py-2 font-medium">From</th>
                        <th className="px-3 py-2 font-medium">To</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {postings.length === 0 && (
                        <tr>
                            <td
                                colSpan={4}
                                className="px-3 py-6 text-center text-muted-foreground"
                            >
                                No postings
                            </td>
                        </tr>
                    )}
                    {postings.map((posting) => (
                        <tr key={posting.ulid}>
                            <td className="px-3 py-2">{posting.role}</td>
                            <td className="px-3 py-2">
                                {posting.jurisdiction}
                            </td>
                            <td className="px-3 py-2">
                                {formatDateTime(posting.started_at)}
                            </td>
                            <td className="px-3 py-2">
                                {posting.ended_at
                                    ? formatDateTime(posting.ended_at)
                                    : 'Current'}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function EditProfileDialog({
    user,
    open,
    onOpenChange,
}: {
    user: UserDetail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({
        name: user.name,
        email: user.email,
        phone: user.phone ?? '',
        service_number: user.service_number ?? '',
        rank: user.rank ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(UserController.update(user.ulid), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const fields: {
        key: keyof typeof form.data;
        label: string;
        type?: string;
    }[] = [
        { key: 'name', label: 'Full name' },
        { key: 'email', label: 'Email address', type: 'email' },
        { key: 'phone', label: 'Phone' },
        { key: 'service_number', label: 'Service number' },
        { key: 'rank', label: 'Rank' },
    ];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Edit profile</DialogTitle>
                    </DialogHeader>
                    {fields.map((field) => (
                        <div key={field.key} className="grid gap-2">
                            <Label htmlFor={`profile-${field.key}`}>
                                {field.label}
                            </Label>
                            <Input
                                id={`profile-${field.key}`}
                                type={field.type ?? 'text'}
                                value={form.data[field.key]}
                                onChange={(event) =>
                                    form.setData(field.key, event.target.value)
                                }
                            />
                            <InputError message={form.errors[field.key]} />
                        </div>
                    ))}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ChangeJurisdictionDialog({
    user,
    options,
    open,
    onOpenChange,
}: {
    user: UserDetail;
    options: JurisdictionFormOptions;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({
        role: user.role?.value ?? '',
        state: user.state?.ulid ?? '',
        lga: user.lga?.ulid ?? '',
        zones: user.zones.map((zone) => zone.ulid),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(UserController.updateJurisdiction(user.ulid), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Change role or jurisdiction</DialogTitle>
                    </DialogHeader>
                    <p className="text-sm text-muted-foreground">
                        The current posting ends and a new one starts. The
                        change is recorded in the audit log.
                    </p>
                    <JurisdictionFields
                        options={options}
                        values={form.data}
                        errors={form.errors}
                        onChange={(values) => form.setData(values)}
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

ShowUser.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Users', href: UserController.index() },
        { title: props.user.name, href: UserController.show(props.user.ulid) },
    ],
});
