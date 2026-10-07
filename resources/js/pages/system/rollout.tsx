import { Head } from '@inertiajs/react';
import { useState } from 'react';
import RolloutController from '@/actions/App/Http/Controllers/System/RolloutController';
import Heading from '@/components/heading';
import OnboardingTable from '@/components/rollout/onboarding-table';
import { Button } from '@/components/ui/button';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import type { OnboardingRow } from '@/types/rollout';

type Target = NonNullable<OnboardingRow['state']>;

/**
 * Pilot rollout (Phase 9B.3): switch states live one at a time, with a
 * reason and step-up. Until its state is live, a state or LGA officer
 * cannot sign in (they can still complete account setup and enrol 2FA).
 */
export default function Rollout({ states }: { states: OnboardingRow[] }) {
    const [target, setTarget] = useState<Target | null>(null);
    const live = states.filter((row) => row.state?.is_live).length;

    return (
        <>
            <Head title="Rollout" />
            <div className="flex flex-col gap-6 p-4">
                <Heading
                    help={{ topic: 'roles/super-admin', section: 'rollout' }}
                    title="Rollout"
                    description={`${live} of ${states.length - 1} states live. Officers in a state that is not live cannot sign in yet; national users are always active.`}
                />

                <OnboardingTable
                    rows={states}
                    detailed
                    action={(row) =>
                        row.state && (
                            <Button
                                size="sm"
                                variant={
                                    row.state.is_live ? 'outline' : 'default'
                                }
                                onClick={() => setTarget(row.state)}
                                data-test={`rollout-${row.state.code}`}
                            >
                                {row.state.is_live
                                    ? 'Set not live'
                                    : 'Make live'}
                            </Button>
                        )
                    }
                />
            </div>

            {target && (
                <ConfirmActionDialog
                    open
                    onOpenChange={(open) => !open && setTarget(null)}
                    title={
                        target.is_live
                            ? `Set ${target.name} back to not live?`
                            : `Make ${target.name} live?`
                    }
                    description={
                        target.is_live
                            ? `Officers in ${target.name} will be signed out on their next action and cannot sign in until the state is live again.`
                            : `Officers in ${target.name} will be able to sign in. You will be asked for your authenticator code.`
                    }
                    confirmLabel={target.is_live ? 'Set not live' : 'Make live'}
                    destructive={target.is_live}
                    requireReason
                    method="put"
                    data={{ live: !target.is_live }}
                    url={RolloutController.update(target.ulid).url}
                />
            )}
        </>
    );
}

Rollout.layout = {
    breadcrumbs: [{ title: 'Rollout', href: RolloutController.index() }],
};
