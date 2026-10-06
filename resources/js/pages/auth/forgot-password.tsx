import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function ForgotPassword() {
    const { status } = usePage<{ status: string | null }>().props;
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        post('/forgot-password');
    }

    const inputClass =
        'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900';

    return (
        <main className="flex min-h-screen items-center justify-center bg-slate-100 p-6 text-slate-900">
            <div className="w-full max-w-md rounded-2xl bg-white p-8 shadow-sm">
                <h1 className="text-2xl font-semibold">Forgot your password?</h1>
                <p className="mt-2 text-sm text-slate-600">
                    Enter your email and we will send you a link to reset it.
                </p>

                {status && (
                    <p className="mt-4 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-700">
                        {status}
                    </p>
                )}

                <form onSubmit={submit} className="mt-6 space-y-4">
                    <div>
                        <label htmlFor="email" className="text-sm font-medium">
                            Email
                        </label>
                        <input
                            id="email"
                            type="email"
                            autoComplete="email"
                            value={data.email}
                            onChange={(event) =>
                                setData('email', event.target.value)
                            }
                            className={inputClass}
                            required
                        />
                        {errors.email && (
                            <p className="mt-1 text-sm text-red-600">
                                {errors.email}
                            </p>
                        )}
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full rounded-lg cursor-pointer bg-slate-900 px-4 py-3 font-medium text-white hover:bg-slate-700 disabled:opacity-50"
                    >
                        {processing ? 'Sending…' : 'Email reset link'}
                    </button>
                </form>

                <p className="mt-6 text-center text-sm text-slate-600">
                    Remembered it?{' '}
                    <Link href="/login" className="font-medium text-blue-700">
                        Back to log in
                    </Link>
                </p>
            </div>
        </main>
    );
}
