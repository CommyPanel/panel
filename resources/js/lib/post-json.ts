import { t } from '@/lib/i18n';

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
        throw new Error(
            t('Request failed (:status)', { status: response.status }),
        );
    }

    return (await response.json()) as T;
}

/**
 * POST without a page visit and without reading the answer. The server answers with a
 * redirect back to the page, which is not followed: what is wanted is only that it
 * did the work (a DNS check), after which the page asks again for its own data.
 */
export async function postQuietly(
    url: string,
    body: Record<string, unknown> = {},
): Promise<void> {
    await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: jsonHeaders(),
        body: JSON.stringify(body),
        redirect: 'manual',
    });
}
