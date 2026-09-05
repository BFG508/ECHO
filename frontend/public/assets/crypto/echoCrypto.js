import { base64UrlToBytes, bytesToBase64Url } from './base64.js';
const textEncoder = new TextEncoder();
const textDecoder = new TextDecoder('utf-8', { fatal: true });
const MASTER_SECRET_BYTES = 32;
const ID_BYTES = 16;
const IV_BYTES = 12;
const FORMAT_VERSION = 1;
const MAX_PLAINTEXT_BYTES = 65_536;
const ENCRYPTION_INFO = textEncoder.encode('ECHO:encryption:v1');
const ACCESS_INFO = textEncoder.encode('ECHO:access:v1');
function randomBytes(length) {
    const bytes = new Uint8Array(length);
    crypto.getRandomValues(bytes);
    return bytes;
}
function aadFor(id) {
    return textEncoder.encode(`ECHO:v${FORMAT_VERSION}:${id}`);
}
function idBytesFor(id) {
    const idBytes = base64UrlToBytes(id);
    if (idBytes.byteLength !== ID_BYTES) {
        throw new Error('Invalid echo identifier.');
    }
    return idBytes;
}
async function importMasterSecret(secretBytes) {
    if (secretBytes.byteLength !== MASTER_SECRET_BYTES) {
        throw new Error('Invalid encryption secret.');
    }
    return crypto.subtle.importKey('raw', secretBytes, 'HKDF', false, ['deriveKey', 'deriveBits']);
}
async function deriveAesKey(masterSecret, idBytes) {
    return crypto.subtle.deriveKey({
        name: 'HKDF',
        hash: 'SHA-256',
        salt: idBytes,
        info: ENCRYPTION_INFO,
    }, masterSecret, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
}
async function deriveAccessProof(masterSecret, idBytes) {
    const proof = await crypto.subtle.deriveBits({
        name: 'HKDF',
        hash: 'SHA-256',
        salt: idBytes,
        info: ACCESS_INFO,
    }, masterSecret, 256);
    return new Uint8Array(proof);
}
async function sha256(bytes) {
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    return new Uint8Array(digest);
}
export function plaintextByteLength(message) {
    return textEncoder.encode(message).byteLength;
}
export function maxPlaintextBytes() {
    return MAX_PLAINTEXT_BYTES;
}
export async function encryptEcho(message) {
    if (message.trim().length === 0) {
        throw new Error('Message cannot be empty.');
    }
    const plaintext = textEncoder.encode(JSON.stringify({ v: FORMAT_VERSION, message }));
    if (plaintext.byteLength > MAX_PLAINTEXT_BYTES) {
        throw new Error('Message is too large.');
    }
    const idBytes = randomBytes(ID_BYTES);
    const masterSecretBytes = randomBytes(MASTER_SECRET_BYTES);
    const iv = randomBytes(IV_BYTES);
    const id = bytesToBase64Url(idBytes);
    const masterSecret = await importMasterSecret(masterSecretBytes);
    const aesKey = await deriveAesKey(masterSecret, idBytes);
    const accessProof = await deriveAccessProof(masterSecret, idBytes);
    const ciphertext = await crypto.subtle.encrypt({
        name: 'AES-GCM',
        iv,
        additionalData: aadFor(id),
        tagLength: 128,
    }, aesKey, plaintext);
    return {
        id,
        key: bytesToBase64Url(masterSecretBytes),
        accessToken: bytesToBase64Url(accessProof),
        accessTokenHash: bytesToBase64Url(await sha256(accessProof)),
        ciphertext: bytesToBase64Url(new Uint8Array(ciphertext)),
        iv: bytesToBase64Url(iv),
    };
}
export async function decryptEcho(id, encodedSecret, encodedIv, encodedCiphertext) {
    const masterSecretBytes = base64UrlToBytes(encodedSecret);
    const idBytes = idBytesFor(id);
    const iv = base64UrlToBytes(encodedIv);
    const ciphertext = base64UrlToBytes(encodedCiphertext);
    if (iv.byteLength !== IV_BYTES) {
        throw new Error('Invalid encrypted payload.');
    }
    const masterSecret = await importMasterSecret(masterSecretBytes);
    const aesKey = await deriveAesKey(masterSecret, idBytes);
    let plaintext;
    try {
        plaintext = await crypto.subtle.decrypt({
            name: 'AES-GCM',
            iv,
            additionalData: aadFor(id),
            tagLength: 128,
        }, aesKey, ciphertext);
    }
    catch {
        throw new Error('Unable to decrypt this echo. The link may be invalid or altered.');
    }
    try {
        const decoded = JSON.parse(textDecoder.decode(plaintext));
        if (typeof decoded !== 'object'
            || decoded === null
            || !('v' in decoded)
            || !('message' in decoded)
            || decoded.v !== FORMAT_VERSION
            || typeof decoded.message !== 'string') {
            throw new Error('Invalid encrypted payload.');
        }
        return decoded.message;
    }
    catch (error) {
        if (error instanceof Error && error.message === 'Invalid encrypted payload.') {
            throw error;
        }
        throw new Error('Invalid encrypted payload.');
    }
}
export async function deriveAccessToken(id, encodedSecret) {
    const masterSecretBytes = base64UrlToBytes(encodedSecret);
    const masterSecret = await importMasterSecret(masterSecretBytes);
    const proof = await deriveAccessProof(masterSecret, idBytesFor(id));
    return bytesToBase64Url(proof);
}
export function formatShareFragment(secrets) {
    return `#/open/${secrets.id}.${secrets.key}`;
}
export function parseShareFragment(hash) {
    const match = /^#\/open\/([A-Za-z0-9_-]{22})\.([A-Za-z0-9_-]{43})$/u.exec(hash);
    if (match === null) {
        return null;
    }
    const [, id, key] = match;
    if (id === undefined || key === undefined) {
        return null;
    }
    try {
        if (base64UrlToBytes(id).byteLength !== ID_BYTES)
            return null;
        if (base64UrlToBytes(key).byteLength !== MASTER_SECRET_BYTES)
            return null;
    }
    catch {
        return null;
    }
    return { id, key };
}
