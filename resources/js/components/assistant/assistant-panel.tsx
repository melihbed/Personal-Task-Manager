import { router } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent } from 'react';
import { AssistantError, boldPieces, clearChat, decide, loadMessages, sendMessage, starterQuestions, type AssistantMessage } from '../../lib/assistant';
import Button from '../ui/button';
import ConfirmDialog from '../ui/confirm-dialog';

const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

function Text({ content }: { content: string }) {
    return <>{boldPieces(content).map((piece, index) => piece.bold ? <strong key={index}>{piece.text}</strong> : <span key={index}>{piece.text}</span>)}</>;
}

function Thinking() {
    return (
        <div role="status" aria-label="The assistant is thinking" className="flex w-fit items-center gap-1.5 rounded-2xl bg-[var(--pm-background)] px-4 py-3">
            {[0, 150, 300].map(delay => <span key={delay} className="size-1.5 animate-pulse rounded-full bg-[var(--pm-muted)]" style={{ animationDelay: `${delay}ms` }} />)}
        </div>
    );
}

type Props = { open: boolean; onClose: () => void };

/** A chat that slides in from the right on every page. It can look things up; changes only happen when you approve them. */
export default function AssistantPanel({ open, onClose }: Props) {
    const [messages, setMessages] = useState<AssistantMessage[] | null>(null);
    const [draft, setDraft] = useState('');
    const [sending, setSending] = useState(false);
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

    useEffect(() => { end.current?.scrollIntoView({ block: 'end' }); }, [messages, sending]);

    async function send(text: string) {
        const content = text.trim();

        if (content === '' || sending) return;

        setSending(true);
        setError('');
        setDraft('');

        const pending: AssistantMessage = { id: -Date.now(), role: 'user', content, proposals: [] };

        setMessages(current => [...(current ?? []), pending]);

        try {
            const reply = await sendMessage(content, timezone);

            setMessages(current => [...(current ?? []).filter(message => message.id !== pending.id), ...reply]);
        } catch (problem) {
            setMessages(current => (current ?? []).filter(message => message.id !== pending.id));
            setDraft(content);
            setError(problem instanceof AssistantError ? problem.message : 'Something went wrong. Please try again.');
        } finally {
            setSending(false);
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
            if (decision === 'approve') router.reload();
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
                <div>
                    <h2 className="font-semibold">Assistant</h2>
                    <p className="mt-0.5 text-xs text-[var(--pm-muted)]">Runs on your computer. Asks before it changes anything.</p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    {messages !== null && messages.length > 0 && <Button type="button" variant="secondary" size="small" className="whitespace-nowrap" onClick={() => setConfirmNew(true)}>New chat</Button>}
                    <button type="button" onClick={onClose} aria-label="Close assistant" title="Close" className="pm-button pm-button--secondary pm-button--small pm-button--icon">
                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M6 6l12 12M18 6 6 18" /></svg>
                    </button>
                </div>
            </div>

            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4" aria-live="polite">
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
                                            <Button type="button" size="small" loading={deciding === `${message.id}:${index}`} loadingLabel="Applying" disabled={deciding !== null} onClick={() => void choose(message, index, 'approve')}>Approve</Button>
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

                {sending && <Thinking />}
                <div ref={end} />
            </div>

            {error && <p role="alert" className="mx-5 mb-2 rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-950">{error}</p>}

            <form onSubmit={(event: FormEvent) => { event.preventDefault(); void send(draft); }} className="border-t border-[var(--pm-border)] p-4">
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
