// All amounts cross the API as integer minor units (cents). These helpers are
// the only place cents <-> display conversion happens.

export function formatMoney(cents: number, currency = 'AUD'): string {
  return new Intl.NumberFormat('en-AU', { style: 'currency', currency }).format(cents / 100)
}

/** Parse user input like "1,234.50" or "-12" into cents; null if not a number. */
export function parseMoney(input: string): number | null {
  const cleaned = input.replace(/[^0-9.-]/g, '')
  if (cleaned === '' || cleaned === '-' || cleaned === '.') return null
  const value = Number(cleaned)
  return Number.isFinite(value) ? Math.round(value * 100) : null
}

/** Cents to a plain editable string ("123.45"), empty for zero. */
export function centsToInput(cents: number): string {
  return cents === 0 ? '' : (cents / 100).toFixed(2)
}
