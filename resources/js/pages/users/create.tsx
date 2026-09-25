import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import JurisdictionFields from '@/components/users/jurisdiction-fields';
import type { JurisdictionFormOptions } from '@/types/users';

export default function CreateUser(options: JurisdictionFormOptions) {
    const form = useForm({
        name: '',
        email: '',
        phone: '',
        service_number: '',
        rank: '',
        role: '',
        state: '',
        lga: '',
        zones: [] as string[],
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(UserController.store());
    };

    const text = (
        field: 'name' | 'email' | 'phone' | 'service_number' | 'rank',
        label: string,
        props: { type?: string; required?: boolean } = {},
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={field}>{label}</Label>
            <Input
                id={field}
                type={props.type ?? 'text'}
                value={form.data[field]}
                onChange={(event) => form.setData(field, event.target.value)}
                required={props.required}
            />
            <InputError message={form.errors[field]} />
        </div>
    );

    return (
        <>
            <Head title="New user" />

            <form onSubmit={submit} className="max-w-2xl space-y-8 p-4">
                <Heading
                    title="New user"
                    description="The account is activated through a one-time setup link, which you send or hand over next."
                />

                <section className="grid gap-4 md:grid-cols-2">
                    {text('name', 'Full name', { required: true })}
                    {text('email', 'Email address', {
                        type: 'email',
                        required: true,
                    })}
                    {text('phone', 'Phone')}
                    {text('service_number', 'Service number')}
                    {text('rank', 'Rank')}
                </section>

                <section className="space-y-2">
                    <h2 className="text-sm font-semibold">
                        Role and jurisdiction
                    </h2>
                    <JurisdictionFields
                        options={options}
                        values={{
                            role: form.data.role,
                            state: form.data.state,
                            lga: form.data.lga,
                            zones: form.data.zones,
                        }}
                        errors={form.errors}
                        onChange={(values) =>
                            form.setData({ ...form.data, ...values })
                        }
                    />
                </section>

                <Button
                    type="submit"
                    disabled={form.processing}
                    data-test="create-user-button"
                >
                    {form.processing && <Spinner />}
                    Create user
                </Button>
            </form>
        </>
    );
}

CreateUser.layout = {
    breadcrumbs: [
        { title: 'Users', href: UserController.index() },
        { title: 'New user', href: UserController.create() },
    ],
};
