import { useState, type FormEvent } from 'react';
import { Camera, LoaderCircle, Trash2, UploadCloud } from 'lucide-react';
import { useStory } from '../store';
import { ErrorMessage, Modal } from './ui';
import type { Memory } from '../types';
import { today } from '../lib/dates';
import { validateUpload } from '../lib/media';
import { GooglePhotosImport } from './GooglePhotosImport';

export function MemoryForm({memory,onClose}:{memory?:Memory;onClose:()=>void}) {
  const {data,session,save,upload,removeMedia}=useStory();
  const [photosBusy,setPhotosBusy]=useState(false);
  const [title,setTitle]=useState(memory?.title??''),[date,setDate]=useState(memory?.date??today()),[location,setLocation]=useState(memory?.location??''),[story,setStory]=useState(memory?.story??''),[album,setAlbum]=useState(memory?.album_id??'');
  const [id,setId]=useState(memory?.id),[files,setFiles]=useState<File[]>([]),[busy,setBusy]=useState(false),[error,setError]=useState(''),[status,setStatus]=useState('');
  const existing = data?.memories.find(m=>m.id===id)?.media??[];
  const submit=async(e:FormEvent)=>{
    e.preventDefault();if(photosBusy||busy)return;setBusy(true);setError('');
    try {
      setStatus('Menyimpan cerita…');
      const saved=await save('memories',{title,date,location,story,album_id:album||null},id);setId(saved);
      const remaining=[...files];
      while(remaining.length){setStatus(`Mengunggah ${remaining[0].name}…`);await upload(remaining[0],saved);remaining.shift();setFiles([...remaining]);}
      onClose();
    } catch(e){setError((e as Error).message+' Cerita yang sudah tersimpan tetap aman; kamu bisa mencoba lagi.');}finally{setBusy(false);setStatus('');}
  };
  return <Modal title={id?'Edit kenangan':'Simpan kenangan baru'} onClose={()=>!busy&&!photosBusy&&onClose()} wide><form onSubmit={submit} className="form-stack">
    <label>Judul kenangan<input required maxLength={150} value={title} onChange={e=>setTitle(e.target.value)} placeholder="Hari yang ingin kita ingat…"/></label>
    <div className="form-row"><label>Tanggal kejadian<input required type="date" value={date} onChange={e=>setDate(e.target.value)}/></label><label>Lokasi <span className="optional">opsional</span><input maxLength={180} value={location} onChange={e=>setLocation(e.target.value)} placeholder="Di mana cerita ini terjadi?"/></label></div>
    <label>Cerita kalian<textarea rows={5} maxLength={30000} value={story} onChange={e=>setStory(e.target.value)} placeholder="Hal kecil apa yang membuat hari ini istimewa?"/></label>
    <label>Album<select value={album} onChange={e=>setAlbum(e.target.value)}><option value="">Tanpa album</option>{data?.albums.map(a=><option key={a.id} value={a.id}>{a.title}</option>)}</select></label>
    <GooglePhotosImport disabled={busy} demo={!!session?.demo} onFile={file=>setFiles(previous=>[...previous,file])} onBusy={setPhotosBusy}/>
    <label className="upload-zone"><UploadCloud size={27} strokeWidth={1.4}/><strong>Tambahkan foto atau video</strong><span>Foto hingga 10 MB · Video MP4 hingga 50 MB</span><input aria-label="Unggah foto atau video" type="file" multiple accept="image/jpeg,image/png,image/webp,video/mp4" disabled={busy} onChange={e=>{try{const next=Array.from(e.target.files??[]);next.forEach(validateUpload);setFiles(previous=>[...previous,...next]);setError('');}catch(err){setError((err as Error).message);}e.target.value='';}}/></label>
    {!!existing.length&&<div className="upload-list">{existing.map(m=><div key={m.id}><Camera size={16}/><span>{m.name}</span><button type="button" className="icon-button" disabled={busy} aria-label={`Hapus ${m.name}`} onClick={async()=>{setBusy(true);try{await removeMedia(m);}catch(e){setError((e as Error).message);}finally{setBusy(false);}}}><Trash2 size={16}/></button></div>)}</div>}
    {!!files.length&&<div className="upload-list">{files.map((file,i)=><div key={`${file.name}-${i}`}><Camera size={16}/><span>{file.name}</span><button type="button" className="icon-button" disabled={busy} aria-label={`Batalkan ${file.name}`} onClick={()=>setFiles(files.filter((_,index)=>index!==i))}><Trash2 size={16}/></button></div>)}</div>}
    <ErrorMessage message={error}/><div className="form-actions"><span>{status||(photosBusy?'Selesaikan atau batalkan impor terlebih dahulu.':'Setiap cerita layak disimpan.')}</span><button className="button primary" disabled={busy||photosBusy}>{busy?<LoaderCircle size={17} className="spin"/>:<Camera size={17}/>} {busy?'Menyimpan…':'Simpan kenangan'}</button></div>
  </form></Modal>;
}
