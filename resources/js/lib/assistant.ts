import { ApiError, csrfToken, request } from './http';

export type Proposal = { summary: string; destructive: boolean; status: 'pending' | 'approved' | 'dismissed'; result: string | null };
export type AssistantMessage = { id: number; role: 'user' | 'assistant'; content: string; proposals: Proposal[] };

export const starterQuestions = [
    'What needs my attention right now?',
    'What does my day look like tomorrow?',
    'What coursework is still left?',
];

/** An error from the assistant endpoints, with a message that is safe to show. */
export { ApiError as AssistantError };

export const loadMessages = () => request<{ messages: AssistantMessage[] }>('GET', '/assistant').then(data => data.messages);


export const decide = (messageId: number, index: number, decision: 'approve' | 'dismiss') =>
    request<{ message: AssistantMessage }>('POST', `/assistant/messages/${messageId}/proposals/${index}/${decision}`).then(data => data.message);

export const clearChat = () => request<{ messages: AssistantMessage[] }>('DELETE', '/assistant');

/** Splits text into plain and **bold** pieces, so a reply's emphasis shows without rendering any HTML. */
export function boldPieces(text: string): { text: string; bold: boolean }[] {
    return text.split(/\*\*(.+?)\*\*/g).map((piece, index) => ({ text: piece, bold: index % 2 === 1 })).filter(piece => piece.text !== '');
}


export type ChangeLine = { label: string; from: string | null; to: string | null };
export type Action = { id: number; type: string; status: 'applied' | 'failed' | 'dismissed'; summary: string; result: string | null; subject_title: string | null; changes: ChangeLine[]; at: string };

export const loadActions = () => request<{ actions: Action[] }>('GET', '/assistant/actions').then(data => data.actions);

/** One field of a change, in words: "Priority: normal → high", "Title: Buy a charger" (new), "Notes: was Chapter 3" (removed). */
export function describeChange(change: ChangeLine): string {
    if (change.from === null) return `${change.label}: ${change.to ?? 'none'}`;
    if (change.to === null) return `${change.label}: was ${change.from}`;

    return `${change.label}: ${change.from} → ${change.to}`;
}

/** What the server sends while a reply is being written, one JSON object per line. */
export type StreamEvent =
    | { type: 'status'; text: string }
    | { type: 'delta'; text: string }
    | { type: 'reset' }
    | { type: 'done'; messages: AssistantMessage[] }
    | { type: 'error'; message: string };

/** Pulls the complete lines out of what has arrived so far; whatever is left is the start of the next line. */
export function parseStreamLines(buffer: string): { events: StreamEvent[]; rest: string } {
    const lines = buffer.split('\n');
    const rest = lines.pop() ?? '';
    const events: StreamEvent[] = [];

    for (const line of lines) {
        if (line.trim() === '') continue;

        try {
            events.push(JSON.parse(line) as StreamEvent);
        } catch {
            // A broken line is skipped rather than ending the reply.
        }
    }

    return { events, rest };
}

/**
 * Sends a message and reports the reply as it is written. Resolves with the saved user and assistant messages.
 * Rejects with an AssistantError if the assistant cannot answer.
 */
export async function sendMessage(content: string, timezone: string, onEvent: (event: StreamEvent) => void): Promise<AssistantMessage[]> {
    let response: Response;

    try {
        response = await fetch('/assistant', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ content, timezone, stream: true }),
        });
    } catch {
        throw new ApiError('Could not reach the app. Check your connection and try again.');
    }

    if (!response.ok || response.body === null) {
        const data = await response.json().catch(() => ({}));

        throw new ApiError(typeof data.message === 'string' && data.message !== '' ? data.message : 'Something went wrong. Please try again.');
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let finished: AssistantMessage[] | null = null;

    const handle = (events: StreamEvent[]) => {
        for (const event of events) {
            if (event.type === 'error') throw new ApiError(event.message);
            if (event.type === 'done') finished = event.messages;

            onEvent(event);
        }
    };

    for (;;) {
        const { done, value } = await reader.read().catch(() => ({ done: true, value: undefined }));

        if (value) {
            const parsed = parseStreamLines(buffer + decoder.decode(value, { stream: true }));

            buffer = parsed.rest;
            handle(parsed.events);
        }

        if (done) break;
    }

    handle(parseStreamLines(`${buffer}\n`).events);

    if (finished === null) throw new ApiError('The reply was cut off. Please try again.');

    return finished;
}
