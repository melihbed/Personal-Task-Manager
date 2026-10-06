import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useToast } from '../components/ui/toast';

export const UNDO_WINDOW_MS = 6000;

/** Sends the delete without waiting, and survives the page closing. Used when leaving before the undo window ends. */
function deleteInBackground(url: string) {
    const token = decodeURIComponent(document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

    void fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'X-XSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    });
}

/**
 * Delete with an undo window. The item is hidden at once; the delete is sent after UNDO_WINDOW_MS unless
 * the user presses Undo. If the page is left first, the pending deletes are sent immediately.
 */
export function useUndoableDelete(urlFor: (id: number) => string, message: string) {
    const toast = useToast();
    const [pending, setPending] = useState<Set<number>>(new Set());
    const timers = useRef(new Map<number, number>());

    const release = useCallback((id: number) => {
        setPending(current => {
            const next = new Set(current);
            next.delete(id);

            return next;
        });
    }, []);

    const schedule = useCallback((id: number) => {
        setPending(current => new Set(current).add(id));

        const timer = window.setTimeout(() => {
            timers.current.delete(id);
            router.delete(urlFor(id), {
                preserveScroll: true,
                onError: () => {
                    release(id);
                    toast.show({ message: 'Could not delete that. It was put back.' });
                },
                onFinish: () => release(id),
            });
        }, UNDO_WINDOW_MS);

        timers.current.set(id, timer);
        toast.show({
            message,
            actionLabel: 'Undo',
            durationMs: UNDO_WINDOW_MS,
            onAction: () => {
                window.clearTimeout(timers.current.get(id));
                timers.current.delete(id);
                release(id);
            },
        });
    }, [message, release, toast, urlFor]);

    useEffect(() => {
        const timersInUse = timers.current;

        function flush() {
            for (const [id, timer] of timersInUse) {
                window.clearTimeout(timer);
                deleteInBackground(urlFor(id));
            }
            timersInUse.clear();
        }

        window.addEventListener('pagehide', flush);

        return () => {
            window.removeEventListener('pagehide', flush);
            flush();
        };
    }, [urlFor]);

    return { pending, schedule };
}
