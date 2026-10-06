import type { ReactNode } from 'react';

type Props = { title: string; description?: string; children: ReactNode; footer?: ReactNode };

export default function AuthLayout({ title, description, children, footer }: Props) {
    return (
        <main className="flex min-h-dvh items-center justify-center bg-[var(--pm-background)] p-6 text-[var(--pm-text)]">
            <div className="pm-card w-full max-w-md">
                <h1 className="text-2xl font-medium tracking-tight">{title}</h1>
                {description && <p className="mt-2 text-sm text-[var(--pm-muted)]">{description}</p>}
                <div className="mt-6">{children}</div>
                {footer && <p className="mt-6 text-center text-sm text-[var(--pm-muted)]">{footer}</p>}
            </div>
        </main>
    );
}
