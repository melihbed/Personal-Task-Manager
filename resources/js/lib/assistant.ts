import { ApiError, request } from './http';

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

export const sendMessage = (content: string, timezone: string) => request<{ messages: AssistantMessage[] }>('POST', '/assistant', { content, timezone }).then(data => data.messages);

export const decide = (messageId: number, index: number, decision: 'approve' | 'dismiss') =>
    request<{ message: AssistantMessage }>('POST', `/assistant/messages/${messageId}/proposals/${index}/${decision}`).then(data => data.message);

export const clearChat = () => request<{ messages: AssistantMessage[] }>('DELETE', '/assistant');

/** Splits text into plain and **bold** pieces, so a reply's emphasis shows without rendering any HTML. */
export function boldPieces(text: string): { text: string; bold: boolean }[] {
    return text.split(/\*\*(.+?)\*\*/g).map((piece, index) => ({ text: piece, bold: index % 2 === 1 })).filter(piece => piece.text !== '');
}
