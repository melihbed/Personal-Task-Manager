import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Button from '../../components/ui/button';
import Field from '../../components/ui/field';
import AuthLayout from '../../layouts/auth-layout';

export default function ForgotPassword() {
    const { status } = usePage<{ status: string | null }>().props;
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        post('/forgot-password');
    }

    return (
        <AuthLayout
            title="Forgot your password?"
            description="Enter your email and we will send you a link to reset it."
            footer={<>Remembered it? <Link href="/login" className="pm-link">Back to log in</Link></>}
        >
            {status && (
                <p role="status" className="mb-4 rounded-xl bg-green-50 px-3 py-2 text-sm text-green-700">
                    {status}
                </p>
            )}

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

                <Button type="submit" loading={processing} loadingLabel="Sending reset link" className="w-full">
                    Email reset link
                </Button>
            </form>
        </AuthLayout>
    );
}
