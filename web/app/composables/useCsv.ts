// Minimal RFC-4180-ish CSV parsing for the import wizard: quoted fields,
// escaped quotes, CRLF/LF endings. Bank exports are simple enough that we
// don't need a dependency.

export function parseCsv(text: string): string[][] {
  const rows: string[][] = []
  let row: string[] = []
  let field = ''
  let inQuotes = false

  for (let i = 0; i < text.length; i++) {
    const char = text[i]!

    if (inQuotes) {
      if (char === '"') {
        if (text[i + 1] === '"') {
          field += '"'
          i++
        } else {
          inQuotes = false
        }
      } else {
        field += char
      }
    } else if (char === '"') {
      inQuotes = true
    } else if (char === ',') {
      row.push(field)
      field = ''
    } else if (char === '\n' || char === '\r') {
      if (char === '\r' && text[i + 1] === '\n') i++
      row.push(field)
      field = ''
      if (row.length > 1 || row[0] !== '') rows.push(row)
      row = []
    } else {
      field += char
    }
  }

  row.push(field)
  if (row.length > 1 || row[0] !== '') rows.push(row)

  return rows
}

export type CsvDateFormat = 'auto' | 'dmy' | 'mdy' | 'ymd'

/** Parse a CSV date cell to YYYY-MM-DD, or null when unparseable. */
export function parseCsvDate(value: string, format: CsvDateFormat): string | null {
  const cleaned = value.trim()

  const iso = cleaned.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})/)
  if (iso && (format === 'auto' || format === 'ymd')) {
    return build(iso[1]!, iso[2]!, iso[3]!)
  }

  const slashed = cleaned.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{2,4})/)
  if (!slashed) return null

  let [, a, b, year] = slashed as unknown as [string, string, string, string]
  if (year.length === 2) year = `20${year}`

  // AU banks are DD/MM/YYYY; that's the auto default.
  const [day, month] = format === 'mdy' ? [b, a] : [a, b]
  return build(year, month!, day!)
}

function build(year: string, month: string, day: string): string | null {
  const m = Number(month)
  const d = Number(day)
  if (m < 1 || m > 12 || d < 1 || d > 31) return null
  return `${year}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`
}
