import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Button from '../../components/ui/button';
import Field from '../../components/ui/field';
import AuthLayout from '../../layouts/auth-layout';

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        post('/register', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    }

    return (
        <AuthLayout
            title="Create your account"
            footer={<>Already have an account? <Link href="/login" className="pm-link">Log in</Link></>}
        >
            <form onSubmit={submit} className="space-y-4">
                <Field
                    id="name"
                    label="Name"
                    autoComplete="name"
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    error={errors.name}
                    required
                />
                <Field
                    id="email"
                    label="Email"
                    type="email"
                    autoComplete="email"
                    value={data.email}
                    onChange={(event) => setData('email', event.target.value)}
                    error={errors.email}
                    required
                />
                <Field
                    id="password"
                    label="Password"
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

                <Button type="submit" loading={processing} loadingLabel="Creating account" className="w-full">
                    Create account
                </Button>
            </form>
        </AuthLayout>
    );
}
