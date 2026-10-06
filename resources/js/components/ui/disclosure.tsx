import { useId, useState, type ReactNode } from 'react';

type Props = {
    title: ReactNode;
    children: ReactNode;
    defaultOpen?: boolean;
};

/** A row that expands to show more. Use several in a list for help topics. */
export default function Disclosure({ title, children, defaultOpen = false }: Props) {
    const [open, setOpen] = useState(defaultOpen);
    const panel = useId();

    return (
        <div className="border-t border-[var(--pm-border)] first:border-t-0">
            <button
                type="button"
                aria-expanded={open}
                aria-controls={panel}
                onClick={() => setOpen(!open)}
                className="flex w-full cursor-pointer items-start gap-3 py-3.5 text-left"
            >
                <svg aria-hidden="true" className={`mt-1 h-3.5 w-3.5 shrink-0 text-[var(--pm-muted)] transition-transform duration-200 ${open ? 'rotate-90' : ''}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"><path d="m9 5 7 7-7 7" /></svg>
                <span className="text-sm font-medium">{title}</span>
            </button>
            <div id={panel} className="pm-collapse" data-open={open}>
                <div inert={!open}>
                    <div className="pb-4 pl-[26px] text-sm">{children}</div>
                </div>
            </div>
        </div>
    );
}
