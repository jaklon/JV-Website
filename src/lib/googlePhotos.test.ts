import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { setCsrf } from './api';
import { downloadPhotosFile, importGooglePhotos, waitForPhotos, type PhotosItem } from './googlePhotos';

const item:PhotosItem={id:'p',name:'sunset.jpeg',kind:'image',ready:true};
const json=(value:unknown,status=200)=>new Response(JSON.stringify(value),{status,headers:{'Content-Type':'application/json'}});
const options=()=>({signal:new AbortController().signal,open:vi.fn(),onStatus:vi.fn(),onFile:vi.fn()});
const picker={id:'s',pickerUri:'https://photos.google.com/picker/example/autoclose',pollMs:1000,timeoutMs:20_000};
let fetcher:ReturnType<typeof vi.fn>;
beforeEach(()=>{vi.useFakeTimers();fetcher=vi.fn();vi.stubGlobal('fetch',fetcher);setCsrf('fixture-csrf');});
afterEach(()=>{vi.useRealTimers();vi.unstubAllGlobals();});

describe('Google Photos file retrieval',()=>{
  it('returns a normal pending File with a normalized extension',async()=>{
    fetcher.mockResolvedValue(new Response('photo',{headers:{'Content-Type':'image/jpeg'}}));
    const file=await downloadPhotosFile('s',item,new AbortController().signal);
    expect(file.name).toBe('sunset.jpg');expect(file.type).toBe('image/jpeg');expect(file.size).toBe(5);
    expect(fetcher.mock.calls[0][1].credentials).toBe('same-origin');
  });
  it('rejects an oversized body before reading it',async()=>{
    fetcher.mockResolvedValue(new Response('x',{headers:{'Content-Type':'image/jpeg','Content-Length':String(11*1024*1024)}}));
    await expect(downloadPhotosFile('s',item,new AbortController().signal)).rejects.toThrow('terlalu besar');
  });
  it('rejects image/video mismatches and unsupported formats',async()=>{
    fetcher.mockResolvedValue(new Response('x',{headers:{'Content-Type':'video/mp4'}}));
    await expect(downloadPhotosFile('s',item,new AbortController().signal)).rejects.toThrow('tidak didukung');
  });
  it('shows the backend error when a file cannot be retrieved',async()=>{
    fetcher.mockResolvedValue(json({error:'Video masih diproses'},422));
    await expect(downloadPhotosFile('s',item,new AbortController().signal)).rejects.toThrow('masih diproses');
  });
});

describe('Google Photos import lifecycle',()=>{
  it('keeps successful attachments when another selected file fails, then cleans up the picker',async()=>{
    fetcher.mockResolvedValueOnce(json({configured:true,connected:true})).mockResolvedValueOnce(json(picker))
      .mockResolvedValueOnce(json({ready:true,items:[item,{...item,id:'v',name:'movie.mp4',kind:'video',ready:false}]}))
      .mockResolvedValueOnce(new Response('photo',{headers:{'Content-Type':'image/jpeg'}})).mockResolvedValueOnce(json({ok:true}));
    const opts=options();const result=importGooglePhotos(opts);await vi.runAllTimersAsync();
    expect(await result).toContain('1 file ditambahkan');expect(opts.onFile).toHaveBeenCalledTimes(1);
    expect(opts.open).toHaveBeenCalledWith(picker.pickerUri);
    expect(fetcher.mock.calls.at(-1)?.[1].method).toBe('DELETE');
    expect(new Headers(fetcher.mock.calls[1][1].headers).get('X-CSRF-Token')).toBe('fixture-csrf');
  });
  it('requests Photos permission separately before creating a picker',async()=>{
    fetcher.mockResolvedValueOnce(json({configured:true,connected:false,authError:'Previous denial'})).mockResolvedValueOnce(json({ok:true})).mockResolvedValueOnce(json({configured:true,connected:true}))
      .mockResolvedValueOnce(json(picker)).mockResolvedValueOnce(json({ready:true,items:[]})).mockResolvedValueOnce(json({ok:true}));
    const opts=options();const result=importGooglePhotos(opts);await vi.runAllTimersAsync();
    expect(await result).toContain('Tidak ada');expect(opts.open.mock.calls[0][0]).toContain('/photos/auth');
  });
  it('stops when Google reports a zero polling timeout and still deletes the picker',async()=>{
    fetcher.mockResolvedValueOnce(json({configured:true,connected:true})).mockResolvedValueOnce(json(picker))
      .mockResolvedValueOnce(json({ready:false,pollMs:3000,timeoutMs:0})).mockResolvedValueOnce(json({ok:true}));
    const assertion=expect(importGooglePhotos(options())).rejects.toThrow('Waktu memilih');await vi.runAllTimersAsync();await assertion;
    expect(fetcher.mock.calls.at(-1)?.[1].method).toBe('DELETE');
  });
  it('cleans up a newly created picker if cancelled while its creation is in flight',async()=>{
    const controller=new AbortController();const opts={...options(),signal:controller.signal};
    fetcher.mockResolvedValueOnce(json({configured:true,connected:true})).mockImplementationOnce(()=>{controller.abort();return Promise.resolve(json(picker));})
      .mockResolvedValueOnce(json({ok:true}));
    await expect(importGooglePhotos(opts)).rejects.toThrow();expect(opts.open).not.toHaveBeenCalled();
    expect(fetcher.mock.calls.at(-1)?.[1].method).toBe('DELETE');
  });
  it('surfaces denied permission without creating a picker',async()=>{
    fetcher.mockResolvedValueOnce(json({configured:true,connected:false})).mockResolvedValueOnce(json({ok:true})).mockResolvedValueOnce(json({connected:false,authError:'Pilih Gmail yang sama'}));
    const assertion=expect(importGooglePhotos(options())).rejects.toThrow('Gmail yang sama');await vi.runAllTimersAsync();await assertion;
    expect(fetcher).toHaveBeenCalledTimes(3);
  });
  it('cancels the wait immediately without a leftover timer',async()=>{
    const controller=new AbortController();const assertion=expect(waitForPhotos(3000,controller.signal)).rejects.toThrow();controller.abort();await assertion;
    expect(vi.getTimerCount()).toBe(0);
  });
});
