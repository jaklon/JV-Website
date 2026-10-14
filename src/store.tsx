import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import type { StoryData, Session, Media, Memory, Album, Letter, ImportantDate, FilmProject, Settings } from './types';
import { api, jsonBody, setCsrf } from './lib/api';
import { DEMO_USER, demoData } from './lib/demo';
import { mediaDuration, validateUpload } from './lib/media';

type Resource = 'memories' | 'albums' | 'letters' | 'dates' | 'projects';
type Store = {
  session: Session | null; data: StoryData | null; loading: boolean; error: string;
  enterDemo: () => void; logout: () => Promise<void>; refresh: () => Promise<void>;
  save: (resource: Resource, value: Record<string,unknown>, id?: string) => Promise<string>;
  remove: (resource: Resource, id: string) => Promise<void>;
  upload: (file: File, memoryId?: string) => Promise<Media>;
  removeMedia: (media: Media) => Promise<void>;
  updateSettings: (settings: Settings) => Promise<void>;
};
const Context = createContext<Store | null>(null);
export function StoryProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<Session | null>(null), [data, setData] = useState<StoryData | null>(null);
  const [loading, setLoading] = useState(true), [error, setError] = useState('');
  const refresh = useCallback(async () => { if (session?.demo) return; const next = await api<StoryData>('/bootstrap'); setData(next); },[session?.demo]);
  useEffect(() => {
    let active = true;
    api<Session>('/session').then(async next => {
      if (!active) return;
      setCsrf(next.csrf); setSession(next);
      if (next.user) { const result = await api<StoryData>('/bootstrap'); if (active) setData(result); }
    }).catch(e => { if (active) { setError(e.message); setSession({ user:null,csrf:'',configured:false }); } }).finally(()=>{ if (active) setLoading(false); });
    const expired = () => { setSession({user:null,csrf:'',configured:false}); setData(null); setError('Sesi berakhir. Silakan masuk kembali.'); };
    window.addEventListener('session-expired',expired);
    return () => { active=false; window.removeEventListener('session-expired',expired); };
  },[]);
  const enterDemo = () => { if (!import.meta.env.DEV && import.meta.env.VITE_ENABLE_DEMO !== 'true') return; setSession({user:DEMO_USER,csrf:'demo',configured:false,demo:true}); setData(demoData()); setError(''); };
  const logout = async () => { if (!session?.demo) await api('/auth/logout',{method:'POST'}); setData(null); setSession({user:null,csrf:'',configured:session?.configured ?? false}); window.location.assign(import.meta.env.BASE_URL); };
  const save = async (resource: Resource, value: Record<string,unknown>, id?: string): Promise<string> => {
    if (!session?.user) throw new Error('Silakan masuk.');
    if (!session.demo) { const result = await api<{id:string}>(`/${resource}${id ? `/${id}` : ''}`,{method:id?'PATCH':'POST',body:jsonBody(value)}); await refresh(); return result.id; }
    const nextId = id ?? crypto.randomUUID(), created = new Date().toISOString();
    setData(previous => {
      if (!previous) return previous;
      const existing = previous[resource].find(x=>x.id===id);
      const record = {id:nextId,user_id:session.user!.id,created_at:created,media:[],updated_at:created,sent_at:value.status==='sent'?created:null,...existing,...value};
      const list = id ? previous[resource].map(x=>x.id===id?record:x) : [record,...previous[resource]];
      return {...previous,[resource]:list} as StoryData;
    });
    return nextId;
  };
  const remove = async (resource: Resource,id:string) => {
    if (!session?.demo) { await api(`/${resource}/${id}`,{method:'DELETE'}); await refresh(); return; }
    setData(previous=> previous ? {...previous,[resource]:previous[resource].filter(x=>x.id!==id),...(resource==='albums'?{memories:previous.memories.map(m=>m.album_id===id?{...m,album_id:null}:m)}:{})} as StoryData : previous);
  };
  const upload = async (file:File,memoryId?:string):Promise<Media> => {
    validateUpload(file); const duration = await mediaDuration(file);
    if (!session?.demo) { const form = new FormData(); form.append('file',file); form.append('duration',String(duration)); if(memoryId) form.append('memory_id',memoryId); const result = await api<Media>('/media',{method:'POST',body:form}); await refresh(); return result; }
    const item: Media = {id:crypto.randomUUID(),memory_id:memoryId??null,user_id:session!.user!.id,kind:file.type.startsWith('image/')?'image':file.type.startsWith('video/')?'video':'audio',url:URL.createObjectURL(file),name:file.name,size:file.size,duration:duration||undefined};
    setData(previous=>previous ? item.kind==='audio'?{...previous,audio:[...previous.audio,item]}:{...previous,memories:previous.memories.map(m=>m.id===memoryId?{...m,media:[...m.media,item]}:m)} : previous);
    return item;
  };
  const removeMedia = async (item:Media) => {
    if (!session?.demo) { await api(`/media/${item.id}`,{method:'DELETE'}); await refresh(); return; }
    if(item.url.startsWith('blob:')) URL.revokeObjectURL(item.url);
    setData(previous=>previous ? {...previous,memories:previous.memories.map(m=>({...m,media:m.media.filter(x=>x.id!==item.id)})),audio:previous.audio.filter(x=>x.id!==item.id)}:previous);
  };
  const updateSettings = async (settings:Settings) => { if (!session?.demo) { await api('/settings',{method:'PATCH',body:jsonBody(settings)}); await refresh(); } else setData(previous=>previous?{...previous,settings}:previous); };
  return <Context.Provider value={{session,data,loading,error,enterDemo,logout,refresh,save,remove,upload,removeMedia,updateSettings}}>{children}</Context.Provider>;
}
export function useStory():Store { const context = useContext(Context); if (!context) throw new Error('StoryProvider missing'); return context; }
export type { Memory, Album, Letter, ImportantDate, FilmProject };
