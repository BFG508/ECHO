import { cp, mkdir, rm } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const frontend = resolve(here, '..');
const backendPublic = resolve(frontend, '..', 'backend', 'public');

await mkdir(backendPublic, { recursive: true });
await rm(resolve(backendPublic, 'assets'), { recursive: true, force: true });
await cp(resolve(frontend, 'public', 'assets'), resolve(backendPublic, 'assets'), { recursive: true });
await cp(resolve(frontend, 'public', 'index.html'), resolve(backendPublic, 'index.html'));
await cp(resolve(frontend, 'public', 'styles.css'), resolve(backendPublic, 'styles.css'));
