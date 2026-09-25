import { Form, Head } from '@inertiajs/react';
import AccountSetupController from '@/actions/App/Http/Controllers/Auth/AccountSetupController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import PasswordRequirements from '@/components/password-requirements';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    token: string;
    userName: string;
    email: string;
    passwordRules: string;
    passwordRequirements: string[];
};

export default function AccountSetup({
    token,
    userName,
    email,
    passwordRules,
    passwordRequirements,
}: Props) {
    return (
        <>
            <Head title="Set up your account" />

            <p className="text-center text-sm text-muted-foreground">
                Welcome, {userName}. Choose a password to continue. You will
                then set up two-factor authentication.
            </p>

            <Form
                {...AccountSetupController.store.form(token)}
                resetOnError={['password', 'password_confirmation']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                value={email}
                                autoComplete="username"
                                readOnly
                            />
                        </div>

                        <PasswordRequirements
                            requirements={passwordRequirements}
                        />

                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                autoFocus
                                required
                                placeholder="Password"
                                passwordrules={passwordRules}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                Confirm password
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                required
                                placeholder="Confirm password"
                                passwordrules={passwordRules}
                            />
                            <InputError
                                message={errors.password_confirmation}
                            />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            data-test="account-setup-button"
                        >
                            {processing && <Spinner />}
                            Set password and continue
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

AccountSetup.layout = {
    title: 'Set up your account',
    description: 'Choose your password',
};
