import { Form } from '@inertiajs/react';
import { LockKeyhole, RefreshCw } from 'lucide-react';
import AlertError from '@/components/alert-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

/**
 * Two-factor authentication is mandatory and cannot be disabled. Users may
 * only generate new recovery codes (the old ones stop working), which are
 * shown once.
 */
export default function RegenerateRecoveryCodes() {
    const { recoveryCodesList, fetchRecoveryCodes, errors } =
        useTwoFactorAuth();

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex gap-3">
                    <LockKeyhole className="size-4" aria-hidden="true" />
                    Recovery codes
                </CardTitle>
                <CardDescription>
                    Recovery codes let you sign in if you lose your
                    authenticator. Generating new codes invalidates your old
                    ones. New codes are shown once: store them offline.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <Form
                    {...regenerateRecoveryCodes.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => void fetchRecoveryCodes()}
                >
                    {({ processing }) => (
                        <Button
                            variant="secondary"
                            type="submit"
                            disabled={processing}
                            data-test="regenerate-recovery-codes"
                        >
                            <RefreshCw /> Generate new recovery codes
                        </Button>
                    )}
                </Form>

                {errors.length > 0 && <AlertError errors={errors} />}

                {recoveryCodesList.length > 0 && (
                    <div className="grid grid-cols-2 gap-2 rounded-lg bg-muted p-4 font-mono text-sm">
                        {recoveryCodesList.map((code) => (
                            <div key={code}>{code}</div>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
