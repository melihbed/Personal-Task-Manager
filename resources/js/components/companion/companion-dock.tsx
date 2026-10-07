import { Link } from '@inertiajs/react';
import { formatClock } from '../../lib/pomodoro';
import { usePomodoro } from '../pomodoro/pomodoro-context';
import Fox, { type FoxMood } from './fox';
import { useFoxMood } from './use-fox-mood';

const captions: Record<FoxMood, string> = {
    idle: 'Ask me anything',
    work: 'On it…',
    focus: 'Focusing with you',
    break: 'Enjoy your break',
    celebrate: 'Nice work!',
    sleep: 'Resting. Zzz',
};

/**
 * The fox in the top-right corner. It is the way into the assistant: it reacts to what the app is doing (writing while an
 * answer is on the way, wearing headphones while you focus, jumping for joy at good news, asleep at night), and clicking it
 * opens the chat. While a Pomodoro runs, its clock sits beside it.
 */
export default function CompanionDock({ onOpenAssistant, open }: { onOpenAssistant: () => void; open: boolean }) {
    const mood = useFoxMood();
    const timer = usePomodoro();
    const active = timer.state?.active ?? null;

    return (
        <div className="ml-auto flex items-center gap-3">
            {active && timer.secondsLeft !== null && (
                <Link href="/focus" aria-label="Open the Focus timer" className="pm-chip tabular-nums">
                    {active.status === 'paused' ? 'Paused ' : ''}{formatClock(timer.secondsLeft)}
                </Link>
            )}
            <div className="group relative">
                <button
                    type="button"
                    onClick={onOpenAssistant}
                    aria-label="Open the assistant"
                    aria-expanded={open}
                    aria-controls="assistant-panel"
                    className="relative flex size-14 cursor-pointer items-end justify-center rounded-full border border-[var(--pm-border)] bg-white shadow-[0_2px_10px_#252b3d14] transition duration-200 hover:-translate-y-0.5 hover:shadow-[0_6px_18px_#252b3d26]"
                >
                    <Fox mood={mood} className="size-[3.35rem] translate-y-0.5" />
                </button>
                <p
                    role="status"
                    className={`pointer-events-none absolute top-full right-0 z-30 mt-2 rounded-xl bg-[var(--pm-text)] px-3 py-1.5 text-xs whitespace-nowrap text-white shadow-lg transition duration-200 ${mood === 'idle' || mood === 'sleep' ? 'translate-y-1 opacity-0 group-focus-within:translate-y-0 group-focus-within:opacity-100 group-hover:translate-y-0 group-hover:opacity-100' : 'opacity-100'}`}
                >
                    <span aria-hidden="true" className="absolute -top-1 right-5 size-2 rotate-45 bg-[var(--pm-text)]" />
                    {captions[mood]}
                </p>
            </div>
        </div>
    );
}
