import type { StoryData, User } from '../types';
export const DEMO_USER: User = { id: 'demo-jascon', name: 'Jascon', email: 'jascon@example.test' };
export const PHOTOS = {
  coast: 'https://images.unsplash.com/photo-1470770841072-f978cf4d019e?auto=format&fit=crop&w=1200&q=85',
  couple: 'https://images.unsplash.com/photo-1516589178581-6cd7833ae3b2?auto=format&fit=crop&w=1000&q=85',
  flowers: 'https://images.unsplash.com/photo-1490750967868-88aa4486c946?auto=format&fit=crop&w=900&q=85',
  sunset: 'https://images.unsplash.com/photo-1470252649378-9c29740c9fa8?auto=format&fit=crop&w=1000&q=85',
};
export function demoData(): StoryData {
  const media = (id: string, memory: string, url: string) => ({ id, memory_id: memory, user_id: DEMO_USER.id, kind: 'image' as const, url, name: 'Foto contoh.jpg', size: 180000 });
  return {
    users: [DEMO_USER, { id: 'demo-vania', name: 'Vania', email: 'vania@example.test' }],
    settings: { names: 'Jascon & Vania', since: '2024-02-14', tagline: 'Bukan tentang ke mana kita pergi, tapi dengan siapa kita berbagi.', cover_id: 'photo-1' },
    albums: [{ id: 'album-1', user_id: DEMO_USER.id, title: 'Petualangan kecil', description: 'Jalan-jalan, tersesat sedikit, dan pulang dengan cerita.' }, { id: 'album-2', user_id: DEMO_USER.id, title: 'Hal-hal sederhana', description: 'Momen biasa yang jadi istimewa karena kamu.' }],
    memories: [
      { id: 'memory-1', user_id: DEMO_USER.id, title: 'Di antara langit dan kita', date: '2026-09-21', location: 'Danau & pegunungan', story: 'Kita duduk lama, tanpa perlu banyak bicara. Ada tempat-tempat yang indah karena pemandangannya. Ada yang indah karena kamu ada di sana.', album_id: 'album-1', media: [media('photo-1','memory-1',PHOTOS.coast)], created_at: '2026-09-22 12:00:00' },
      { id: 'memory-2', user_id: 'demo-vania', title: 'Satu sore, seribu cerita', date: '2026-08-16', location: 'Tempat favorit kita', story: 'Masih ingat hari ini? Semua rencana berubah, tapi justru jadi salah satu hari favoritku. Terima kasih sudah membuat hal sederhana terasa begitu berarti.', album_id: 'album-2', media: [media('photo-2','memory-2',PHOTOS.couple)], created_at: '2026-08-16 12:00:00' },
      { id: 'memory-3', user_id: DEMO_USER.id, title: 'Bunga untuk hari biasa', date: '2026-07-04', location: 'Rumah', story: 'Tidak ada perayaan apa-apa. Hanya ingin mengingatkan bahwa kamu selalu layak dirayakan.', album_id: 'album-2', media: [media('photo-3','memory-3',PHOTOS.flowers)], created_at: '2026-07-04 12:00:00' },
      { id: 'memory-4', user_id: DEMO_USER.id, title: 'Mengejar senja bersama', date: '2026-06-08', location: 'Perjalanan pulang', story: 'Kita berhenti sebentar untuk melihat langit. Semoga selalu ada waktu untuk hal-hal kecil seperti ini.', album_id: 'album-1', media: [media('photo-4','memory-4',PHOTOS.sunset)], created_at: '2026-06-08 12:00:00' },
    ],
    letters: [{ id: 'letter-1', user_id: 'demo-vania', title: 'Untuk kamu, rumahku', body: 'Kalau suatu hari kita lupa betapa banyak yang sudah kita lalui, semoga halaman ini membantu kita mengingat.\n\nTerima kasih sudah hadir, sudah mendengarkan, dan sudah memilih kita setiap hari.\n\nDengan sayang,\nVania', status: 'sent', sent_at: '2026-09-20 12:00:00', created_at: '2026-09-20 12:00:00' }],
    dates: [{ id: 'date-1', user_id: DEMO_USER.id, title: 'Anniversary kita', date: '2024-02-14', recurring: true }], projects: [], audio: [],
  };
}
