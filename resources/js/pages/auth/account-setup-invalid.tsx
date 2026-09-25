import { Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { login } from '@/routes';

/**
 * Shown for every unusable setup link (unknown, expired, already used or
 * for an inactive account): deliberately does not say which.
 */
export default function AccountSetupInvalid() {
    return (
        <>
            <Head title="Link invalid" />

            <div className="space-y-4 text-center text-sm text-muted-foreground">
                <p>
                    This link is invalid or has expired. Contact your
                    administrator.
                </p>
                <TextLink href={login()}>Go to the login page</TextLink>
            </div>
        </>
    );
}

AccountSetupInvalid.layout = {
    title: 'Account setup',
    description: '',
};
