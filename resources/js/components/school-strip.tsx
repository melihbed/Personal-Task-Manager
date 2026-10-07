import { Link } from '@inertiajs/react';
import { formatDue } from '../lib/deadlines';
import { courseTitle } from '../lib/school';

type Props = {
    school: { overdue: number; dueSoon: { id: number; name: string; course_name: string; due_at: string; url: string | null }[] };
    timezone: string;
};

/** Canvas work due in the next two days, and a count of what is overdue. Hidden when there is nothing to say. */
export default function SchoolStrip({ school, timezone }: Props) {
    if (school.overdue === 0 && school.dueSoon.length === 0) return null;

    return (
        <section aria-labelledby="school-strip-title" className="pm-card mb-6 !p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 id="school-strip-title" className="text-lg font-medium">School · due soon</h2>
                <div className="flex items-center gap-4 text-sm">
                    {school.overdue > 0 && <span className="pm-due pm-due--overdue">{school.overdue} overdue</span>}
                    <Link href="/school" className="pm-button pm-button--secondary pm-button--small">See all</Link>
                </div>
            </div>
            {school.dueSoon.length > 0 && (
                <ul className="mt-3 divide-y divide-[var(--pm-border)]">
                    {school.dueSoon.map(item => (
                        <li key={item.id} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2 first:pt-0 last:pb-0">
                            <span className="min-w-0 text-sm">
                                <span className="font-medium">{item.name}</span>
                                <span className="ml-2 text-xs text-[var(--pm-muted)]">{courseTitle(item.course_name)}</span>
                            </span>
                            <span className="text-xs text-[var(--pm-muted)]">{formatDue(item.due_at, true, timezone)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
