import { useEffect, useRef, type ReactNode } from 'react';
import Button from './button';

type Props = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    /** Shown while the action runs. */
    busy?: boolean;
    destructive?: boolean;
    onConfirm: () => void;
    onCancel: () => void;
};

/** A small modal that asks before something that cannot be undone. Render it only while it is needed. */
export default function ConfirmDialog({ title, description, confirmLabel, busy = false, destructive = true, onConfirm, onCancel }: Props) {
    const dialog = useRef<HTMLDialogElement>(null);

    useEffect(() => { dialog.current?.showModal(); }, []);

    return (
        <dialog
            ref={dialog}
            aria-labelledby="confirm-title"
            aria-describedby="confirm-description"
            className="pm-dialog"
            style={{ width: 'min(440px, calc(100vw - 32px))' }}
            onCancel={(event) => { if (busy) event.preventDefault(); else onCancel(); }}
            onClose={onCancel}
        >
            <h2 id="confirm-title" className="text-xl font-medium break-words">{title}</h2>
            <div id="confirm-description" className="mt-3 text-sm text-[var(--pm-muted)]">{description}</div>
            <div className="pm-button-group mt-6 justify-end">
                <Button type="button" variant="secondary" disabled={busy} onClick={onCancel}>Cancel</Button>
                <Button type="button" variant={destructive ? 'danger' : 'primary'} loading={busy} loadingLabel={`${confirmLabel}…`} onClick={onConfirm}>{confirmLabel}</Button>
            </div>
        </dialog>
    );
}
