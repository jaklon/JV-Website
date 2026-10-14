let csrf = '';
export const setCsrf = (value: string) => { csrf = value; };
export async function api<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers);
  if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
  if (options.method && options.method !== 'GET') headers.set('X-CSRF-Token', csrf);
  const response = await fetch(`${import.meta.env.BASE_URL}api${path}`, { ...options, headers, credentials: 'same-origin' });
  const contentType = response.headers.get('content-type') ?? '';
  if (!contentType.includes('application/json')) throw new Error('Backend PHP belum berjalan. Jalankan server PHP sesuai README.');
  const payload = await response.json();
  if (!response.ok) {
    if (response.status === 401) window.dispatchEvent(new Event('session-expired'));
    throw new Error(payload.error || 'Permintaan gagal. Coba lagi.');
  }
  return payload as T;
}
export const jsonBody = (value: unknown) => JSON.stringify(value);
