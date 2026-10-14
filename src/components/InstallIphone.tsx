import { useState } from 'react';
import { PlusSquare, Share, Smartphone } from 'lucide-react';
import { Modal } from './ui';

export function InstallIphone() {
  const [open,setOpen]=useState(false);
  const installed=window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & {standalone?:boolean}).standalone===true;
  if(installed)return null;
  return <>
    <button type="button" className="install-iphone-button" onClick={()=>setOpen(true)}><Smartphone size={15}/>Pasang di Home Screen iPhone</button>
    {open&&<Modal title="JV Story di iPhone" onClose={()=>setOpen(false)}>
      <div className="install-iphone-guide"><img src={`${import.meta.env.BASE_URL}icons/apple-touch-icon.png`} width="72" height="72" alt="Ikon aplikasi JV Story"/>
        <p>Simpan ruang kita di Home Screen, lalu buka langsung dari ikon JV Story.</p>
        <ol><li>Buka <strong>jascon.my.id/jv-story/</strong> melalui Safari di iPhone.</li>
          <li>Ketuk <strong>Bagikan / Share</strong> <Share size={16}/> di Safari. Pada beberapa tampilan, buka menu halaman terlebih dahulu.</li>
          <li>Pilih <strong>Tambah ke Layar Utama / Add to Home Screen</strong> <PlusSquare size={16}/>.</li>
          <li>Jika tersedia, aktifkan <strong>Buka sebagai App Web / Open as Web App</strong>, lalu ketuk <strong>Tambah / Add</strong>.</li></ol>
        <p className="muted">Setelah terpasang, ketuk ikon JV Story. Koneksi internet tetap diperlukan untuk masuk dan menyimpan kenangan.</p>
        <button type="button" className="button primary" onClick={()=>setOpen(false)}>Mengerti</button>
      </div>
    </Modal>}
  </>;
}
