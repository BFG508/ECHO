import { ApiError, createEcho, revealEcho } from '../api/client.js';
import { decryptEcho, deriveAccessToken, encryptEcho, formatShareFragment, maxPlaintextBytes, parseShareFragment, plaintextByteLength, } from '../crypto/echoCrypto.js';
import { clear, copyText, element, setBusy } from './dom.js';
import { renderQr } from './qr.js';
import { startCountdown } from './time.js';
const EXPIRATIONS = new Map([
    [300, '5 minutes'],
    [3600, '1 hour'],
    [86400, '1 day'],
    [604800, '7 days'],
]);
function statusBox(kind, message) {
    const box = element('div', `status status--${kind}`, message);
    box.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    return box;
}
function readableError(error) {
    if (error instanceof ApiError) {
        if (error.status === 404)
            return 'This echo does not exist, has expired, or has already been opened.';
        if (error.status === 429)
            return 'Too many requests. Please try again shortly.';
        return error.message;
    }
    if (error instanceof Error)
        return error.message;
    return 'Something went wrong.';
}
function buildShareUrl(secrets) {
    const url = new URL(window.location.href);
    url.search = '';
    url.hash = formatShareFragment(secrets).slice(1);
    return url.toString();
}
function focusView(root) {
    window.requestAnimationFrame(() => root.focus({ preventScroll: true }));
}
function securityBadges(burn, expiresAt) {
    const list = element('div', 'badges');
    const encrypted = element('span', 'badge', '🔐 256-bit local secret');
    const burnBadge = element('span', 'badge', burn ? '🔥 One-time reveal' : '♻️ Reusable until expiry');
    const expiry = element('span', 'badge badge--timer');
    expiry.append(document.createTextNode('⏱ '));
    const countdown = element('span', '', '');
    expiry.append(countdown);
    startCountdown(countdown, expiresAt);
    list.append(encrypted, burnBadge, expiry);
    return list;
}
export function renderCreate(root) {
    clear(root);
    const shell = element('section', 'card');
    const eyebrow = element('p', 'eyebrow', 'Private by design');
    const title = element('h1', 'title', 'Send an echo');
    const intro = element('p', 'lead', 'Your message is encrypted in this browser. The server stores only ciphertext and never receives the decryption key.');
    const form = element('form', 'form');
    const label = element('label', 'label', 'Message');
    label.htmlFor = 'message';
    const textarea = element('textarea', 'textarea');
    textarea.id = 'message';
    textarea.name = 'message';
    textarea.rows = 9;
    textarea.placeholder = 'Write something that should stay private…';
    textarea.required = true;
    textarea.autocomplete = 'off';
    textarea.spellcheck = false;
    const meter = element('div', 'meter');
    const meterText = element('span', 'meter__text', `0 / ${maxPlaintextBytes().toLocaleString()} bytes`);
    meter.append(meterText);
    const options = element('div', 'options');
    const expiryGroup = element('div', 'field');
    const expiryLabel = element('label', 'label', 'Expires after');
    expiryLabel.htmlFor = 'expiry';
    const select = element('select', 'select');
    select.id = 'expiry';
    select.name = 'expiry';
    for (const [seconds, text] of EXPIRATIONS) {
        const option = document.createElement('option');
        option.value = String(seconds);
        option.textContent = text;
        if (seconds === 86400)
            option.selected = true;
        select.append(option);
    }
    expiryGroup.append(expiryLabel, select);
    const burnLabel = element('label', 'check');
    const burn = document.createElement('input');
    burn.type = 'checkbox';
    burn.checked = true;
    burn.name = 'burn';
    const burnCopy = element('span', 'check__copy');
    burnCopy.append(element('strong', '', 'Burn after first reveal'), element('small', '', 'Consume the encrypted server copy when it is successfully retrieved once.'));
    burnLabel.append(burn, burnCopy);
    options.append(expiryGroup, burnLabel);
    const submit = element('button', 'button button--primary', 'Create encrypted link');
    submit.type = 'submit';
    const status = element('div', 'status-slot');
    status.setAttribute('aria-live', 'polite');
    form.append(label, textarea, meter, options, submit, status);
    shell.append(eyebrow, title, intro, form);
    root.append(shell, securityNotes());
    focusView(root);
    const updateMeter = () => {
        const bytes = plaintextByteLength(textarea.value);
        meterText.textContent = `${bytes.toLocaleString()} / ${maxPlaintextBytes().toLocaleString()} bytes`;
        meter.classList.toggle('meter--over', bytes > maxPlaintextBytes());
    };
    textarea.addEventListener('input', updateMeter);
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        void (async () => {
            status.replaceChildren();
            const message = textarea.value;
            if (message.trim().length === 0) {
                status.append(statusBox('error', 'Write a message first.'));
                textarea.focus();
                return;
            }
            if (plaintextByteLength(JSON.stringify({ v: 1, message })) > maxPlaintextBytes()) {
                status.append(statusBox('error', 'The message is too large after UTF-8 encoding.'));
                return;
            }
            setBusy(submit, true, 'Encrypting…');
            try {
                const encrypted = await encryptEcho(message);
                const created = await createEcho({
                    id: encrypted.id,
                    ciphertext: encrypted.ciphertext,
                    iv: encrypted.iv,
                    accessTokenHash: encrypted.accessTokenHash,
                    burnAfterReading: burn.checked,
                    expiresInSeconds: Number(select.value),
                });
                textarea.value = '';
                updateMeter();
                renderCreated(root, {
                    id: encrypted.id,
                    key: encrypted.key,
                }, burn.checked, created.expiresAt);
            }
            catch (error) {
                status.append(statusBox('error', readableError(error)));
                setBusy(submit, false, '');
            }
        })();
    });
}
function renderCreated(root, secrets, burn, expiresAt) {
    clear(root);
    const shell = element('section', 'card');
    shell.append(element('p', 'eyebrow', 'Encrypted locally'), element('h1', 'title', 'Your echo is ready'), element('p', 'lead', burn
        ? 'This link can retrieve the encrypted message once. Send it through a channel you trust.'
        : 'This link can be opened repeatedly until it expires.'), securityBadges(burn, expiresAt));
    const url = buildShareUrl(secrets);
    const share = element('div', 'share');
    const input = document.createElement('input');
    input.className = 'share__input';
    input.value = url;
    input.readOnly = true;
    input.setAttribute('aria-label', 'Encrypted echo link');
    const copy = element('button', 'button button--primary', 'Copy link');
    copy.type = 'button';
    const feedback = element('div', 'status-slot');
    feedback.setAttribute('aria-live', 'polite');
    copy.addEventListener('click', () => {
        void (async () => {
            try {
                await copyText(url);
                feedback.replaceChildren(statusBox('success', 'Link copied.'));
            }
            catch {
                input.focus();
                input.select();
                feedback.replaceChildren(statusBox('info', 'Select and copy the link manually.'));
            }
        })();
    });
    share.append(input, copy);
    const actions = element('div', 'actions');
    if (typeof navigator.share === 'function') {
        const nativeShare = element('button', 'button button--ghost button--inline', 'Share');
        nativeShare.type = 'button';
        nativeShare.addEventListener('click', () => {
            void navigator.share({ title: 'ECHO', text: 'Encrypted ECHO link', url }).catch(() => undefined);
        });
        actions.append(nativeShare);
    }
    const qrDetails = document.createElement('details');
    qrDetails.className = 'qr-details';
    const qrSummary = document.createElement('summary');
    qrSummary.textContent = 'Show QR code';
    const qrCopy = element('p', 'detail', 'Generated entirely in this browser. The secret is not sent anywhere to create the QR code.');
    const canvas = document.createElement('canvas');
    canvas.className = 'qr';
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', 'QR code containing the complete encrypted ECHO link');
    renderQr(canvas, url);
    qrDetails.append(qrSummary, qrCopy, canvas);
    const warning = statusBox('info', 'The 256-bit master secret lives only after the # in this link. ECHO never uploads it. Anyone with the complete link can reveal the message.');
    const another = element('button', 'button button--ghost', 'Create another echo');
    another.type = 'button';
    another.addEventListener('click', () => {
        history.replaceState(null, '', window.location.pathname + window.location.search);
        renderCreate(root);
    });
    shell.append(share, feedback, actions, qrDetails, warning, another);
    root.append(shell, securityNotes());
    focusView(root);
}
export function renderOpen(root) {
    clear(root);
    const secrets = parseShareFragment(window.location.hash);
    const shell = element('section', 'card');
    if (secrets === null) {
        shell.append(element('p', 'eyebrow', 'Invalid link'), element('h1', 'title', 'This echo link is malformed'), element('p', 'lead', 'Check that you copied the entire link, including everything after the # symbol.'));
        const home = element('button', 'button button--primary', 'Create a new echo');
        home.type = 'button';
        home.addEventListener('click', () => {
            history.replaceState(null, '', window.location.pathname + window.location.search);
            renderCreate(root);
        });
        shell.append(home);
        root.append(shell);
        focusView(root);
        return;
    }
    shell.append(element('p', 'eyebrow', 'Encrypted echo'), element('h1', 'title', 'A private message is waiting'), element('p', 'lead', 'Nothing is fetched until you choose to reveal it. This avoids consuming one-time messages through ordinary link previews.'));
    const reveal = element('button', 'button button--primary button--wide', 'Reveal echo');
    reveal.type = 'button';
    const status = element('div', 'status-slot');
    status.setAttribute('aria-live', 'polite');
    shell.append(reveal, status);
    root.append(shell, securityNotes());
    focusView(root);
    reveal.addEventListener('click', () => {
        void (async () => {
            status.replaceChildren();
            setBusy(reveal, true, 'Revealing…');
            try {
                const accessToken = await deriveAccessToken(secrets.id, secrets.key);
                const payload = await revealEcho(secrets.id, accessToken);
                const message = await decryptEcho(secrets.id, secrets.key, payload.iv, payload.ciphertext);
                history.replaceState(null, '', window.location.pathname + window.location.search);
                renderMessage(root, message, payload.burnAfterReading, payload.expiresAt);
            }
            catch (error) {
                status.append(statusBox('error', readableError(error)));
                setBusy(reveal, false, '');
            }
        })();
    });
}
function renderMessage(root, message, burned, expiresAt) {
    clear(root);
    const shell = element('section', 'card');
    shell.append(element('p', 'eyebrow', burned ? 'Retrieved and burned' : 'Decrypted locally'), element('h1', 'title', 'The echo says'));
    const messageBox = element('pre', 'message');
    messageBox.textContent = message;
    const buttons = element('div', 'actions');
    const copy = element('button', 'button button--primary', 'Copy message');
    copy.type = 'button';
    const copyStatus = element('div', 'status-slot');
    copyStatus.setAttribute('aria-live', 'polite');
    copy.addEventListener('click', () => {
        void (async () => {
            try {
                await copyText(message);
                copyStatus.replaceChildren(statusBox('success', 'Message copied. Clipboard history is outside ECHO’s security boundary.'));
            }
            catch {
                copyStatus.replaceChildren(statusBox('info', 'Copy the message manually.'));
            }
        })();
    });
    buttons.append(copy);
    const detail = element('p', 'detail', burned
        ? 'The encrypted server copy was consumed as part of this successful retrieval. ECHO cannot prove that a human actually read the plaintext.'
        : 'The encrypted server copy remains available until it expires.');
    if (!burned) {
        const countdown = element('strong', 'countdown');
        startCountdown(countdown, expiresAt);
        detail.append(document.createTextNode(' Remaining: '), countdown, document.createTextNode('.'));
    }
    const home = element('button', 'button button--ghost', 'Create your own echo');
    home.type = 'button';
    home.addEventListener('click', () => renderCreate(root));
    shell.append(messageBox, buttons, copyStatus, detail, home);
    root.append(shell, securityNotes());
    focusView(root);
}
function securityNotes() {
    const notes = element('aside', 'notes');
    notes.append(element('h2', 'notes__title', 'What ECHO can and cannot protect'), element('p', '', 'AES-256-GCM encryption happens in your browser. The server receives the encrypted payload, an opaque identifier, expiry settings, and only a one-way hash of an HKDF-derived access proof.'), element('p', '', 'Anyone with the complete share link can read the message. Protect the link itself, use HTTPS in production, and remember that a compromised browser, server-delivered JavaScript, device, screenshot, or clipboard can expose plaintext.'));
    return notes;
}
