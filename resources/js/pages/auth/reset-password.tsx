import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Button from '../../components/ui/button';
import Field from '../../components/ui/field';
import AuthLayout from '../../layouts/auth-layout';

type ResetPasswordProps = {
    token: string;
    email: string;
};

export default function ResetPassword({ token, email }: ResetPasswordProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        post('/reset-password', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    }

    return (
        <AuthLayout
            title="Reset your password"
            description="Choose a new password for your account."
            footer={<Link href="/login" className="pm-link">Back to log in</Link>}
        >
            <form onSubmit={submit} className="space-y-4">
                <Field
                    id="email"
                    label="Email"
                    type="email"
                    autoComplete="email"
                    value={data.email}
                    onChange={(event) => setData('email', event.target.value)}
                    error={errors.email ?? errors.token}
                    required
                />
                <Field
                    id="password"
                    label="New password"
                    type="password"
                    autoComplete="new-password"
                    value={data.password}
                    onChange={(event) => setData('password', event.target.value)}
                    error={errors.password}
                    required
                />
                <Field
                    id="password_confirmation"
                    label="Confirm password"
                    type="password"
                    autoComplete="new-password"
                    value={data.password_confirmation}
                    onChange={(event) => setData('password_confirmation', event.target.value)}
                    error={errors.password_confirmation}
                    required
                />

                <Button type="submit" loading={processing} loadingLabel="Resetting password" className="w-full">
                    Reset password
                </Button>
            </form>
        </AuthLayout>
    );
}
