import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';

type Props = { title: string; children: ReactNode; backHref?: string };

export default function AppLayout({ title, children, backHref }: Props) {
    const { url } = usePage();
    const [sidebarOpen, setSidebarOpen] = useState(true);
    const onHome = url.split('?')[0] === '/';

    useEffect(() => {
        if (!sidebarOpen) return;

        function closeOnEscape(event: KeyboardEvent) {
            if (event.key === 'Escape') setSidebarOpen(false);
        }

        document.addEventListener('keydown', closeOnEscape);
        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [sidebarOpen]);

    return (
        <div className="min-h-dvh bg-[var(--pm-background)] text-[var(--pm-text)]">
            <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:bg-white focus:p-3">Skip to content</a>
            <div className="flex min-h-dvh">
                <aside
                    id="app-sidebar"
                    hidden={!sidebarOpen}
                    className="pm-sidebar"
                >                    <div className="flex items-center justify-between p-6">
                        <Link href="/" onClick={() => {
                            if (window.innerWidth < 768) setSidebarOpen(false);
                        }} className="flex items-center gap-3">
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[var(--pm-accent)] text-lg font-bold text-white">P</span>
                            <span className="text-xs font-semibold tracking-wide">PERSONAL MANAGER</span>
                        </Link>
                    </div>
                    <nav aria-label="Main navigation" className="flex-1 overflow-y-auto px-4 py-6">
                        <p className="mb-3 px-4 text-xs font-medium tracking-wider text-[var(--pm-muted)] uppercase">Workspace</p>
                        <Link href="/" aria-current={onHome ? 'page' : undefined} onClick={() => {
                            if (window.innerWidth < 768) setSidebarOpen(false);
                        }} className={`flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium transition ${onHome ? 'bg-white shadow-sm' : 'hover:bg-white/60'}`}>
                            <span aria-hidden="true" className="h-2 w-2 rounded-full bg-[var(--pm-accent)]" />Dashboard
                        </Link>
                        <p className="mt-8 mb-3 px-4 text-xs font-medium tracking-wider text-[var(--pm-muted)] uppercase">Coming later</p>
                        <ul className="space-y-1">{['Inbox', 'Calendar', 'Spiritual duties', 'School', 'Workouts', 'Watch later'].map(label => <li key={label} className="px-4 py-3 text-sm text-[var(--pm-muted)]">{label}</li>)}</ul>
                    </nav>
                    <div className="p-4"><button type="button" onClick={() => router.post('/logout')} className="pm-button pm-button--secondary w-full">Log out</button></div>
                </aside>
                <div className="min-w-0 flex-1">
                    <header className="flex items-center gap-4 px-6 py-6 lg:px-10">
                        <button
                            type="button"
                            onClick={() => setSidebarOpen(open => !open)}
                            aria-label={sidebarOpen ? 'Collapse sidebar' : 'Expand sidebar'}
                            aria-expanded={sidebarOpen}
                            aria-controls="app-sidebar"
                            title={sidebarOpen ? 'Collapse sidebar' : 'Expand sidebar'}
                            className="pm-button pm-button--secondary pm-button--icon"
                        >
                            <svg
                                aria-hidden="true"
                                width="20"
                                height="20"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="1.8"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                            >
                                <rect x="3" y="4" width="18" height="16" rx="3" />
                                <path d="M9 4v16" />
                                <path
                                    d={sidebarOpen ? 'm15 9-3 3 3 3' : 'm13 9 3 3-3 3'}
                                />
                            </svg>
                        </button>

                        {/* Your existing title/back-arrow div stays here. */}                        <div className="flex min-w-0 items-center gap-3">
                            {backHref && (
                                <Link href={backHref} aria-label="Back to dashboard" title="Back to dashboard" className="pm-button pm-button--secondary pm-button--icon">
                                    <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                                        <path d="m12 19-7-7 7-7" /><path d="M5 12h14" />
                                    </svg>
                                </Link>
                            )}
                            <h1 className="text-xl font-medium break-words">{title}</h1>
                        </div>
                    </header>
                    <main id="main-content" className="px-6 pb-10 lg:px-10"><div className="mx-auto max-w-[1600px]">{children}</div></main>
                </div>
            </div>
        </div>
    );
}
