import { Form, Head } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import TwoFactorSetupController from '@/actions/App/Http/Controllers/Auth/TwoFactorSetupController';
import AlertError from '@/components/alert-error';
import InputError from '@/components/input-error';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { logout } from '@/routes';
import { enable } from '@/routes/two-factor';

type Props = {
    twoFactorConfirmed: boolean;
    hasSecret: boolean;
};

/**
 * Mandatory two-factor enrolment. Signed-in users without confirmed 2FA are
 * sent here and cannot reach anything else until they finish.
 */
export default function TwoFactorSetup({ twoFactorConfirmed }: Props) {
    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState(false);
    const [saved, setSaved] = useState(false);

    useEffect(() => {
        if (twoFactorConfirmed && recoveryCodesList.length === 0) {
            void fetchRecoveryCodes();
        }
    }, [twoFactorConfirmed, recoveryCodesList.length, fetchRecoveryCodes]);

    return (
        <>
            <Head title="Set up two-factor authentication" />

            {twoFactorConfirmed ? (
                <div className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        Two-factor authentication is set up. Save these recovery
                        codes somewhere safe and offline. Each code can be used
                        once if you lose your authenticator. They will not be
                        shown again.
                    </p>

                    {errors.length > 0 && <AlertError errors={errors} />}

                    <div
                        className="grid grid-cols-2 gap-2 rounded-lg bg-muted p-4 font-mono text-sm"
                        data-test="recovery-codes"
                    >
                        {recoveryCodesList.map((code) => (
                            <div key={code}>{code}</div>
                        ))}
                    </div>

                    <Form
                        {...TwoFactorSetupController.complete.form()}
                        className="space-y-4"
                    >
                        {({ processing, errors: formErrors }) => (
                            <>
                                <div className="flex items-center gap-3">
                                    <Checkbox
                                        id="saved"
                                        name="saved"
                                        value="1"
                                        checked={saved}
                                        onCheckedChange={(checked) =>
                                            setSaved(checked === true)
                                        }
                                    />
                                    <Label htmlFor="saved">
                                        I have saved these codes
                                    </Label>
                                </div>
                                <InputError message={formErrors.saved} />
                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={!saved || processing}
                                    data-test="finish-two-factor-setup"
                                >
                                    Continue
                                </Button>
                            </>
                        )}
                    </Form>
                </div>
            ) : (
                <div className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        Two-factor authentication is required. You will need an
                        authenticator app (TOTP) on your phone. Each time you
                        sign in you will enter a six-digit code from the app.
                    </p>

                    {hasSetupData ? (
                        <Button
                            className="w-full"
                            onClick={() => setShowSetupModal(true)}
                        >
                            <ShieldCheck />
                            Continue setup
                        </Button>
                    ) : (
                        <Form
                            {...enable.form()}
                            onSuccess={() => setShowSetupModal(true)}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={processing}
                                    data-test="start-two-factor-setup"
                                >
                                    <ShieldCheck />
                                    Set up two-factor authentication
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
            )}

            <TwoFactorSetupModal
                isOpen={showSetupModal}
                onClose={() => setShowSetupModal(false)}
                requiresConfirmation={true}
                twoFactorEnabled={twoFactorConfirmed}
                qrCodeSvg={qrCodeSvg}
                manualSetupKey={manualSetupKey}
                clearSetupData={clearSetupData}
                fetchSetupData={fetchSetupData}
                errors={errors}
            />

            <Form {...logout.form()} className="text-center">
                <button
                    type="submit"
                    className="text-sm text-muted-foreground underline underline-offset-4"
                >
                    Log out
                </button>
            </Form>
        </>
    );
}

TwoFactorSetup.layout = {
    title: 'Set up two-factor authentication',
    description: 'Required before you can use BUFFALO',
};
