import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { ApiError } from '../../lib/http';
import { breakLabel, controlTimer, formatClock, kindLabels, loadState, saveSettings as saveSettingsRequest, secondsLeft as computeSecondsLeft, startTimer, type Settings, type TimerKind, type TimerState } from '../../lib/pomodoro';
import { useCompanion } from '../companion/companion-context';

type Pomodoro = {
    /** Null until the first answer from the server. */
    state: TimerState | null;
    /** Seconds left on the running or paused timer, null when there is none. */
    secondsLeft: number | null;
    busy: boolean;
    error: string;
    /** The round or break that ended while this page was open, until it is acknowledged. */
    finished: TimerKind | null;
    start: (kind: TimerKind, taskId?: number | null) => Promise<void>;
    pause: () => Promise<void>;
    resume: () => Promise<void>;
    stop: () => Promise<void>;
    dismissFinished: () => void;
    updateSettings: (settings: Settings) => Promise<boolean>;
};

const PomodoroContext = createContext<Pomodoro | null>(null);

export function usePomodoro(): Pomodoro {
    const value = useContext(PomodoroContext);

    if (value === null) throw new Error('usePomodoro needs a PomodoroProvider.');

    return value;
}

/** A soft two-note chime made in the browser, so no sound file is needed. It can be blocked, which is fine. */
function playChime() {
    try {
        const audio = new AudioContext();
        const note = (frequency: number, start: number) => {
            const oscillator = audio.createOscillator();
            const gain = audio.createGain();

            oscillator.type = 'sine';
            oscillator.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, audio.currentTime + start);
            gain.gain.exponentialRampToValueAtTime(0.18, audio.currentTime + start + 0.04);
            gain.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + start + 0.9);
            oscillator.connect(gain).connect(audio.destination);
            oscillator.start(audio.currentTime + start);
            oscillator.stop(audio.currentTime + start + 1);
        };

        note(660, 0);
        note(880, 0.35);
        window.setTimeout(() => void audio.close(), 1800);
    } catch {
        // No sound; the notification and the page still say it.
    }
}

function notify(title: string, body: string) {
    try {
        if (typeof Notification !== 'undefined' && Notification.permission === 'granted' && document.hidden) new Notification(title, { body });
    } catch {
        // Notifications are optional.
    }
}

const errorText = (problem: unknown) => problem instanceof ApiError ? problem.message : 'Something went wrong. Please try again.';

/** The Pomodoro timer, shared by the Focus page, the fox in the sidebar and the browser tab title. */
export function PomodoroProvider({ children }: { children: ReactNode }) {
    const { celebrate } = useCompanion();
    const [state, setState] = useState<TimerState | null>(null);
    const [receivedAt, setReceivedAt] = useState(() => Date.now());
    const [now, setNow] = useState(() => Date.now());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [finished, setFinished] = useState<TimerKind | null>(null);
    const completing = useRef<number | null>(null);
    const baseTitle = useRef('');

    const apply = useCallback((next: TimerState) => {
        setState(next);
        setReceivedAt(Date.now());
    }, []);

    const refresh = useCallback(() => loadState().then(apply).catch(() => undefined), [apply]);

    useEffect(() => {
        baseTitle.current = document.title;
        void refresh();

        const onVisible = () => { if (!document.hidden) void refresh(); };

        document.addEventListener('visibilitychange', onVisible);

        return () => {
            document.removeEventListener('visibilitychange', onVisible);
            document.title = baseTitle.current;
        };
    }, [refresh]);

    const active = state?.active ?? null;
    const left = active ? computeSecondsLeft(active, receivedAt, now) : null;

    useEffect(() => {
        if (active?.status !== 'running') return;

        const timer = window.setInterval(() => setNow(Date.now()), 500);

        return () => window.clearInterval(timer);
    }, [active?.status, active?.id]);

    // When the clock reaches zero, ask the server to count the round. The server has the final say.
    useEffect(() => {
        if (!active || active.status !== 'running' || left === null || left > 0 || completing.current === active.id) return;

        const { id, kind } = active;

        completing.current = id;

        controlTimer(id, 'complete').then((next) => {
            apply(next);
            setFinished(kind);

            if (next.settings.sound_enabled) playChime();

            if (kind === 'focus') {
                celebrate();
                notify('Focus round done', `Take a ${breakLabel(next)}.`);
            } else {
                notify('Break over', 'Ready for the next round?');
            }
        }).catch(() => {
            // Most likely the browser clock ran a little fast; look again in a moment.
            window.setTimeout(() => { completing.current = null; void refresh(); }, 2000);
        });
    }, [active, left, apply, celebrate, refresh]);

    useEffect(() => {
        if (!active || left === null) return;

        document.title = `${formatClock(left)} ${kindLabels[active.kind]}${active.status === 'paused' ? ' (paused)' : ''}`;

        return () => { document.title = baseTitle.current; };
    }, [active, left]);

    const run = useCallback(async (action: () => Promise<TimerState>) => {
        setBusy(true);
        setError('');

        try {
            apply(await action());
        } catch (problem) {
            setError(errorText(problem));
        } finally {
            setBusy(false);
        }
    }, [apply]);

    const value = useMemo<Pomodoro>(() => ({
        state,
        secondsLeft: left,
        busy,
        error,
        finished,
        start: async (kind, taskId = null) => {
            // Asking for permission needs a click, and starting a timer is one.
            if (typeof Notification !== 'undefined' && Notification.permission === 'default') void Notification.requestPermission();

            setFinished(null);
            await run(() => startTimer(kind, taskId));
        },
        pause: () => run(() => controlTimer(active!.id, 'pause')),
        resume: () => run(() => controlTimer(active!.id, 'resume')),
        stop: () => { setFinished(null); return run(() => controlTimer(active!.id, 'abandon')); },
        dismissFinished: () => setFinished(null),
        updateSettings: async (settings) => {
            setBusy(true);
            setError('');

            try {
                apply(await saveSettingsRequest(settings));

                return true;
            } catch (problem) {
                setError(errorText(problem));

                return false;
            } finally {
                setBusy(false);
            }
        },
    }), [state, left, busy, error, finished, active, run, apply]);

    return <PomodoroContext.Provider value={value}>{children}</PomodoroContext.Provider>;
}
