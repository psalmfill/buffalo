import { Head, Link, useForm } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import StepUpController from '@/actions/App/Http/Controllers/Auth/StepUpController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { dashboard } from '@/routes';

type Props = {
    intended: string | null;
    minutes: number;
};

/**
 * Step-up page (Phase 8B.2): shown when a plain link, such as a download,
 * needs a fresh code. After confirming, the browser continues to the
 * original address.
 */
export default function StepUp({ intended, minutes }: Props) {
    const form = useForm({ code: '', intended: intended ?? '' });

    return (
        <>
            <Head title="Confirm it is you" />
            <div className="flex flex-col gap-6 text-center">
                <div className="space-y-1">
                    <h1 className="text-lg font-semibold">Confirm it is you</h1>
                    <p className="text-sm text-muted-foreground">
                        Enter the current code from your authenticator app to
                        continue. You will not be asked again for {minutes}{' '}
                        minutes. Recovery codes cannot be used here.
                    </p>
                </div>
                <form
                    className="flex flex-col items-center gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(StepUpController.store.url(), {
                            onError: () => form.setData('code', ''),
                        });
                    }}
                >
                    <InputOTP
                        maxLength={6}
                        value={form.data.code}
                        onChange={(value) => form.setData('code', value)}
                        disabled={form.processing}
                        pattern={REGEXP_ONLY_DIGITS}
                        autoFocus
                    >
                        <InputOTPGroup>
                            {Array.from({ length: 6 }, (_, index) => (
                                <InputOTPSlot key={index} index={index} />
                            ))}
                        </InputOTPGroup>
                    </InputOTP>
                    <InputError message={form.errors.code} />
                    <Button
                        type="submit"
                        className="w-full"
                        disabled={
                            form.processing || form.data.code.length !== 6
                        }
                    >
                        Continue
                    </Button>
                </form>
                <Link
                    href={dashboard()}
                    className="text-sm text-muted-foreground underline underline-offset-4"
                >
                    Cancel
                </Link>
            </div>
        </>
    );
}
