import test from 'node:test';
import assert from 'node:assert/strict';
import {
  decryptEcho,
  deriveAccessToken,
  encryptEcho,
  formatShareFragment,
  parseShareFragment,
} from '../public/assets/crypto/echoCrypto.js';

if (globalThis.btoa === undefined) {
  globalThis.btoa = (value) => Buffer.from(value, 'binary').toString('base64');
}
if (globalThis.atob === undefined) {
  globalThis.atob = (value) => Buffer.from(value, 'base64').toString('binary');
}

function mutateBase64Url(value) {
  const first = value[0];
  const replacement = first === 'A' ? 'B' : 'A';
  return replacement + value.slice(1);
}

function randomMessage(index) {
  const pieces = [
    'ASCII text',
    'áéíóú ñ ç',
    '秘密のメッセージ',
    'مرحبا بالعالم',
    '👋🔐🔥',
    'line one\nline two\nline three',
    '\u0000 is represented safely inside JSON',
  ];
  return `${index}:${pieces[index % pieces.length]}:${'x'.repeat(index % 97)}`;
}

test('encrypt/decrypt round trip preserves Unicode', async () => {
  const encrypted = await encryptEcho('Hola, ECHO 👋 — confidential');
  const decrypted = await decryptEcho(encrypted.id, encrypted.key, encrypted.iv, encrypted.ciphertext);
  assert.equal(decrypted, 'Hola, ECHO 👋 — confidential');
  assert.equal(encrypted.id.length, 22);
  assert.equal(encrypted.key.length, 43);
  assert.equal(encrypted.accessToken.length, 43);
  assert.equal(encrypted.accessTokenHash.length, 43);
  assert.equal(await deriveAccessToken(encrypted.id, encrypted.key), encrypted.accessToken);
});

test('wrong AES key fails authentication', async () => {
  const first = await encryptEcho('secret');
  const second = await encryptEcho('different key');
  assert.notEqual(await deriveAccessToken(first.id, second.key), first.accessToken);
  await assert.rejects(
    decryptEcho(first.id, second.key, first.iv, first.ciphertext),
    /Unable to decrypt/u,
  );
});

test('AAD binds ciphertext to its echo id', async () => {
  const first = await encryptEcho('secret');
  const other = await encryptEcho('other');
  await assert.rejects(
    decryptEcho(other.id, first.key, first.iv, first.ciphertext),
    /Unable to decrypt/u,
  );
});

test('share fragment round trips and malformed fragments are rejected', async () => {
  const encrypted = await encryptEcho('share me');
  const secrets = { id: encrypted.id, key: encrypted.key };
  const fragment = formatShareFragment(secrets);
  assert.deepEqual(parseShareFragment(fragment), secrets);
  assert.equal(parseShareFragment('#/open/not-valid'), null);
  assert.equal(parseShareFragment(fragment + 'x'), null);
});

test('randomized round trips preserve 200 varied messages', async () => {
  for (let index = 0; index < 200; index += 1) {
    const message = randomMessage(index);
    const encrypted = await encryptEcho(message);
    assert.equal(
      await decryptEcho(encrypted.id, encrypted.key, encrypted.iv, encrypted.ciphertext),
      message,
    );
  }
});

test('tampering with ciphertext, IV, id, or key is rejected', async () => {
  const encrypted = await encryptEcho('tamper resistant');
  const other = await encryptEcho('other');

  await assert.rejects(
    decryptEcho(encrypted.id, encrypted.key, encrypted.iv, mutateBase64Url(encrypted.ciphertext)),
    /Unable to decrypt|Invalid encrypted payload/u,
  );
  await assert.rejects(
    decryptEcho(encrypted.id, encrypted.key, mutateBase64Url(encrypted.iv), encrypted.ciphertext),
    /Unable to decrypt|Invalid encrypted payload/u,
  );
  await assert.rejects(
    decryptEcho(other.id, encrypted.key, encrypted.iv, encrypted.ciphertext),
    /Unable to decrypt/u,
  );
  await assert.rejects(
    decryptEcho(encrypted.id, mutateBase64Url(encrypted.key), encrypted.iv, encrypted.ciphertext),
    /Unable to decrypt/u,
  );
});
