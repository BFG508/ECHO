const BASE64URL_RE = /^[A-Za-z0-9_-]+$/;
export function bytesToBase64Url(bytes) {
    let binary = '';
    const chunkSize = 0x8000;
    for (let offset = 0; offset < bytes.length; offset += chunkSize) {
        const chunk = bytes.subarray(offset, Math.min(offset + chunkSize, bytes.length));
        binary += String.fromCharCode(...chunk);
    }
    return btoa(binary).replaceAll('+', '-').replaceAll('/', '_').replace(/=+$/u, '');
}
export function base64UrlToBytes(value) {
    if (value.length === 0 || !BASE64URL_RE.test(value)) {
        throw new Error('Invalid base64url value.');
    }
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const base64 = value.replaceAll('-', '+').replaceAll('_', '/') + padding;
    let binary;
    try {
        binary = atob(base64);
    }
    catch {
        throw new Error('Invalid base64url value.');
    }
    const output = new Uint8Array(binary.length);
    for (let index = 0; index < binary.length; index += 1) {
        output[index] = binary.charCodeAt(index);
    }
    return output;
}
