import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const frontend = resolve(here, '..');
const vendor = resolve(frontend, 'vendor', 'QRCode');
const output = resolve(frontend, 'public', 'assets', 'vendor', 'qrcode.js');
const moduleNames = [
  'QR8bitByte',
  'QRBitBuffer',
  'QRErrorCorrectLevel',
  'QRMaskPattern',
  'QRMath',
  'QRMode',
  'QRPolynomial',
  'QRRSBlock',
  'QRUtil',
  'index',
];

const entries = [];
for (const name of moduleNames) {
  const source = await readFile(resolve(vendor, `${name}.js`), 'utf8');
  entries.push(`"./${name}": function(module, exports, require) {\n${source}\n}`);
}

const bundle = `/*
 * ECHO vendored QR encoder bundle.
 * QRCode for JavaScript, Copyright (c) 2009 Kazuhiko Arase, MIT License.
 * See THIRD_PARTY_NOTICES.md and frontend/vendor/QRCode source headers.
 */
(function (global) {
  "use strict";
  const modules = {${entries.join(',\n')}};
  const cache = Object.create(null);
  function requireModule(id) {
    const normalized = id.endsWith('.js') ? id.slice(0, -3) : id;
    if (cache[normalized]) return cache[normalized].exports;
    const factory = modules[normalized];
    if (!factory) throw new Error('Unknown QR module: ' + normalized);
    const module = { exports: {} };
    cache[normalized] = module;
    factory(module, module.exports, requireModule);
    return module.exports;
  }
  global.EchoQRCode = requireModule('./index');
})(globalThis);
`;

await mkdir(dirname(output), { recursive: true });
await writeFile(output, bundle, 'utf8');
