import { useEffect, useState } from 'react';

/** The current time, refreshed every 30 seconds and when the tab becomes visible again. */
export function useNow(): Date {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const tick = () => setNow(new Date());
        const timer = window.setInterval(tick, 30_000);

        document.addEventListener('visibilitychange', tick);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', tick);
        };
    }, []);

    return now;
}
