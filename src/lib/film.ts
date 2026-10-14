import type { FilmItem, FilmRecipe, Media, Memory } from '../types';
export const FILM_LIMITS = { items: 30, bytes: 100 * 1024 * 1024, seconds: 180 };
export function segmentDuration(item: FilmItem, media: Media): number { return media.kind === 'image' ? item.duration : item.end - item.start; }
export function filmDuration(recipe: FilmRecipe, media: Media[]): number {
  const byId = new Map(media.map(m => [m.id, m]));
  return recipe.items.reduce((sum, item) => sum + (byId.has(item.mediaId) ? segmentDuration(item, byId.get(item.mediaId)!) : 0), 0) - (recipe.transition === 'fade' ? Math.max(0, recipe.items.length - 1) * 0.5 : 0);
}
export function validateFilm(recipe: FilmRecipe, media: Media[]): string[] {
  const errors: string[] = [], byId = new Map(media.map(m => [m.id, m]));
  if (!recipe.items.length) errors.push('Pilih minimal satu foto atau video.');
  if (recipe.items.length > FILM_LIMITS.items) errors.push('Maksimal 30 adegan per film.');
  if (recipe.orientation !== 'landscape' && recipe.orientation !== 'portrait') errors.push('Orientasi film tidak valid.');
  if (recipe.transition !== 'cut' && recipe.transition !== 'fade') errors.push('Transisi film tidak valid.');
  const selected = new Set(recipe.items.map(i => i.mediaId));
  if (recipe.musicId) selected.add(recipe.musicId);
  for (const item of recipe.items) {
    const source = byId.get(item.mediaId);
    if (!source || source.kind === 'audio') { errors.push('Media sumber tidak tersedia. Hapus atau ganti adegannya.'); continue; }
    if (source.kind === 'image' && (!Number.isFinite(item.duration) || item.duration < 1 || item.duration > 15)) errors.push('Durasi foto harus 1–15 detik.');
    if (source.kind === 'video' && (!Number.isFinite(item.start) || !Number.isFinite(item.end) || item.start < 0 || item.end - item.start < 1 || !source.duration || item.end > source.duration + 0.1)) errors.push('Potongan video minimal 1 detik dan harus berada dalam durasi video.');
  }
  if (recipe.musicId && byId.get(recipe.musicId)?.kind !== 'audio') errors.push('Musik tidak tersedia. Pilih kembali.');
  const total = [...selected].reduce((sum, id) => sum + (byId.get(id)?.size ?? 0), 0);
  if (total > FILM_LIMITS.bytes) errors.push('Total file sumber maksimal 100 MB.');
  if (filmDuration(recipe, media) > FILM_LIMITS.seconds + 0.01) errors.push('Film maksimal 3 menit. Kurangi adegan atau potong video.');
  return [...new Set(errors)];
}
export function automaticRecipe(memories: Memory[], albumId: string): FilmRecipe {
  const sorted = memories.filter(m => m.album_id === albumId).sort((a, b) => a.date.localeCompare(b.date) || a.created_at.localeCompare(b.created_at) || a.id.localeCompare(b.id));
  return { version: 1, orientation: 'landscape', transition: 'fade', musicId: null, items: sorted.flatMap(m => m.media.filter(x => x.kind !== 'audio').map(x => ({ mediaId: x.id, duration: 4, start: 0, end: x.duration ?? 0 }))) };
}
export function durationLabel(seconds: number): string { const value = Math.max(0, Math.round(seconds)); return `${Math.floor(value / 60)}:${String(value % 60).padStart(2, '0')}`; }
