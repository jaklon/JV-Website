import { mkdir, copyFile } from 'node:fs/promises';
import { resolve } from 'node:path';
const target = resolve('public/ffmpeg');
await mkdir(target, { recursive: true });
for (const file of ['ffmpeg-core.js', 'ffmpeg-core.wasm']) {
  await copyFile(resolve('node_modules/@ffmpeg/core/dist/esm', file), resolve(target, file));
}
