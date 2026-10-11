/** Jeton CSRF posé par Laravel dans le cookie XSRF-TOKEN. */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** Requête JSON authentifiée par la session (POST par défaut). */
export async function postJson<T>(
    url: string,
    body: Record<string, unknown>,
): Promise<{ ok: boolean; status: number; data: T }> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });

    return {
        ok: response.ok,
        status: response.status,
        data: (await response.json().catch(() => ({}))) as T,
    };
}
