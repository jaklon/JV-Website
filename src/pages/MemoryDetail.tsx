import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, CalendarDays, MapPin, Pencil, Trash2, ImagePlus } from 'lucide-react';
import { useStory } from '../store';
import { Empty, ErrorMessage, Modal } from '../components/ui';
import { MemoryForm } from '../components/MemoryForm';
import { formatDate } from '../lib/dates';
import type { Media } from '../types';

export function MemoryDetail() {
  const {id}=useParams(),navigate=useNavigate(),{data,session,remove}=useStory();
  const [editing,setEditing]=useState(false),[viewing,setViewing]=useState<Media|null>(null),[error,setError]=useState(''),[busy,setBusy]=useState(false);
  const memory=data?.memories.find(m=>m.id===id);
  if(!memory)return <Empty title="Cerita ini belum ditemukan" description="Mungkin sudah dihapus atau alamatnya tidak tepat." action={<Link to="/timeline" className="button secondary">Kembali ke timeline</Link>}/>;
  const album=data?.albums.find(a=>a.id===memory.album_id),author=data?.users.find(u=>u.id===memory.user_id);
  return <><Link to="/timeline" className="back-link"><ArrowLeft size={16}/>Kembali ke cerita kita</Link><div className="detail-heading"><span className="eyebrow">{album?.title??'SEBUAH HALAMAN DARI KITA'}</span><h1>{memory.title}</h1><div className="detail-meta"><span><CalendarDays size={16}/>{formatDate(memory.date)}</span>{memory.location&&<span><MapPin size={16}/>{memory.location}</span>}<span>Disimpan oleh {author?.name??'pasangan'}</span></div>{memory.user_id===session?.user?.id&&<div className="detail-actions"><button className="button secondary" onClick={()=>setEditing(true)}><Pencil size={15}/>Edit kenangan</button><button className="icon-button danger" aria-label="Hapus kenangan" disabled={busy} onClick={async()=>{if(!window.confirm('Hapus cerita dan semua foto/video di dalamnya secara permanen?'))return;setBusy(true);try{await remove('memories',memory.id);navigate('/timeline');}catch(e){setError((e as Error).message);}finally{setBusy(false);}}}><Trash2 size={17}/></button></div>}</div><ErrorMessage message={error}/>{memory.media.length?<div className="detail-gallery">{memory.media.map((media,i)=>media.kind==='video'?<div className={`detail-media ${i===0?'first':''}`} key={media.id}><video src={media.url} controls playsInline preload="metadata"/></div>:<button className={`detail-media ${i===0?'first':''}`} key={media.id} onClick={()=>setViewing(media)} aria-label={`Perbesar ${media.name}`}><img src={media.url} alt={media.name} loading={i?'lazy':'eager'}/></button>)}</div>:<div className="detail-no-photo"><ImagePlus size={34} strokeWidth={1}/><span>Cerita ini hidup dalam kata-kata.</span></div>}<article className="detail-story"><span className="eyebrow">YANG INGIN KITA INGAT</span><p>{memory.story||'Belum ada cerita tertulis untuk momen ini.'}</p><span className="handwriting">a little piece of us.</span></article>{editing&&<MemoryForm memory={memory} onClose={()=>setEditing(false)}/>} {viewing&&<Modal title={viewing.name} wide onClose={()=>setViewing(null)}><img className="lightbox-image" src={viewing.url} alt={viewing.name}/></Modal>}</>;
}
