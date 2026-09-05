export class ApiError extends Error {
    status;
    code;
    constructor(status, code, message) {
        super(message);
        this.status = status;
        this.code = code;
        this.name = 'ApiError';
    }
}
async function parseJsonResponse(response) {
    let payload;
    try {
        payload = await response.json();
    }
    catch {
        throw new ApiError(response.status, 'invalid_response', 'The server returned an invalid response.');
    }
    if (!response.ok) {
        const error = payload;
        throw new ApiError(response.status, error.error?.code ?? 'request_failed', error.error?.message ?? 'The request could not be completed.');
    }
    return payload;
}
export async function createEcho(payload) {
    const response = await fetch('/api/echoes', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        cache: 'no-store',
        credentials: 'omit',
    });
    return parseJsonResponse(response);
}
export async function revealEcho(id, accessToken) {
    const response = await fetch(`/api/echoes/${encodeURIComponent(id)}/reveal`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ accessToken }),
        cache: 'no-store',
        credentials: 'omit',
    });
    return parseJsonResponse(response);
}
