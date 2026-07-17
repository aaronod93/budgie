// Per-user date display preference. The API always speaks yyyy-MM-dd; this
// only affects what the user sees. Persisted locally, default dd/MM/yyyy.
const KEY = 'budgie:date-format'

const dateDisplayFormat = ref('dd/MM/yyyy')

if (import.meta.client) {
  const saved = localStorage.getItem(KEY)
  if (saved) dateDisplayFormat.value = saved
  watch(dateDisplayFormat, value => localStorage.setItem(KEY, value))
}

export function useDateDisplayFormat() {
  return dateDisplayFormat
}

/** Render an ISO yyyy-MM-dd string using the user's display format. */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return ''
  const [y, m, d] = iso.split('-')
  if (!y || !m || !d) return iso
  return dateDisplayFormat.value
    .replace('dd', d)
    .replace('MM', m)
    .replace('yyyy', y)
}
