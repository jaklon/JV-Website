import { createRequire } from 'node:module';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
const require = createRequire(import.meta.url);
const createCore = require('@ffmpeg/core');
const core = await createCore({ wasmBinary: await readFile(require.resolve('@ffmpeg/core/wasm')), print:()=>{}, printErr:()=>{} });
const destination = resolve('.test-data/fixtures');
await mkdir(destination,{recursive:true});
const commands = [
  ['silent.mp4',['-f','lavfi','-i','color=c=0x965c50:s=160x90:r=24:d=2','-an','-c:v','libx264','-preset','ultrafast','-pix_fmt','yuv420p','silent.mp4']],
  ['with-audio.mp4',['-f','lavfi','-i','color=c=0xa5b49a:s=160x90:r=24:d=2','-f','lavfi','-i','sine=frequency=440:sample_rate=48000:duration=2','-c:v','libx264','-preset','ultrafast','-pix_fmt','yuv420p','-c:a','aac','-shortest','with-audio.mp4']],
  ['music.mp3',['-f','lavfi','-i','sine=frequency=220:sample_rate=48000:duration=2','-c:a','libmp3lame','music.mp3']],
  ['photo.png',['-f','lavfi','-i','color=c=0xd8b49a:s=320x240','-frames:v','1','photo.png']],
];
for(const [name,args] of commands){ const status=core.exec(...args); if(status!==0)throw new Error(`Fixture failed: ${name}`); await writeFile(resolve(destination,name),core.FS.readFile(name));core.reset(); }
console.log('Generated four disposable media fixtures in .test-data/fixtures.');
