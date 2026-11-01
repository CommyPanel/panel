/** Laravel's XSRF cookie, to send back as the X-XSRF-TOKEN header. */
export function xsrfToken(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length) ?? '',
    );
}

/** JSON request headers Laravel accepts from this page. */
export function jsonHeaders(): Record<string, string> {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': xsrfToken(),
        'X-Requested-With': 'XMLHttpRequest',
    };
}

/** POST without a page visit and read the JSON answer (Laravel's XSRF cookie is sent as a header). */
export async function postJson<T>(
    url: string,
    body: Record<string, unknown> = {},
): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: jsonHeaders(),
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(`Request failed (${response.status})`);
    }

    return (await response.json()) as T;
}
