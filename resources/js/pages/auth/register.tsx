import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

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

    const inputClass =
        'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900';

    return (
        <main className="flex min-h-screen items-center justify-center bg-slate-100 p-6 text-slate-900">
            <div className="w-full max-w-md rounded-2xl bg-white p-8 shadow-sm">
                <h1 className="text-2xl font-semibold">Create your account</h1>
                <p className="mt-2 text-sm text-slate-600">

                </p>

                <form onSubmit={submit} className="mt-6 space-y-4">
                    <div>
                        <label htmlFor="name" className="text-sm font-medium">
                            Name
                        </label>
                        <input
                            id="name"
                            autoComplete="name"
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                            className={inputClass}
                            required
                        />
                        {errors.name && (
                            <p className="mt-1 text-sm text-red-600">
                                {errors.name}
                            </p>
                        )}
                    </div>

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

                    <div>
                        <label htmlFor="password" className="text-sm font-medium">
                            Password
                        </label>
                        <input
                            id="password"
                            type="password"
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(event) =>
                                setData('password', event.target.value)
                            }
                            className={inputClass}
                            required
                        />
                        {errors.password && (
                            <p className="mt-1 text-sm text-red-600">
                                {errors.password}
                            </p>
                        )}
                    </div>

                    <div>
                        <label
                            htmlFor="password_confirmation"
                            className="text-sm font-medium"
                        >
                            Confirm password
                        </label>
                        <input
                            id="password_confirmation"
                            type="password"
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(event) =>
                                setData(
                                    'password_confirmation',
                                    event.target.value,
                                )
                            }
                            className={inputClass}
                            required
                        />
                        {errors.password_confirmation && (
                            <p className="mt-1 text-sm text-red-600">
                                {errors.password_confirmation}
                            </p>
                        )}
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full rounded-lg bg-slate-900 px-4 py-3 font-medium text-white hover:bg-slate-700 disabled:opacity-50 cursor-pointer"
                    >
                        {processing ? 'Creating account…' : 'Create account'}
                    </button>
                </form>

                <p className="mt-6 text-center text-sm text-slate-600">
                    Already have an account?{' '}
                    <Link href="/login" className="font-medium text-blue-700">
                        Log in
                    </Link>
                </p>
            </div>
        </main>
    );
}
