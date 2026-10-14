import { useRef } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useGSAP } from '@gsap/react';
import gsap from 'gsap';
import { ArrowRight, Camera, Film, Heart, Sparkles, CalendarHeart, Quote, Shuffle } from 'lucide-react';
import { useStory } from '../store';
import { PHOTOS } from '../lib/demo';
import { countdown, daysTogether, formatDate, today } from '../lib/dates';
import { Empty, MemoryCard } from '../components/ui';

gsap.registerPlugin(useGSAP);
export function Home({onAdd}:{onAdd:()=>void}) {
  const {data}=useStory(),navigate=useNavigate(),hero=useRef<HTMLDivElement>(null);
  useGSAP(()=>{const mm=gsap.matchMedia();mm.add('(prefers-reduced-motion: no-preference)',()=>{gsap.from('.hero-reveal',{y:24,opacity:0,stagger:0.1,duration:0.8,ease:'power2.out'});gsap.from('.polaroid',{y:35,opacity:0,stagger:0.15,duration:1,ease:'power2.out'});});return()=>mm.revert();},{scope:hero});
  if(!data)return null;
  const sorted=[...data.memories].sort((a,b)=>b.date.localeCompare(a.date));
  const images=data.memories.flatMap(m=>m.media).filter(m=>m.kind==='image');
  const cover=images.find(m=>m.id===data.settings.cover_id)?.url??images[0]?.url;
  const next=data.dates.map(d=>({...d,days:countdown(d.date,d.recurring)})).filter(d=>d.days>=0).sort((a,b)=>a.days-b.days)[0];
  const onThisDay=sorted.filter(m=>m.date.slice(5)===today().slice(5)&&m.date<today());
  return <>
    <section className="home-hero" ref={hero}>
      <div className="hero-copy"><div className="eyebrow hero-reveal"><span className="little-line"/> SEBUAH RUANG UNTUK KITA BERDUA</div><h1 className="hero-reveal">Kita, dan semua<br/><em>cerita</em> di antaranya<span className="title-dot">.</span></h1><p className="hero-description hero-reveal">{data.settings.tagline}<br/>Mari simpan, agar tak ada yang terlupa.</p><div className="hero-actions hero-reveal"><button className="button primary" onClick={onAdd}><Camera size={17}/>Simpan kenangan<ArrowRight size={17}/></button><Link className="text-link" to="/timeline">Jelajahi cerita kita <ArrowUp/></Link></div><div className="hero-signature hero-reveal"><span className="signature-heart"><Heart size={16}/></span><span>{data.settings.names}<small>{data.settings.since?`Sejak ${formatDate(data.settings.since)}`:'Cerita kita dimulai di sini'}</small></span></div></div>
      <div className="hero-collage" aria-label="Galeri cerita kita"><div className="collage-orbit"/><span className="collage-note">little moments,<br/><em>big feelings.</em></span><div className="polaroid polaroid-back"><img src={images[1]?.url??PHOTOS.flowers} alt={images[1]?'Foto kenangan kalian':'Ilustrasi bunga'} /><span>you make ordinary, extraordinary.</span></div><div className="polaroid polaroid-front"><img src={cover??PHOTOS.coast} alt={cover?'Foto sampul kalian':'Ilustrasi perjalanan'} /><span>our favorite place? together.</span></div><div className="collage-stamp"><Heart size={20} strokeWidth={1.2}/><span>JUST<br/>US TWO</span></div><Sparkles className="collage-sparkle" size={35} strokeWidth={1}/><span className="collage-number">01 / OUR LITTLE UNIVERSE</span></div>
    </section>
    <section className="stats-strip"><div><Heart size={22} strokeWidth={1.4}/><strong>{data.settings.since?daysTogether(data.settings.since).toLocaleString('id-ID'):'—'}</strong><span>hari memilih kita</span></div><div><Camera size={22} strokeWidth={1.4}/><strong>{data.memories.length}</strong><span>kenangan tersimpan</span></div><div><CalendarHeart size={22} strokeWidth={1.4}/><strong>{next?next.days:'—'}<small>{next?' hari':''}</small></strong><span>{next?.title??'menuju cerita berikutnya'}</span></div><Link to="/film" className="stats-film"><span className="stats-film-icon"><Film size={23} strokeWidth={1.3}/></span><span><strong>Jadikan sebuah film</strong><small>Kenangan yang bisa diputar kembali</small></span><ArrowRight size={19}/></Link></section>
    <section className="home-memories"><div className="section-heading"><div><span className="eyebrow">HALAMAN-HALAMAN TERBARU</span><h2>Momen kecil, <em>arti besar.</em></h2></div><Link to="/timeline" className="text-link">Semua kenangan <ArrowRight size={16}/></Link></div>{sorted.length?<div className="memory-grid">{sorted.slice(0,3).map((m,i)=><MemoryCard key={m.id} memory={m} index={i}/>)}</div>:<Empty title="Halaman pertama masih menunggu" description="Mulai dari satu foto, satu cerita, atau satu hari yang tak ingin kalian lupa." action={<button className="button primary" onClick={onAdd}>Tambah kenangan pertama</button>}/>}</section>
    <section className="home-bottom"><div className="quote-card"><Quote size={28} strokeWidth={1}/><p>“Dan jika waktu bisa diulang,<br/>aku tetap akan memilih <em>kamu.</em>”</p><span>UNTUK KITA, SELALU.</span><Link to="/surat" className="text-link">Buka surat kita <ArrowRight size={16}/></Link></div><div className="rediscover-card"><span className="eyebrow">SEDIKIT NOSTALGIA</span><h3>{onThisDay.length?'Pada hari ini, dulu…':'Ke mana kenangan membawa kita?'}</h3><p>{onThisDay.length?`${onThisDay.length} cerita terjadi pada tanggal ini. Mari buka kembali.`:'Kadang, momen yang paling ingin kita ingat justru yang tak kita cari.'}</p><button className="button secondary" disabled={!sorted.length} onClick={()=>{const pool=onThisDay.length?onThisDay:sorted;navigate(`/kenangan/${pool[Math.floor(Math.random()*pool.length)].id}`);}}><Shuffle size={17}/>Buka satu kenangan</button><span className="decorative-flower">✳</span></div></section>
  </>;
}
function ArrowUp(){return <ArrowRight size={16} style={{transform:'rotate(-35deg)'}}/>;}
