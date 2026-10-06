import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

type ToastInput = {
    message: string;
    actionLabel?: string;
    onAction?: () => void;
    /** How long it stays. The countdown bar shows this window. */
    durationMs?: number;
};
type ToastItem = ToastInput & { id: number; durationMs: number };

const ToastContext = createContext<{ show: (toast: ToastInput) => void } | null>(null);
let nextToastId = 1;

function Toast({ toast, onDone }: { toast: ToastItem; onDone: () => void }) {
    useEffect(() => {
        const timer = window.setTimeout(onDone, toast.durationMs);

        return () => window.clearTimeout(timer);
    }, [toast.durationMs, onDone]);

    return (
        <div className="pm-toast">
            <span className="min-w-0 flex-1 text-sm">{toast.message}</span>
            {toast.actionLabel && (
                <button type="button" onClick={() => { toast.onAction?.(); onDone(); }} className="pm-toast__action">{toast.actionLabel}</button>
            )}
            <span aria-hidden="true" className="pm-toast__bar" style={{ animationDuration: `${toast.durationMs}ms` }} />
        </div>
    );
}

export function ToastProvider({ children }: { children: ReactNode }) {
    const [toasts, setToasts] = useState<ToastItem[]>([]);

    const show = useCallback((toast: ToastInput) => {
        setToasts(current => [...current, { ...toast, id: nextToastId++, durationMs: toast.durationMs ?? 4000 }]);
    }, []);
    const value = useMemo(() => ({ show }), [show]);

    return (
        <ToastContext.Provider value={value}>
            {children}
            <div role="status" aria-live="polite" className="pm-toast-stack">
                {toasts.map(toast => (
                    <Toast key={toast.id} toast={toast} onDone={() => setToasts(current => current.filter(item => item.id !== toast.id))} />
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast() {
    const context = useContext(ToastContext);

    if (!context) throw new Error('useToast must be used inside a ToastProvider.');

    return context;
}
