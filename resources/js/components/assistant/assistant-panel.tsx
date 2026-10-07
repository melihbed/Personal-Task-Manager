import { router } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent } from 'react';
import { AssistantError, boldPieces, clearChat, decide, describeChange, loadActions, loadMessages, sendMessage, starterQuestions, type Action, type AssistantMessage } from '../../lib/assistant';
import { useCompanion } from '../companion/companion-context';
import Fox from '../companion/fox';
import { useFoxMood } from '../companion/use-fox-mood';
import Button from '../ui/button';
import ConfirmDialog from '../ui/confirm-dialog';

const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

function Text({ content }: { content: string }) {
    return <>{boldPieces(content).map((piece, index) => piece.bold ? <strong key={index}>{piece.text}</strong> : <span key={index}>{piece.text}</span>)}</>;
}

function Thinking({ status }: { status: string }) {
    const [slow, setSlow] = useState(false);

    // A model that has gone to sleep takes a while to wake, so say so instead of leaving the dots spinning.
    useEffect(() => {
        const timer = window.setTimeout(() => setSlow(true), 10_000);

        return () => window.clearTimeout(timer);
    }, []);

    return (
        <div role="status" aria-label="The assistant is thinking">
            <div className="flex w-fit items-center gap-1.5 rounded-2xl bg-[var(--pm-background)] px-4 py-3">
                {[0, 150, 300].map(delay => <span key={delay} className="size-1.5 animate-pulse rounded-full bg-[var(--pm-muted)]" style={{ animationDelay: `${delay}ms` }} />)}
            </div>
            {status !== '' && <p className="mt-2 text-xs text-[var(--pm-muted)]">{status}</p>}
            {slow && <p className="mt-1 text-xs text-[var(--pm-muted)]">Still working. The first answer after a break can take up to a minute while the model wakes up.</p>}
        </div>
    );
}

const statusStyle = { applied: 'pm-badge pm-badge--ok', failed: 'pm-badge pm-badge--warn', dismissed: 'pm-badge' } as const;
const statusLabel = { applied: 'Applied', failed: 'Could not apply', dismissed: 'Turned down' } as const;

/** What the assistant has done, newest first, with what changed from and to. */
function ActivityList({ actions, failed }: { actions: Action[] | null; failed: boolean }) {
    const [openId, setOpenId] = useState<number | null>(null);

    if (failed) return <p role="alert" className="text-sm text-red-700">Could not load the activity.</p>;
    if (actions === null) return <div role="status" aria-label="Loading the activity" className="h-16 animate-pulse rounded-2xl bg-[var(--pm-background)]" />;

    if (actions.length === 0) {
        return (
            <div>
                <p className="text-sm font-medium">Nothing yet</p>
                <p className="mt-1 text-xs text-[var(--pm-muted)]">Every change you approve, and every suggestion you turn down, is recorded here with what it changed.</p>
            </div>
        );
    }

    return (
        <ul className="divide-y divide-[var(--pm-border)]">
            {actions.map(action => {
                const open = openId === action.id;
                const detail = action.status === 'failed' ? [action.result ?? 'It could not be applied.'] : action.changes.map(describeChange);

                return (
                    <li key={action.id} className="py-3 first:pt-0 last:pb-0">
                        <div className="flex items-start justify-between gap-3">
                            <p className="min-w-0 text-sm leading-5 break-words">{action.summary}</p>
                            <span className={`${statusStyle[action.status]} shrink-0`}>{statusLabel[action.status]}</span>
                        </div>
                        <div className="mt-1.5 flex items-center justify-between gap-3 text-[11px] text-[var(--pm-muted)]">
                            <time dateTime={action.at}>{new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(action.at))}</time>
                            {detail.length > 0 && (
                                <button type="button" aria-expanded={open} onClick={() => setOpenId(open ? null : action.id)} className="cursor-pointer rounded-md px-1.5 py-0.5 hover:bg-[var(--pm-background)]">{open ? 'Hide details' : 'Details'}</button>
                            )}
                        </div>
                        {open && <ul className="mt-2 space-y-1 rounded-xl bg-[var(--pm-background)] px-3 py-2.5 text-xs">{detail.map((line, index) => <li key={index} className="break-words">{line}</li>)}</ul>}
                    </li>
                );
            })}
        </ul>
    );
}

type Props = { open: boolean; onClose: () => void };

/** A chat that slides in from the right on every page. It can look things up; changes only happen when you approve them. */
export default function AssistantPanel({ open, onClose }: Props) {
    const { celebrate, setThinking } = useCompanion();
    const mood = useFoxMood();
    const [messages, setMessages] = useState<AssistantMessage[] | null>(null);
    const [draft, setDraft] = useState('');
    const [sending, setSending] = useState(false);
    const [streamText, setStreamText] = useState('');
    const [status, setStatus] = useState('');
    const [view, setView] = useState<'chat' | 'activity'>('chat');
    const [actions, setActions] = useState<Action[] | null>(null);
    const [actionsFailed, setActionsFailed] = useState(false);
    const [error, setError] = useState('');
    const [deciding, setDeciding] = useState<string | null>(null);
    const [confirmNew, setConfirmNew] = useState(false);
    const [clearing, setClearing] = useState(false);
    const input = useRef<HTMLTextAreaElement>(null);
    const end = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;

        input.current?.focus();

        function closeOnEscape(event: globalThis.KeyboardEvent) {
            if (event.key === 'Escape') onClose();
        }

        document.addEventListener('keydown', closeOnEscape);

        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [open, onClose]);

    useEffect(() => {
        if (!open || messages !== null) return;

        loadMessages().then(setMessages).catch((problem: Error) => { setMessages([]); setError(problem.message); });
    }, [open, messages]);

    useEffect(() => { end.current?.scrollIntoView({ block: 'end' }); }, [messages, sending, streamText, status]);

    // The record is read fresh each time it is opened, so it is never out of date.
    useEffect(() => {
        if (!open || view !== 'activity') return;

        setActionsFailed(false);
        loadActions().then(setActions).catch(() => setActionsFailed(true));
    }, [open, view]);

    // The fox writes in its notebook while an answer is on the way.
    useEffect(() => { setThinking(sending); return () => setThinking(false); }, [sending, setThinking]);

    async function send(text: string) {
        const content = text.trim();

        if (content === '' || sending) return;

        setSending(true);
        setError('');
        setDraft('');
        setStreamText('');
        setStatus('');

        const pending: AssistantMessage = { id: -Date.now(), role: 'user', content, proposals: [] };

        setMessages(current => [...(current ?? []), pending]);

        try {
            const reply = await sendMessage(content, timezone, (event) => {
                if (event.type === 'delta') setStreamText(current => current + event.text);
                else if (event.type === 'reset') setStreamText('');
                else if (event.type === 'status') setStatus(event.text);
            });

            setMessages(current => [...(current ?? []).filter(message => message.id !== pending.id), ...reply]);
        } catch (problem) {
            setMessages(current => (current ?? []).filter(message => message.id !== pending.id));
            setDraft(content);
            setError(problem instanceof AssistantError ? problem.message : 'Something went wrong. Please try again.');
        } finally {
            setSending(false);
            setStreamText('');
            setStatus('');
            input.current?.focus();
        }
    }

    async function choose(message: AssistantMessage, index: number, decision: 'approve' | 'dismiss') {
        setDeciding(`${message.id}:${index}`);
        setError('');

        try {
            const updated = await decide(message.id, index, decision);

            setMessages(current => (current ?? []).map(item => item.id === updated.id ? updated : item));

            // An approved change alters the planner, so the page behind the panel is refreshed.
            if (decision === 'approve') {
                router.reload();
                celebrate();
            }
        } catch (problem) {
            setError(problem instanceof AssistantError ? problem.message : 'Something went wrong. Please try again.');
        } finally {
            setDeciding(null);
        }
    }

    async function startNewChat() {
        setClearing(true);

        try {
            await clearChat();
            setMessages([]);
            setError('');
        } catch (problem) {
            setError(problem instanceof AssistantError ? problem.message : 'Something went wrong. Please try again.');
        } finally {
            setClearing(false);
            setConfirmNew(false);
        }
    }

    function onKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
            event.preventDefault();
            void send(draft);
        }
    }

    const empty = messages !== null && messages.length === 0;

    return (
        <aside
            id="assistant-panel"
            aria-label="Assistant"
            aria-hidden={!open}
            inert={!open}
            className={`fixed inset-y-0 right-0 z-40 flex w-full max-w-[420px] flex-col border-l border-[var(--pm-border)] bg-white shadow-[-12px_0_40px_#252b3d1a] transition-transform duration-300 ease-out ${open ? 'translate-x-0' : 'translate-x-full'}`}
        >
            <div className="flex items-start justify-between gap-3 border-b border-[var(--pm-border)] px-5 py-4">
                <div className="flex min-w-0 items-center gap-3">
                    <Fox mood={mood} className="size-12 shrink-0" />
                    <div className="min-w-0">
                        <h2 className="font-semibold">Assistant</h2>
                        <p className="mt-0.5 text-xs text-[var(--pm-muted)]">Runs on your computer. Asks before it changes anything.</p>
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    {messages !== null && messages.length > 0 && <Button type="button" variant="secondary" size="small" className="whitespace-nowrap" onClick={() => setConfirmNew(true)}>New chat</Button>}
                    <button type="button" onClick={onClose} aria-label="Close assistant" title="Close" className="pm-button pm-button--secondary pm-button--small pm-button--icon">
                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M6 6l12 12M18 6 6 18" /></svg>
                    </button>
                </div>
            </div>

            <div className="px-4 pt-3">
                <div
                    role="tablist"
                    aria-label="Chat or activity"
                    className="pm-tabs"
                    onKeyDown={(event) => { if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') setView(view === 'chat' ? 'activity' : 'chat'); }}
                >
                    {(['chat', 'activity'] as const).map(name => (
                        <button key={name} type="button" role="tab" id={`assistant-tab-${name}`} aria-selected={view === name} aria-controls={`assistant-view-${name}`} tabIndex={view === name ? 0 : -1} onClick={() => setView(name)} className="pm-tab">
                            {name === 'chat' ? 'Chat' : 'Activity'}
                        </button>
                    ))}
                </div>
            </div>

            {view === 'activity' && (
                <div role="tabpanel" id="assistant-view-activity" aria-labelledby="assistant-tab-activity" className="pm-tab-panel min-h-0 flex-1 overflow-y-auto px-5 py-4">
                    <ActivityList actions={actions} failed={actionsFailed} />
                </div>
            )}

            <div role="tabpanel" id="assistant-view-chat" aria-labelledby="assistant-tab-chat" hidden={view !== 'chat'} className="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4" aria-live="polite">
                {messages === null && <div role="status" aria-label="Loading the conversation" className="h-12 animate-pulse rounded-2xl bg-[var(--pm-background)]" />}

                {empty && !sending && (
                    <div>
                        <p className="text-sm font-medium">How can I help?</p>
                        <p className="mt-1 text-xs text-[var(--pm-muted)]">I can look through your tasks, calendar and coursework, and suggest changes for you to approve.</p>
                        <div className="mt-4 flex flex-col items-start gap-2">
                            {starterQuestions.map(question => <button key={question} type="button" className="pm-chip" onClick={() => void send(question)}>{question}</button>)}
                        </div>
                    </div>
                )}

                {messages?.map(message => (
                    <div key={message.id} className={message.role === 'user' ? 'flex justify-end' : ''}>
                        <div className={message.role === 'user' ? 'max-w-[85%] rounded-2xl bg-[var(--pm-text)] px-4 py-2.5 text-sm text-white' : 'max-w-full'}>
                            <p className="text-sm leading-6 break-words whitespace-pre-wrap"><Text content={message.content} /></p>
                            {message.proposals.map((proposal, index) => (
                                <div key={index} className="mt-3 rounded-2xl border border-[var(--pm-border)] bg-white p-3.5 text-[var(--pm-text)]">
                                    <p className={`text-sm ${proposal.status === 'dismissed' ? 'text-[var(--pm-muted)] line-through' : ''}`}>{proposal.summary}</p>
                                    {proposal.status === 'pending' && (
                                        <div className="pm-button-group mt-3">
                                            <Button type="button" variant={proposal.destructive ? 'danger' : 'primary'} size="small" loading={deciding === `${message.id}:${index}`} loadingLabel="Applying" disabled={deciding !== null} onClick={() => void choose(message, index, 'approve')}>{proposal.destructive ? 'Delete' : 'Approve'}</Button>
                                            <Button type="button" variant="secondary" size="small" disabled={deciding !== null} onClick={() => void choose(message, index, 'dismiss')}>Dismiss</Button>
                                        </div>
                                    )}
                                    {proposal.status === 'approved' && <p className="mt-2 flex items-center gap-1.5 text-xs text-[#15803d]"><span aria-hidden="true">✓</span>{proposal.result ?? 'Done'}</p>}
                                    {proposal.status === 'dismissed' && <p className="mt-2 text-xs text-[var(--pm-muted)]">Dismissed</p>}
                                </div>
                            ))}
                        </div>
                    </div>
                ))}

                {sending && streamText !== '' && (
                    <p className="text-sm leading-6 break-words whitespace-pre-wrap"><Text content={streamText} /><span aria-hidden="true" className="ml-0.5 inline-block h-4 w-0.5 translate-y-0.5 animate-pulse bg-[var(--pm-text)]" /></p>
                )}
                {sending && streamText === '' && <Thinking status={status} />}
                <div ref={end} />
            </div>

            {view === 'chat' && error && <p role="alert" className="mx-5 mb-2 rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-950">{error}</p>}

            <form hidden={view !== 'chat'} onSubmit={(event: FormEvent) => { event.preventDefault(); void send(draft); }} className="border-t border-[var(--pm-border)] p-4">
                <label htmlFor="assistant-input" className="sr-only">Message the assistant</label>
                <div className="flex items-end gap-2">
                    <textarea
                        id="assistant-input"
                        ref={input}
                        rows={1}
                        value={draft}
                        maxLength={2000}
                        placeholder="Ask, or say what to add"
                        onChange={(event) => setDraft(event.target.value)}
                        onKeyDown={onKeyDown}
                        className="pm-input pm-input--flush max-h-32 min-h-11 flex-1 resize-none"
                    />
                    <Button type="submit" className="shrink-0" loading={sending} loadingLabel="Sending" disabled={draft.trim() === ''}>Send</Button>
                </div>
                <p className="mt-2 text-[11px] text-[var(--pm-muted)]">Enter to send, Shift+Enter for a new line. Small models can make mistakes, so check suggestions before approving.</p>
            </form>

            {confirmNew && (
                <ConfirmDialog
                    title="Start a new chat?"
                    description="This clears the current conversation. Changes you already approved stay."
                    confirmLabel="New chat"
                    busy={clearing}
                    onConfirm={() => void startNewChat()}
                    onCancel={() => setConfirmNew(false)}
                />
            )}
        </aside>
    );
}
