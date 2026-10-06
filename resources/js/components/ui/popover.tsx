import { useEffect, useRef, useState, type ReactNode } from 'react';

type Props = {
    /** Content of the chip that opens the popover. */
    label: ReactNode;
    active?: boolean;
    ariaLabel: string;
    children: (close: () => void) => ReactNode;
};

export default function Popover({ label, active = false, ariaLabel, children }: Props) {
    const [open, setOpen] = useState(false);
    const root = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;

        function closeOnOutsideClick(event: MouseEvent) {
            if (!root.current?.contains(event.target as Node)) setOpen(false);
        }
        function closeOnEscape(event: KeyboardEvent) {
            if (event.key === 'Escape') setOpen(false);
        }

        document.addEventListener('mousedown', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('mousedown', closeOnOutsideClick);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [open]);

    return (
        <div ref={root} className="relative">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                aria-haspopup="dialog"
                aria-label={ariaLabel}
                className={`pm-chip ${active ? 'pm-chip--active' : ''}`}
            >
                {label}
            </button>
            {open && <div role="dialog" aria-label={ariaLabel} className="pm-popover">{children(() => setOpen(false))}</div>}
        </div>
    );
}
