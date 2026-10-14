const DAY = 86_400_000;
export function dateOnly(value: string): Date { return new Date(`${value.slice(0, 10)}T00:00:00Z`); }
export function today(): string { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()); }
export function formatDate(value: string, compact = false): string {
  return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: compact ? 'short' : 'long', year: 'numeric', timeZone: 'UTC' }).format(dateOnly(value));
}
export function daysTogether(since: string | null, now = today()): number { return since ? Math.max(0, Math.floor((dateOnly(now).getTime() - dateOnly(since).getTime()) / DAY)) : 0; }
export function nextOccurrence(value: string, recurring: boolean, now = today()): string {
  if (!recurring) return value;
  const reference = dateOnly(now), source = dateOnly(value);
  const occurrence = (year: number) => new Date(Date.UTC(year, source.getUTCMonth(), Math.min(source.getUTCDate(), new Date(Date.UTC(year, source.getUTCMonth() + 1, 0)).getUTCDate())));
  let next = occurrence(reference.getUTCFullYear());
  if (next < reference) next = occurrence(reference.getUTCFullYear() + 1);
  return next.toISOString().slice(0, 10);
}
export function countdown(value: string, recurring = false, now = today()): number { return Math.ceil((dateOnly(nextOccurrence(value, recurring, now)).getTime() - dateOnly(now).getTime()) / DAY); }
