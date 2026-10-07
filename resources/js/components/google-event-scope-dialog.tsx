import { useEffect, useRef, useState, type ReactNode } from 'react';
import type { EditScope } from '../lib/google-events';
import Button from './ui/button';

type Option = { value: EditScope; label: string; hint: string };

type Props = {
    title: string;
    description?: ReactNode;
    options: Option[];
    confirmLabel: string;
    destructive?: boolean;
    busy: boolean;
    error: string;
    onConfirm: (scope: EditScope) => void;
    onCancel: () => void;
};

/** Asks whether a change to a repeating event is for just that event or for the whole series. */
export default function GoogleEventScopeDialog({ title, description, options, confirmLabel, destructive = false, busy, error, onConfirm, onCancel }: Props) {
    const dialog = useRef<HTMLDialogElement>(null);
    const [scope, setScope] = useState<EditScope>(options[0].value);

    useEffect(() => { dialog.current?.showModal(); }, []);

    return (
        <dialog
            ref={dialog}
            aria-labelledby="event-scope-title"
            className="pm-dialog"
            style={{ width: 'min(460px, calc(100vw - 32px))' }}
            onCancel={(event) => { if (busy) event.preventDefault(); else onCancel(); }}
            onClose={onCancel}
        >
            <h2 id="event-scope-title" className="text-xl font-medium break-words">{title}</h2>
            {description && <div className="mt-3 text-sm text-[var(--pm-muted)]">{description}</div>}

            <fieldset className="mt-5 space-y-3" disabled={busy}>
                <legend className="sr-only">Which events</legend>
                {options.map(option => (
                    <label key={option.value} className={`flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition-colors ${scope === option.value ? 'border-[var(--pm-text)] bg-[var(--pm-background)]/60' : 'border-[var(--pm-border)]'}`}>
                        <input type="radio" name="event-scope" value={option.value} checked={scope === option.value} onChange={() => setScope(option.value)} className="mt-1 accent-[var(--pm-text)]" />
                        <span className="text-sm font-medium">{option.label}<span className="mt-0.5 block text-xs font-normal text-[var(--pm-muted)]">{option.hint}</span></span>
                    </label>
                ))}
            </fieldset>

            {error && <p role="alert" className="mt-4 text-sm text-red-700">{error}</p>}

            <div className="pm-button-group mt-6 justify-end">
                <Button type="button" variant="secondary" disabled={busy} onClick={onCancel}>Cancel</Button>
                <Button type="button" variant={destructive ? 'danger' : 'primary'} loading={busy} loadingLabel={`${confirmLabel}…`} onClick={() => onConfirm(scope)}>{confirmLabel}</Button>
            </div>
        </dialog>
    );
}
