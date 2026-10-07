export type Proposal = { summary: string; status: 'pending' | 'approved' | 'dismissed'; result: string | null };
export type AssistantMessage = { id: number; role: 'user' | 'assistant'; content: string; proposals: Proposal[] };

export const starterQuestions = [
    'What needs my attention right now?',
    'What does my day look like tomorrow?',
    'What coursework is still left?',
];

/** An error from the assistant endpoints, with a message that is safe to show. */
export class AssistantError extends Error {}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function request<T>(method: string, url: string, body?: unknown): Promise<T> {
    let response: Response;

    try {
        response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
    } catch {
        throw new AssistantError('Could not reach the app. Check your connection and try again.');
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) throw new AssistantError(typeof data.message === 'string' && data.message !== '' ? data.message : 'Something went wrong. Please try again.');

    return data as T;
}

export const loadMessages = () => request<{ messages: AssistantMessage[] }>('GET', '/assistant').then(data => data.messages);

export const sendMessage = (content: string, timezone: string) => request<{ messages: AssistantMessage[] }>('POST', '/assistant', { content, timezone }).then(data => data.messages);

export const decide = (messageId: number, index: number, decision: 'approve' | 'dismiss') =>
    request<{ message: AssistantMessage }>('POST', `/assistant/messages/${messageId}/proposals/${index}/${decision}`).then(data => data.message);

export const clearChat = () => request<{ messages: AssistantMessage[] }>('DELETE', '/assistant');

/** Splits text into plain and **bold** pieces, so a reply's emphasis shows without rendering any HTML. */
export function boldPieces(text: string): { text: string; bold: boolean }[] {
    return text.split(/\*\*(.+?)\*\*/g).map((piece, index) => ({ text: piece, bold: index % 2 === 1 })).filter(piece => piece.text !== '');
}
