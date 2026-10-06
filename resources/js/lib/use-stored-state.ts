import { useEffect, useState } from 'react';

/**
 * State remembered in this browser (a per-viewer convenience). Storage can be unavailable or hold an
 * old value, so every read and write is guarded and the value is validated before use.
 */
export function useStoredState<T extends string | number>(key: string, initial: T, isValid: (value: unknown) => value is T): [T, (value: T) => void] {
    const [value, setValue] = useState<T>(initial);

    useEffect(() => {
        try {
            const stored = window.localStorage.getItem(key);
            const parsed: unknown = stored === null ? null : JSON.parse(stored);

            if (isValid(parsed)) setValue(parsed);
        } catch {
            // Storage is unavailable or corrupted; keep the default.
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    function update(next: T) {
        setValue(next);

        try {
            window.localStorage.setItem(key, JSON.stringify(next));
        } catch {
            // Not remembered, still works for this visit.
        }
    }

    return [value, update];
}
