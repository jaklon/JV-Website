import type { FFmpeg } from '@ffmpeg/ffmpeg';
import type { FilmRecipe, Media } from '../types';
import { filmDuration, segmentDuration, validateFilm } from './film';

export type RenderStatus = { percent: number; label: string };
export class FilmRenderer {
  private engine: FFmpeg | null = null;
  private abort = new AbortController();
  cancel() { this.abort.abort(); this.engine?.terminate(); this.engine=null; }
  async render(recipe:FilmRecipe,media:Media[],onStatus:(status:RenderStatus)=>void):Promise<Blob> {
    const errors=validateFilm(recipe,media);if(errors.length)throw new Error(errors[0]);
    if(typeof Worker==='undefined'||typeof WebAssembly==='undefined')throw new Error('Browser ini belum mendukung pembuatan film. Gunakan Chrome atau Edge di laptop.');
    const byId=new Map(media.map(m=>[m.id,m])),signal=this.abort.signal;
    const width=recipe.orientation==='portrait'?720:1280,height=recipe.orientation==='portrait'?1280:720;
    let downloaded=0;
    const check=()=>signal.throwIfAborted();
    const sourceBytes=async(source:Media)=>{
      check();const response=await fetch(source.url,{credentials:'same-origin',signal});
      if(!response.ok)throw new Error('Media tidak bisa dibuka. Pastikan sesi login masih aktif.');
      const bytes=new Uint8Array(await response.arrayBuffer());downloaded+=bytes.byteLength;
      if(downloaded>100*1024*1024)throw new Error('Ukuran file sumber sebenarnya melewati batas 100 MB.');
      return bytes;
    };
    try {
      onStatus({percent:2,label:'Menyiapkan studio video…'});
      const {FFmpeg}=await import('@ffmpeg/ffmpeg');check();
      const engine=new FFmpeg();this.engine=engine;
      const core=new URL(`${import.meta.env.BASE_URL}ffmpeg/`,window.location.origin);
      await engine.load({coreURL:new URL('ffmpeg-core.js',core).href,wasmURL:new URL('ffmpeg-core.wasm',core).href});check();
      const encoding=['-c:v','libx264','-preset','ultrafast','-crf','23','-maxrate','3M','-bufsize','6M','-pix_fmt','yuv420p','-c:a','aac','-b:a','128k','-ar','48000','-ac','2','-threads','1'];
      const execute=async(args:string[])=>{check();const code=await engine.exec(['-hide_banner','-loglevel','error','-filter_threads','1','-filter_complex_threads','1',...args]);check();if(code!==0)throw new Error('Media tidak dapat diproses. Coba MP4 H.264, kurangi adegan, atau gunakan transisi langsung.');};
      const clips:string[]=[];const lengths:number[]=[];
      for(let i=0;i<recipe.items.length;i++){
        const item=recipe.items[i],source=byId.get(item.mediaId)!,duration=segmentDuration(item,source);
        onStatus({percent:5+(i/recipe.items.length)*62,label:`Menyiapkan adegan ${i+1} dari ${recipe.items.length}…`});
        const bytes=await sourceBytes(source),input=`source-${i}.${source.kind==='image'?'jpg':'mp4'}`,output=`clip-${i}.mp4`;
        if(source.kind==='image'){
          const bitmap=await createImageBitmap(new Blob([bytes.buffer as ArrayBuffer]));check();
          const canvas=document.createElement('canvas'),scale=Math.min(width/bitmap.width,height/bitmap.height,1);canvas.width=Math.max(1,Math.round(bitmap.width*scale));canvas.height=Math.max(1,Math.round(bitmap.height*scale));
          canvas.getContext('2d')!.drawImage(bitmap,0,0,canvas.width,canvas.height);bitmap.close();
          const blob=await new Promise<Blob>((resolve,reject)=>canvas.toBlob(value=>value?resolve(value):reject(new Error('Foto tidak dapat dibaca.')),'image/jpeg',0.9));
          await engine.writeFile(input,new Uint8Array(await blob.arrayBuffer()));
        }else await engine.writeFile(input,bytes);
        const vf=`scale=${width}:${height}:force_original_aspect_ratio=decrease,pad=${width}:${height}:(ow-iw)/2:(oh-ih)/2:color=0xf4eee6,setsar=1,fps=24,format=yuv420p`;
        let hasAudio=false;
        if(source.kind==='video'){
          const probeCode=await engine.ffprobe(['-v','error','-show_entries','stream=codec_type:format=duration','-of','json',input,'-o','probe.json']);check();
          if(probeCode!==0)throw new Error('Video tidak dapat dibaca.');
          const probe=JSON.parse(new TextDecoder().decode(await engine.readFile('probe.json') as Uint8Array));
          if(item.end>Number(probe.format?.duration)+0.15)throw new Error('Potongan melebihi durasi video asli. Atur kembali titik akhirnya.');
          hasAudio=!recipe.musicId&&probe.streams.some((stream:{codec_type:string})=>stream.codec_type==='audio');
          await engine.deleteFile('probe.json');
        }
        const args=source.kind==='image'?['-loop','1','-framerate','24','-i',input]:['-ss',String(item.start),'-i',input];
        if(!hasAudio)args.push('-f','lavfi','-i','anullsrc=channel_layout=stereo:sample_rate=48000');
        args.push('-map','0:v:0','-map',hasAudio?'0:a:0':'1:a:0','-vf',vf,'-af',`aresample=48000,apad,atrim=duration=${duration},asetpts=PTS-STARTPTS`,'-t',String(duration),...encoding,output);
        await execute(args);await engine.deleteFile(input);clips.push(output);lengths.push(duration);
      }
      onStatus({percent:70,label:'Merangkai adegan dan transisi…'});
      let joined=clips[0];
      if(clips.length>1){
        joined='joined.mp4';
        if(recipe.transition==='cut'){
          await engine.writeFile('clips.txt',new TextEncoder().encode(clips.map(name=>`file '${name}'`).join('\n')));
          await execute(['-f','concat','-safe','0','-i','clips.txt','-c','copy',joined]);
        }else{
          const inputs=clips.flatMap(name=>['-i',name]);const filters:string[]=[];
          clips.forEach((_,i)=>filters.push(`[${i}:v]settb=AVTB,setpts=PTS-STARTPTS[v${i}];[${i}:a]asetpts=PTS-STARTPTS[a${i}]`));
          let elapsed=lengths[0],v='v0',a='a0';
          for(let i=1;i<clips.length;i++){
            const nextV=`blendv${i}`,nextA=`blenda${i}`;
            filters.push(`[${v}][v${i}]xfade=transition=fade:duration=0.5:offset=${(elapsed-0.5).toFixed(3)}[${nextV}];[${a}][a${i}]acrossfade=d=0.5[${nextA}]`);
            elapsed+=lengths[i]-0.5;v=nextV;a=nextA;
          }
          await execute([...inputs,'-filter_complex',filters.join(';'),'-map',`[${v}]`,'-map',`[${a}]`,...encoding,joined]);
        }
      }
      const duration=filmDuration(recipe,media);
      if(recipe.musicId){
        onStatus({percent:85,label:'Menambahkan musik pilihanmu…'});
        await engine.writeFile('music.mp3',await sourceBytes(byId.get(recipe.musicId)!));
        const fade=Math.min(1,duration/3);
        await execute(['-i',joined,'-stream_loop','-1','-i','music.mp3','-map','0:v:0','-map','1:a:0','-c:v','copy','-c:a','aac','-b:a','128k','-af',`afade=t=in:st=0:d=${fade},afade=t=out:st=${Math.max(0,duration-fade)}:d=${fade}`,'-t',String(duration),'-movflags','+faststart','result.mp4']);
      }else await execute(['-i',joined,'-c','copy','-t',String(duration),'-movflags','+faststart','result.mp4']);
      onStatus({percent:97,label:'Menyiapkan file unduhan…'});
      const result=await engine.readFile('result.mp4') as Uint8Array;check();
      const blob=new Blob([result.buffer as ArrayBuffer],{type:'video/mp4'});
      onStatus({percent:100,label:'Film kenanganmu sudah siap.'});return blob;
    }catch(error){if(signal.aborted)throw new DOMException('Pembuatan film dibatalkan.','AbortError');throw error instanceof Error?error:new Error('Film belum berhasil dibuat. Coba dengan lebih sedikit media.');}
    finally{this.engine?.terminate();this.engine=null;}
  }
}
