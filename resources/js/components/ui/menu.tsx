import { useEffect, useRef, useState } from 'react';

export type MenuItem = { label: string; onSelect: () => void; danger?: boolean; disabled?: boolean };

type Props = {
    label: string;
    items: MenuItem[];
    /** Content of a text button. Omit for the default "⋯" icon button. */
    trigger?: React.ReactNode;
};

/** A "⋯" button that opens a short action menu. Closes on outside click, Escape, or choosing an item. */
export default function Menu({ label, items, trigger }: Props) {
    const [open, setOpen] = useState(false);
    const root = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;

        root.current?.querySelector<HTMLButtonElement>('[role="menuitem"]:not(:disabled)')?.focus();

        function closeOnOutsideClick(event: MouseEvent) {
            if (!root.current?.contains(event.target as Node)) setOpen(false);
        }
        function closeOnEscape(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                setOpen(false);
                root.current?.querySelector<HTMLButtonElement>('[aria-haspopup]')?.focus();
            }
        }

        document.addEventListener('mousedown', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('mousedown', closeOnOutsideClick);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [open]);

    function move(event: React.KeyboardEvent, direction: 1 | -1) {
        const entries = [...(root.current?.querySelectorAll<HTMLButtonElement>('[role="menuitem"]:not(:disabled)') ?? [])];
        const index = entries.indexOf(document.activeElement as HTMLButtonElement);

        event.preventDefault();
        entries[(index + direction + entries.length) % entries.length]?.focus();
    }

    return (
        <div ref={root} className="relative shrink-0">
            <button
                type="button"
                aria-label={label}
                aria-haspopup="menu"
                aria-expanded={open}
                onClick={() => setOpen(!open)}
                className={trigger ? 'pm-button pm-button--small' : 'pm-button pm-button--secondary pm-button--small pm-button--icon'}
            >
                {trigger ?? <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="19" cy="12" r="1.6" /></svg>}
            </button>
            {open && (
                <div
                    role="menu"
                    aria-label={label}
                    className="pm-popover"
                    style={{ left: 'auto', right: 0, width: 200 }}
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowDown') move(event, 1);
                        if (event.key === 'ArrowUp') move(event, -1);
                    }}
                >
                    {items.map((item) => (
                        <button
                            key={item.label}
                            type="button"
                            role="menuitem"
                            disabled={item.disabled}
                            onClick={() => { setOpen(false); item.onSelect(); }}
                            className={`pm-popover-item ${item.danger ? 'text-[var(--pm-overdue)]' : ''}`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
