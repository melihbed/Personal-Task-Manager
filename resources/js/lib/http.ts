/** An error from one of the app's JSON endpoints, with a message that is safe to show. */
export class ApiError extends Error {}

export function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** A JSON request to the app, with the CSRF token Laravel expects. Failures throw an ApiError. */
export async function request<T>(method: string, url: string, body?: unknown): Promise<T> {
    let response: Response;

    try {
        response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
    } catch {
        throw new ApiError('Could not reach the app. Check your connection and try again.');
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) throw new ApiError(typeof data.message === 'string' && data.message !== '' ? data.message : 'Something went wrong. Please try again.');

    return data as T;
}
