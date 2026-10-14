export async function mediaDuration(file: File): Promise<number> {
  if (file.type.startsWith('image/')) return 0;
  return new Promise((resolve, reject) => {
    const element = document.createElement(file.type.startsWith('audio/') ? 'audio' : 'video');
    const url = URL.createObjectURL(file);
    const timer = setTimeout(() => finish(new Error('Media terlalu lama dibaca atau tidak didukung.')), 15000);
    const finish = (error?: Error) => { clearTimeout(timer); URL.revokeObjectURL(url); element.removeAttribute('src'); element.load(); error ? reject(error) : resolve(element.duration); };
    element.preload = 'metadata';
    element.onloadedmetadata = () => { const duration = element.duration; if (!Number.isFinite(duration) || duration < 1 || duration > 86400) finish(new Error('Durasi media tidak valid.')); else { clearTimeout(timer); URL.revokeObjectURL(url); element.removeAttribute('src'); element.load(); resolve(duration); } };
    element.onerror = () => finish(new Error('Media tidak bisa dibaca. Gunakan MP4 H.264 atau MP3.'));
    element.src = url;
  });
}
export function validateUpload(file: File): void {
  const limits: Record<string,number> = { 'image/jpeg':10,'image/png':10,'image/webp':10,'video/mp4':50,'audio/mpeg':10 };
  const limit = limits[file.type];
  if (!limit) throw new Error('Gunakan JPEG, PNG, WebP, MP4, atau MP3.');
  if (!file.size || file.size > limit * 1024 * 1024) throw new Error(`${file.name}: ukuran maksimal ${limit} MB.`);
}
