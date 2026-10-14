import { useEffect, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { motion } from 'motion/react';
import { ArrowUpRight, ImagePlus, X } from 'lucide-react';
import { Link } from 'react-router-dom';
import type { Memory } from '../types';
import { formatDate } from '../lib/dates';

export function Modal({title,children,onClose,wide=false,className=''}:{title:string;children:ReactNode;onClose:()=>void;wide?:boolean;className?:string}) {
  const dialog = useRef<HTMLDialogElement>(null);
  useEffect(()=>{ const element=dialog.current!; const focused=document.activeElement as HTMLElement; element.showModal(); const previous=document.body.style.overflow; document.body.style.overflow='hidden'; return ()=>{element.close();document.body.style.overflow=previous;focused?.focus();};},[]);
  return createPortal(<dialog ref={dialog} className={`modal ${wide?'modal-wide':''} ${className}`} onCancel={e=>{e.preventDefault();onClose();}} onClick={e=>{if(e.target===e.currentTarget)onClose();}} aria-labelledby="dialog-heading"><div className="modal-inner"><div className="modal-heading"><h2 id="dialog-heading">{title}</h2><button className="icon-button" onClick={onClose} aria-label="Tutup"><X size={20}/></button></div>{children}</div></dialog>,document.body);
}
export function PageTitle({eyebrow,title,description,action}:{eyebrow:string;title:string;description:string;action?:ReactNode}) {
  return <div className="page-heading"><div><span className="eyebrow">{eyebrow}</span><h1>{title}</h1><p>{description}</p></div>{action}</div>;
}
export function Empty({title,description,action}:{title:string;description:string;action?:ReactNode}) {
  return <div className="empty-state"><ImagePlus size={32} strokeWidth={1}/><h3>{title}</h3><p>{description}</p>{action}</div>;
}
export function ErrorMessage({message}:{message:string}) { return message?<p className="error-message" role="alert">{message}</p>:null; }
export function MemoryCard({memory,index=0}:{memory:Memory;index?:number}) {
  const cover=memory.media.find(m=>m.kind==='image') ?? memory.media[0];
  return <motion.article className="memory-card" initial={{opacity:0,y:18}} whileInView={{opacity:1,y:0}} viewport={{once:true}} transition={{delay:Math.min(index,3)*0.06}}>
    <Link to={`/kenangan/${memory.id}`} className="memory-image" aria-label={`Buka ${memory.title}`}>
      {cover?.kind==='image'?<img src={cover.url} alt={memory.title} loading="lazy"/>:cover?.kind==='video'?<video src={cover.url} preload="metadata" muted/>:<div className="photo-placeholder"><ImagePlus size={36}/></div>}
      <span className="memory-image-arrow"><ArrowUpRight size={20}/></span>
      {memory.media.length>1&&<span className="media-count">{memory.media.length} momen</span>}
    </Link>
    <div className="memory-card-copy"><span className="small-date">{formatDate(memory.date,true)}</span><h3><Link to={`/kenangan/${memory.id}`}>{memory.title}</Link></h3><p>{memory.location||'Cerita kita'}</p></div>
  </motion.article>;
}
