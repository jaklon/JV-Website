import { useEffect, useRef, useState } from 'react';
import { ExternalLink, Images, LoaderCircle, X } from 'lucide-react';
import { importGooglePhotos } from '../lib/googlePhotos';

export function GooglePhotosImport({disabled,demo,onFile,onBusy}:{disabled:boolean;demo:boolean;onFile:(file:File)=>void;onBusy:(busy:boolean)=>void}) {
  const [busy,setBusy]=useState(false),[status,setStatus]=useState(''),[link,setLink]=useState(''),[error,setError]=useState(false);
  const task=useRef<AbortController|null>(null),popup=useRef<Window|null>(null),mounted=useRef(true);
  useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;task.current?.abort();try{popup.current?.close();}catch{/* Google may sever the opener. */}};},[]);
  const open=(url:string)=>{
    if(!mounted.current)return;setLink(url);
    // A visible link also supports mobile, blocked popups and a detached Google window.
    try{if(popup.current&&!popup.current.closed)popup.current.location.href=url;}catch{/* Use the explicit link. */}
  };
  const start=async()=>{
    const controller=new AbortController();task.current=controller;
    popup.current=window.open('about:blank','_blank','popup,width=1000,height=760');
    if(popup.current){popup.current.document.title='Google Photos · JV Story';popup.current.document.body.textContent='Menghubungkan Google Photos…';}
    setBusy(true);onBusy(true);setError(false);setStatus('');setLink('');
    try{
      const result=await importGooglePhotos({signal:controller.signal,open,onFile,onStatus:message=>{if(mounted.current)setStatus(message);}});
      if(mounted.current)setStatus(result);
    }catch(error){if(mounted.current){setError(!controller.signal.aborted);setStatus(controller.signal.aborted?'Impor dibatalkan. File yang sudah ditambahkan tetap ada.':(error as Error).message);}}
    finally{
      try{popup.current?.close();}catch{/* Detached windows can be closed by the user. */}
      if(mounted.current){setBusy(false);setLink('');onBusy(false);}task.current=null;
    }
  };
  return <div className="google-photos-import">
    <div className="google-photos-actions"><button type="button" className="button secondary" disabled={disabled||busy||demo} onClick={start}><Images size={18}/>Ambil dari Google Photos</button>
      {busy&&<button type="button" className="button ghost" onClick={()=>task.current?.abort()}><X size={16}/>Batalkan impor</button>}</div>
    <p className="google-photos-hint">{demo?'Google Photos tersedia setelah login dengan Gmail kalian.':'Pilih dari pustaka Google Photos milik akunmu. Foto JPEG/PNG/WebP ≤10 MB · Video MP4 ≤50 MB.'}</p>
    {busy&&link&&<a className="google-photos-link" href={link} target="_blank" rel="noopener noreferrer">Buka Google Photos <ExternalLink size={14}/></a>}
    {status&&<p className={`google-photos-status${error?' is-error':''}`} role={error?'alert':'status'}>{busy&&<LoaderCircle size={15} className="spin"/>}{status}</p>}
  </div>;
}
