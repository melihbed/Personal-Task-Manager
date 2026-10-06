import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Button from '../../components/ui/button';
import Field from '../../components/ui/field';
import AuthLayout from '../../layouts/auth-layout';

export default function Login() {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        post('/login', {
            onFinish: () => reset('password'),
        });
    }

    return (
        <AuthLayout
            title="Welcome back"
            description="Log in to your personal task manager."
            footer={<>Need an account? <Link href="/register" className="pm-link">Register</Link></>}
        >
            <form onSubmit={submit} className="space-y-4">
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

                <div>
                    <Field
                        id="password"
                        label="Password"
                        type="password"
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                        error={errors.password}
                        required
                    />
                    <div className="mt-2 flex justify-end text-sm">
                        <Link href="/forgot-password" className="pm-link">Forgot password</Link>
                    </div>
                </div>

                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={data.remember}
                        onChange={(event) => setData('remember', event.target.checked)}
                        className="accent-[var(--pm-text)]"
                    />
                    Remember me
                </label>

                <Button type="submit" loading={processing} loadingLabel="Logging in" className="w-full">
                    Log in
                </Button>
            </form>
        </AuthLayout>
    );
}
