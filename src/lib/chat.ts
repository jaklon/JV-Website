export type ChatSource = { key: string; title: string; href: string };
export type ChatMessage = { id: string; thread_id: string; user_id: string | null; role: 'user'|'assistant'; content: string; author: string | null; status: 'pending'|'complete'|'failed'; sources: ChatSource[]; created_at: string };
export type ChatThread = { id: string; user_id: string; title: string; author?: string; updated_at: string };
export type ChatStatus = { enabled: boolean; installed: boolean; model: string; reason: string; daily_remaining: number; monthly_remaining: number; shared: boolean };
export const chatSuggestions = ['Kenangan kita bulan lalu apa?', 'Kapan anniversary kita?', 'Aku ingin cerita hari ini.'];
// Never allow model-generated links. Only paths created by the PHP source mapper.
export function safeChatHref(href: string): boolean {
  return /^\/kenangan\/[a-f0-9]{32}$/.test(href) || /^\/timeline\?album=[a-f0-9]{32}$/.test(href)
    || /^\/surat\?letter=[a-f0-9]{32}$/.test(href) || /^\/tanggal#date-[a-f0-9]{32}$/.test(href) || href === '/pengaturan';
}
