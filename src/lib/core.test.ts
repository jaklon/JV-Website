import { describe, expect, it } from 'vitest';
import { automaticRecipe, filmDuration, validateFilm } from './film';
import { countdown, daysTogether, nextOccurrence } from './dates';
import type { FilmRecipe, Media, Memory } from '../types';
const photo:Media={id:'p',user_id:'u',memory_id:'m',kind:'image',url:'/media/p',name:'photo.jpg',size:100};
const video:Media={...photo,id:'v',kind:'video',duration:20};
const recipe:FilmRecipe={version:1,orientation:'landscape',transition:'fade',musicId:null,items:[{mediaId:'p',duration:4,start:0,end:0},{mediaId:'v',duration:4,start:5,end:15}]};
describe('film validation',()=>{
  it('counts transition overlap and video trim',()=>expect(filmDuration(recipe,[photo,video])).toBe(13.5));
  it('accepts a valid mixed project',()=>expect(validateFilm(recipe,[photo,video])).toEqual([]));
  it('rejects missing source instead of silently skipping it',()=>expect(validateFilm(recipe,[photo]).join()).toContain('tidak tersedia'));
  it('rejects trims outside the actual media duration',()=>expect(validateFilm({...recipe,items:[{mediaId:'v',duration:4,start:0,end:21}]},[video]).join()).toContain('Potongan'));
  it('rejects empty and too-large projects',()=>{expect(validateFilm({...recipe,items:[]},[])).not.toEqual([]);expect(validateFilm(recipe,[{...photo,size:101*1024*1024},video]).join()).toContain('100 MB');});
  it('rejects 31 scenes and durations over 3 minutes',()=>{const items=Array.from({length:31},()=>({mediaId:'p',duration:15,start:0,end:0}));const issues=validateFilm({...recipe,items},[photo]).join();expect(issues).toContain('30 adegan');expect(issues).toContain('3 menit');});
  it('orders automatic album media by event date without truncating',()=>{const memory=(id:string,date:string):Memory=>({id,user_id:'u',date,title:id,story:'',location:'',created_at:date,album_id:'a',media:[{...photo,id}]});const result=automaticRecipe([memory('later','2025-02-01'),memory('earlier','2025-01-01')],'a');expect(result.items.map(i=>i.mediaId)).toEqual(['earlier','later']);});
});
describe('Jakarta calendar dates',()=>{
  it('does not count future relationship dates as negative days',()=>expect(daysTogether('2026-12-01','2026-10-08')).toBe(0));
  it('keeps the anniversary on today',()=>expect(countdown('2024-10-08',true,'2026-10-08')).toBe(0));
  it('rolls past anniversaries into next year',()=>expect(nextOccurrence('2024-02-14',true,'2026-10-08')).toBe('2027-02-14'));
  it('maps February 29 to February 28 in non-leap years',()=>expect(nextOccurrence('2024-02-29',true,'2025-01-01')).toBe('2025-02-28'));
  it('preserves negative countdowns for one-off past events',()=>expect(countdown('2026-10-07',false,'2026-10-08')).toBe(-1));
});
