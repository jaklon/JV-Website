import { api } from './api';
import { validateUpload } from './media';

type Picker = { id:string; pickerUri:string; pollMs:number; timeoutMs:number };
export type PhotosItem = { id:string; name:string; kind:'image'|'video'; ready:boolean };
type Selection = { ready:boolean; pollMs?:number; timeoutMs?:number; items?:PhotosItem[] };
type PhotosStatus = { connected:boolean; configured:boolean; authError:string|null };
type ImportOptions = {signal:AbortSignal;open:(url:string)=>void;onStatus:(message:string)=>void;onFile:(file:File)=>void};

export function waitForPhotos(ms:number,signal:AbortSignal):Promise<void> {
  signal.throwIfAborted();
  return new Promise((resolve,reject)=>{
    const cancel=()=>{clearTimeout(timer);signal.removeEventListener('abort',cancel);reject(signal.reason);};
    const timer=setTimeout(()=>{signal.removeEventListener('abort',cancel);resolve();},ms);
    signal.addEventListener('abort',cancel,{once:true});
  });
}

export async function downloadPhotosFile(sessionId:string,item:PhotosItem,signal:AbortSignal):Promise<File> {
  const response=await fetch(`${import.meta.env.BASE_URL}api/photos/sessions/${sessionId}/files/${item.id}`,{credentials:'same-origin',signal});
  if(!response.ok){const payload=await response.json().catch(()=>({error:'Pengambilan file Google Photos gagal.'}));throw new Error(payload.error);}
  const mime=(response.headers.get('content-type')??'').split(';')[0];
  const extensions:Record<string,string>={'image/jpeg':'jpg','image/png':'png','image/webp':'webp','video/mp4':'mp4'};
  if(!extensions[mime]||(item.kind==='video')!==mime.startsWith('video/'))throw new Error('Format media Google Photos tidak didukung.');
  const limit=(item.kind==='video'?50:10)*1024*1024;
  if(Number(response.headers.get('content-length'))>limit){await response.body?.cancel();throw new Error('File Google Photos terlalu besar.');}
  const reader=response.body?.getReader();if(!reader)throw new Error('File Google Photos tidak dapat dibaca.');
  const chunks:Uint8Array<ArrayBuffer>[]=[];let size=0;
  try{
    while(true){const {value,done}=await reader.read();if(done)break;size+=value.byteLength;
      if(size>limit)throw new Error('File Google Photos terlalu besar.');chunks.push(value as Uint8Array<ArrayBuffer>);}
  }catch(error){await reader.cancel().catch(()=>{});throw error;}finally{reader.releaseLock();}
  signal.throwIfAborted();
  const name=(item.name.replace(/\.[^.]+$/,'')||'Google Photos')+'.'+extensions[mime];
  const file=new File(chunks,name,{type:mime});validateUpload(file);return file;
}

/** Selected files become pending attachments. Save uses the existing private upload endpoint. */
export async function importGooglePhotos({signal,open,onStatus,onFile}:ImportOptions):Promise<string> {
  let picker:Picker|undefined;
  try{
    onStatus('Menghubungkan Google Photos…');
    let status=await api<PhotosStatus>('/photos/status',{signal});
    if(!status.configured)throw new Error('Login Google belum dikonfigurasi di server.');
    if(!status.connected){
      await api('/photos/prepare',{method:'POST',signal});
      open(`${import.meta.env.BASE_URL}api/photos/auth`);
      onStatus('Izinkan Google Photos dengan Gmail yang sedang masuk. Cerita kamu tetap ada di tab ini.');
      const deadline=Date.now()+600_000;
      while(!status.connected){
        if(Date.now()>=deadline)throw new Error('Waktu menghubungkan Google Photos habis. Coba lagi.');
        await waitForPhotos(3000,signal);status=await api<PhotosStatus>('/photos/status',{signal});
        if(status.authError)throw new Error(status.authError);
      }
    }
    onStatus('Menyiapkan pemilih foto dan video…');
    // Finish creation even when cancelled, so its ID is available for cleanup.
    picker=await api<Picker>('/photos/sessions',{method:'POST'});signal.throwIfAborted();
    open(picker.pickerUri);onStatus('Pilih foto/video di Google Photos, lalu tekan Selesai. Maksimal 20 file.');
    let deadline=Date.now()+Math.min(picker.timeoutMs,600_000),interval=picker.pollMs;let selected:Selection;
    while(true){
      if(Date.now()>=deadline)throw new Error('Waktu memilih Google Photos habis. Mulai lagi.');
      await waitForPhotos(Math.min(Math.max(1000,interval),deadline-Date.now()),signal);
      if(Date.now()>=deadline)throw new Error('Waktu memilih Google Photos habis. Mulai lagi.');
      selected=await api<Selection>(`/photos/sessions/${picker.id}`,{signal});if(selected.ready)break;
      interval=selected.pollMs??3000;deadline=Math.min(deadline,Date.now()+(selected.timeoutMs??600_000));
    }
    const items=selected.items??[];if(!items.length)return 'Tidak ada foto atau video yang dipilih.';
    let bytes=0,added=0;const failures:string[]=[];
    for(const [index,item] of items.entries()){
      signal.throwIfAborted();onStatus(`Mengambil ${index+1}/${items.length}: ${item.name}…`);
      try{
        if(!item.ready)throw new Error('Video masih diproses Google Photos. Pilih lagi setelah selesai diproses.');
        const file=await downloadPhotosFile(picker.id,item,signal);
        if(bytes+file.size>100*1024*1024)throw new Error('Total sekali impor maksimal 100 MB. Tambahkan sisanya lewat pemilihan berikutnya.');
        bytes+=file.size;onFile(file);added++;
      }catch(error){if(signal.aborted)throw error;failures.push(`${item.name}: ${(error as Error).message}`);}
    }
    return `${added} file ditambahkan. Tekan Simpan kenangan untuk menyimpannya.${failures.length?` ${failures.length} file belum ditambahkan. ${failures.join(' ')}`:''}`;
  }finally{if(picker)await api(`/photos/sessions/${picker.id}`,{method:'DELETE'}).catch(()=>{});}
}
