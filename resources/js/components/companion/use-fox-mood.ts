import { useEffect, useState } from 'react';
import { usePomodoro } from '../pomodoro/pomodoro-context';
import { useCompanion } from './companion-context';
import type { FoxMood } from './fox';

/** Late at night the fox is asleep. */
function useIsNight(): boolean {
    const check = () => { const hour = new Date().getHours(); return hour >= 23 || hour < 6; };
    const [night, setNight] = useState(check);

    useEffect(() => {
        const timer = window.setInterval(() => setNight(check()), 60_000);

        return () => window.clearInterval(timer);
    }, []);

    return night;
}

/** What the fox is doing right now, from what the app is doing: good news first, then the assistant, then the timer, then the time of day. */
export function useFoxMood(): FoxMood {
    const { celebrating, thinking } = useCompanion();
    const timer = usePomodoro();
    const night = useIsNight();
    const active = timer.state?.active ?? null;
    const running = active?.status === 'running';

    if (celebrating) return 'celebrate';
    if (thinking) return 'work';
    if (running && active.kind === 'focus') return 'focus';
    if (running) return 'break';

    return night ? 'sleep' : 'idle';
}
