import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { Link, useLocation, useSearchParams } from 'react-router-dom';
import { Heart, LoaderCircle, MessageCircle, Plus, Send, Trash2, ArrowUpRight } from 'lucide-react';
import { useStory } from '../store';
import { api, jsonBody } from '../lib/api';
import { chatSuggestions, safeChatHref, type ChatMessage, type ChatStatus, type ChatThread } from '../lib/chat';
import { ErrorMessage, Modal, PageTitle } from './ui';

const unavailable: ChatStatus = { enabled: false, installed: false, model: 'Qwen3 · 0.6B', reason: 'Chat sedang disiapkan. Kenangan kalian tetap dapat dibuka.', daily_remaining: 20, monthly_remaining: 600, shared: true };
export function ChatWorkspace({ initialThread = '' }: { initialThread?: string }) {
  const {session}=useStory();
  const [threads,setThreads]=useState<ChatThread[]>([]),[thread,setThread]=useState(initialThread),[messages,setMessages]=useState<ChatMessage[]>([]);
  const [status,setStatus]=useState<ChatStatus>(unavailable),[text,setText]=useState(''),[ack,setAck]=useState(false),[busy,setBusy]=useState(false),[loading,setLoading]=useState(true),[error,setError]=useState('');
  const end=useRef<HTMLDivElement>(null),generation=useRef(0),mounted=useRef(true),sending=useRef(false),selected=useRef(thread);
  selected.current=thread;
  useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;generation.current++;};},[]);
  const refresh=useCallback(async()=>{
    if(session?.demo){setLoading(false);return;}
    const next=await api<ChatStatus>('/chat/status');if(!mounted.current)return;setStatus(next);
    if(next.installed){const list=await api<ChatThread[]>('/chat/threads');if(mounted.current)setThreads(list);}
  },[session?.demo]);
  useEffect(()=>{void refresh().catch(e=>{if(mounted.current)setError(e.message);}).finally(()=>{if(mounted.current)setLoading(false);});},[refresh]);
  useEffect(()=>{
    const version=++generation.current;setMessages([]);setError('');
    if(!thread||session?.demo)return;
    void api<{messages:ChatMessage[]}>(`/chat/threads/${thread}`).then(r=>{if(mounted.current&&version===generation.current)setMessages(r.messages);}).catch(e=>{if(mounted.current&&version===generation.current)setError(e.message);});
  },[thread,session?.demo]);
  useEffect(()=>{if(messages.length||busy)end.current?.scrollIntoView({block:'nearest',behavior:'smooth'});},[messages,busy]);
  // Read-only polling keeps shared conversations current; never retries inference.
  useEffect(()=>{
    if(!thread||session?.demo||!status.installed)return;
    const timer=window.setInterval(()=>{
      if(document.visibilityState!=='visible'||sending.current)return;
      void api<{messages:ChatMessage[]}>(`/chat/threads/${thread}`).then(r=>{if(mounted.current&&selected.current===thread)setMessages(r.messages);}).catch(()=>{});
    },5000);return()=>window.clearInterval(timer);
  },[thread,session?.demo,status.installed]);
  const canSend=status.enabled&&status.daily_remaining>0&&status.monthly_remaining>0&&!session?.demo;
  const createThread=async(title:string)=>{
    const created=await api<{id:string}>('/chat/threads',{method:'POST',body:jsonBody({title:title.slice(0,120)})});
    if(mounted.current){selected.current=created.id;setThread(created.id);await refresh();}return created.id;
  };
  const submit=async(e:FormEvent)=>{
    e.preventDefault();if(!text.trim()||!ack||!canSend||sending.current)return;
    const content=text.trim();setBusy(true);sending.current=true;setError('');
    let id=thread;
    try {
      if(!id)id=await createThread(content);
      const result=await api<{messages:ChatMessage[];status:ChatStatus}>(`/chat/threads/${id}/messages`,{method:'POST',body:jsonBody({content,request_id:crypto.randomUUID().replaceAll('-',''),shared_acknowledged:true})});
      if(mounted.current&&selected.current===id){setMessages(result.messages);setStatus(result.status);setText('');}
      await refresh();
    }catch(e){
      if(mounted.current){setError((e as Error).message);setText('');
        if(id)try{const next=await api<{messages:ChatMessage[]}>(`/chat/threads/${id}`);if(selected.current===id)setMessages(next.messages);}catch{}
        try{await refresh();}catch{}
      }
    }finally{sending.current=false;if(mounted.current)setBusy(false);}
  };
  const remove=async(item:ChatThread)=>{
    if(!window.confirm('Hapus percakapan ini beserta semua pesannya untuk kalian berdua? Kuota yang terpakai tetap dihitung.'))return;
    try{await api(`/chat/threads/${item.id}`,{method:'DELETE'});if(thread===item.id)setThread('');await refresh();}catch(e){setError((e as Error).message);}
  };
  return <div className="chat-workspace">
    <aside className="chat-sidebar" aria-label="Riwayat percakapan bersama">
      <button className="button secondary" disabled={busy||!canSend} onClick={()=>{setThread('');setMessages([]);setText('');setError('');}}><Plus size={16}/>Cerita baru</button>
      <span className="eyebrow">PERCAKAPAN KALIAN</span>
      {loading?<LoaderCircle size={18} className="spin"/>:threads.length===0?<p className="chat-hint">Belum ada percakapan.</p>:<ul>{threads.map(item=><li key={item.id} className={item.id===thread?'active':''}><button disabled={busy} onClick={()=>setThread(item.id)}><strong>{item.title}</strong><small>{item.author??'Kalian'}</small></button>{item.user_id===session?.user?.id&&<button className="icon-button" aria-label={`Hapus percakapan ${item.title}`} disabled={busy} onClick={()=>void remove(item)}><Trash2 size={14}/></button>}</li>)}</ul>}
    </aside>
    <section className="chat-conversation" aria-label="Chat JV Story">
      <div className="chat-top"><span><Heart size={16}/>Teman cerita</span><small>{status.daily_remaining}/20 hari ini · {status.monthly_remaining}/600 bulan ini</small></div>
      <p className="chat-shared-note">Percakapan ini dapat dibaca kamu dan pasangan. Draft surat tidak digunakan.</p>
      {!status.enabled&&<p className="chat-notice" role="status">{session?.demo?'Chat AI tersedia setelah backend dan GCP disiapkan. Mode demo tidak mengirim cerita ke model.':status.reason}</p>}
      {status.enabled&&!canSend&&<p className="chat-notice" role="status">Kuota bersama sudah habis. Riwayat tetap bisa dibaca.</p>}
      <div className="chat-messages" aria-live="polite" aria-relevant="additions text">
        {!messages.length&&!busy&&<div className="chat-welcome"><span className="chat-heart"><MessageCircle size={28} strokeWidth={1.3}/></span><h2>Ada cerita apa hari ini?</h2><p>Kenangan kecil, pertanyaan tentang kita, atau perasaan yang ingin kamu ceritakan.</p><div className="chat-suggestions">{chatSuggestions.map(s=><button key={s} disabled={!canSend} onClick={()=>setText(s)}>{s}<ArrowUpRight size={14}/></button>)}</div></div>}
        {messages.map(message=><article key={message.id} className={`chat-message ${message.role}`}><span className="chat-author">{message.role==='assistant'?'Teman cerita':message.author??'Kalian'}</span><p>{message.content}</p>{message.status==='pending'&&<small><LoaderCircle size={12} className="spin"/> Sedang menyiapkan jawaban…</small>}{message.status==='failed'&&<small className="chat-failed">Jawaban belum tersedia. Tidak dicoba ulang otomatis.</small>}{message.sources.length>0&&<div className="chat-sources"><span>Kenangan terkait</span>{message.sources.filter(s=>safeChatHref(s.href)).map(source=><Link key={source.key} to={source.href}>{source.title}<ArrowUpRight size={12}/></Link>)}</div>}</article>)}
        {busy&&<div className="chat-thinking" role="status"><LoaderCircle size={16} className="spin"/>Menyiapkan jawaban… Pesan pertama bisa membutuhkan waktu lebih lama.</div>}<div ref={end}/>
      </div>
      <form className="chat-composer" onSubmit={e=>void submit(e)}>
        {!ack&&<label className="chat-consent"><input type="checkbox" checked={ack} onChange={e=>setAck(e.target.checked)}/>Saya tahu pesan dapat dibaca pasangan dan diproses di server GCP JV Story.</label>}
        <ErrorMessage message={error}/><label className="sr-only" htmlFor="chat-input">Pesan untuk teman cerita</label>
        <div className="chat-input-row"><textarea id="chat-input" rows={2} maxLength={2000} placeholder="Ceritakan sesuatu…" value={text} onChange={e=>setText(e.target.value)} disabled={busy||!canSend}/><button className="button primary" type="submit" aria-label="Kirim pesan" disabled={busy||!canSend||!ack||!text.trim()}>{busy?<LoaderCircle className="spin" size={17}/>:<Send size={17}/>}</button></div>
        <small className="chat-hint">Jawaban AI bisa keliru. Buka sumber untuk memastikan cerita dan tanggalnya.</small>
      </form>
    </section>
  </div>;
}
export function ChatPage(){const [params]=useSearchParams();return <><PageTitle eyebrow="RUANG UNTUK BERCERITA" title="Cerita yang ingin didengar." description="Teman ngobrol kecil untuk mengenang perjalanan kalian."/><ChatWorkspace key={params.get('thread')??''} initialThread={params.get('thread')??''}/></>;}
export function ChatLauncher(){
  const location=useLocation();const [open,setOpen]=useState(false);
  useEffect(()=>setOpen(false),[location.pathname,location.search,location.hash]);
  if(location.pathname==='/chat')return null;
  return <><button className="chat-launcher" aria-label="Buka teman cerita" onClick={()=>setOpen(true)}><MessageCircle size={20}/><span>Teman cerita</span></button>{open&&<Modal title="Teman cerita" className="chat-modal" wide onClose={()=>setOpen(false)}><Link className="chat-history-link text-link" to="/chat">Buka halaman percakapan<ArrowUpRight size={13}/></Link><ChatWorkspace/></Modal>}</>;
}
