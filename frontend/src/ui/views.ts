import { ApiError, createEcho, revealEcho } from '../api/client.js';
import {
  decryptEcho,
  deriveAccessToken,
  encryptEcho,
  formatShareFragment,
  maxPlaintextBytes,
  parseShareFragment,
  plaintextByteLength,
} from '../crypto/echoCrypto.js';
import type { ShareSecrets } from '../types/echo.js';
import { clear, copyText, element, setBusy } from './dom.js';

const EXPIRATIONS = new Map<number, string>([
  [300, '5 minutes'],
  [3600, '1 hour'],
  [86400, '1 day'],
  [604800, '7 days'],
]);

function statusBox(kind: 'error' | 'success' | 'info', message: string): HTMLDivElement {
  const box = element('div', `status status--${kind}`, message);
  box.setAttribute('role', kind === 'error' ? 'alert' : 'status');
  return box;
}

function readableError(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 404) return 'This echo does not exist, has expired, or has already been opened.';
    if (error.status === 429) return 'Too many requests. Please try again shortly.';
    return error.message;
  }
  if (error instanceof Error) return error.message;
  return 'Something went wrong.';
}

function buildShareUrl(secrets: ShareSecrets): string {
  const url = new URL(window.location.href);
  url.search = '';
  url.hash = formatShareFragment(secrets).slice(1);
  return url.toString();
}

export function renderCreate(root: HTMLElement): void {
  clear(root);

  const shell = element('section', 'card');
  const eyebrow = element('p', 'eyebrow', 'Private by design');
  const title = element('h1', 'title', 'Send an echo');
  const intro = element(
    'p',
    'lead',
    'Your message is encrypted in this browser. The server stores only ciphertext and never receives the decryption key.',
  );

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
    if (seconds === 86400) option.selected = true;
    select.append(option);
  }
  expiryGroup.append(expiryLabel, select);

  const burnLabel = element('label', 'check');
  const burn = document.createElement('input');
  burn.type = 'checkbox';
  burn.checked = true;
  burn.name = 'burn';
  const burnCopy = element('span', 'check__copy');
  burnCopy.append(
    element('strong', '', 'Burn after reading'),
    element('small', '', 'Delete the encrypted payload after the first authorized reveal.'),
  );
  burnLabel.append(burn, burnCopy);
  options.append(expiryGroup, burnLabel);

  const submit = element('button', 'button button--primary', 'Create encrypted link');
  submit.type = 'submit';
  const status = element('div', 'status-slot');
  status.setAttribute('aria-live', 'polite');

  form.append(label, textarea, meter, options, submit, status);
  shell.append(eyebrow, title, intro, form);
  root.append(shell, securityNotes());

  const updateMeter = (): void => {
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
        await createEcho({
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
        }, burn.checked, Number(select.value));
      } catch (error) {
        status.append(statusBox('error', readableError(error)));
        setBusy(submit, false, '');
      }
    })();
  });
}

function renderCreated(root: HTMLElement, secrets: ShareSecrets, burn: boolean, expirySeconds: number): void {
  clear(root);
  const shell = element('section', 'card');
  shell.append(
    element('p', 'eyebrow', 'Encrypted locally'),
    element('h1', 'title', 'Your echo is ready'),
    element('p', 'lead', burn
      ? 'This link can reveal the message once. Send it through a channel you trust.'
      : 'This link can be opened repeatedly until it expires.'),
  );

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
      } catch {
        input.focus();
        input.select();
        feedback.replaceChildren(statusBox('info', 'Select and copy the link manually.'));
      }
    })();
  });
  share.append(input, copy);

  const warning = statusBox(
    'info',
    `The master secret lives only after the # in this link. ECHO never uploads it. Expires in ${EXPIRATIONS.get(expirySeconds) ?? 'the selected interval'}.`,
  );
  const another = element('button', 'button button--ghost', 'Create another echo');
  another.type = 'button';
  another.addEventListener('click', () => {
    history.replaceState(null, '', window.location.pathname + window.location.search);
    renderCreate(root);
  });

  shell.append(share, feedback, warning, another);
  root.append(shell, securityNotes());
}

export function renderOpen(root: HTMLElement): void {
  clear(root);
  const secrets = parseShareFragment(window.location.hash);
  const shell = element('section', 'card');

  if (secrets === null) {
    shell.append(
      element('p', 'eyebrow', 'Invalid link'),
      element('h1', 'title', 'This echo link is malformed'),
      element('p', 'lead', 'Check that you copied the entire link, including everything after the # symbol.'),
    );
    const home = element('button', 'button button--primary', 'Create a new echo');
    home.type = 'button';
    home.addEventListener('click', () => {
      history.replaceState(null, '', window.location.pathname + window.location.search);
      renderCreate(root);
    });
    shell.append(home);
    root.append(shell);
    return;
  }

  shell.append(
    element('p', 'eyebrow', 'Encrypted echo'),
    element('h1', 'title', 'A private message is waiting'),
    element('p', 'lead', 'Nothing is fetched until you choose to reveal it. This avoids consuming one-time messages through link previews.'),
  );

  const reveal = element('button', 'button button--primary button--wide', 'Reveal echo');
  reveal.type = 'button';
  const status = element('div', 'status-slot');
  status.setAttribute('aria-live', 'polite');
  shell.append(reveal, status);
  root.append(shell, securityNotes());

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
      } catch (error) {
        status.append(statusBox('error', readableError(error)));
        setBusy(reveal, false, '');
      }
    })();
  });
}

function renderMessage(root: HTMLElement, message: string, burned: boolean, expiresAt: number): void {
  clear(root);
  const shell = element('section', 'card');
  shell.append(
    element('p', 'eyebrow', burned ? 'Opened and burned' : 'Decrypted locally'),
    element('h1', 'title', 'The echo says'),
  );
  const messageBox = element('pre', 'message');
  messageBox.textContent = message;
  const detail = element(
    'p',
    'detail',
    burned
      ? 'The encrypted server copy was deleted as part of this reveal.'
      : `The encrypted server copy remains available until ${new Date(expiresAt * 1000).toLocaleString()}.`,
  );
  const home = element('button', 'button button--ghost', 'Create your own echo');
  home.type = 'button';
  home.addEventListener('click', () => renderCreate(root));
  shell.append(messageBox, detail, home);
  root.append(shell, securityNotes());
}

function securityNotes(): HTMLElement {
  const notes = element('aside', 'notes');
  notes.append(
    element('h2', 'notes__title', 'What ECHO can and cannot protect'),
    element('p', '', 'AES-256-GCM encryption happens in your browser. The server receives the encrypted payload, an opaque identifier, expiry settings, and only a one-way hash of an HKDF-derived access proof.'),
    element('p', '', 'Anyone with the complete share link can read the message. Protect the link itself, use HTTPS in production, and remember that a compromised browser or device can expose plaintext.'),
  );
  return notes;
}
