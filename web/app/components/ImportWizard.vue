<script setup lang="ts">
import type { CsvDateFormat } from '~/composables/useCsv'

const props = defineProps<{ accountUuid: string }>()
const emit = defineEmits<{ close: [], done: [] }>()

const store = useBudgetStore()

const rows = ref<string[][]>([])
const fileName = ref('')
const hasHeader = ref(true)
const dateFormat = ref<CsvDateFormat>('auto')
const amountMode = ref<'single' | 'split'>('single')
const dateCol = ref(0)
const payeeCol = ref<number>(-1)
const memoCol = ref<number>(-1)
const amountCol = ref(1)
const outflowCol = ref(1)
const inflowCol = ref(2)
const busy = ref(false)
const error = ref('')
const result = ref<{ imported: number, skipped: number } | null>(null)

const columns = computed(() => rows.value[0]?.map((cell, i) =>
  hasHeader.value ? (cell || `Column ${i + 1}`) : `Column ${i + 1}`) ?? [])
const dataRows = computed(() => hasHeader.value ? rows.value.slice(1) : rows.value)
const preview = computed(() => normalized.value.slice(0, 5))

const normalized = computed(() => {
  const out: { date: string, amount: number, payee_name: string | null, memo: string | null }[] = []
  for (const row of dataRows.value) {
    const date = parseCsvDate(row[dateCol.value] ?? '', dateFormat.value)
    const amount = amountMode.value === 'single'
      ? parseMoney(row[amountCol.value] ?? '')
      : (parseMoney(row[inflowCol.value] ?? '') ?? 0) - Math.abs(parseMoney(row[outflowCol.value] ?? '') ?? 0)
    if (!date || amount === null || amount === 0) continue
    out.push({
      date,
      amount,
      payee_name: payeeCol.value >= 0 ? (row[payeeCol.value]?.trim() || null) : null,
      memo: memoCol.value >= 0 ? (row[memoCol.value]?.trim() || null) : null,
    })
  }
  return out
})

async function onFile(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  fileName.value = file.name
  rows.value = parseCsv(await file.text())
  guessColumns()
}

function guessColumns() {
  const header = (rows.value[0] ?? []).map(h => h.toLowerCase())
  const find = (...terms: string[]) => header.findIndex(h => terms.some(t => h.includes(t)))
  const date = find('date')
  const payee = find('payee', 'description', 'narrative', 'merchant')
  const memo = find('memo', 'note', 'reference')
  const amount = find('amount')
  const debit = find('debit', 'withdrawal', 'outflow')
  const credit = find('credit', 'deposit', 'inflow')

  if (date >= 0) dateCol.value = date
  if (payee >= 0) payeeCol.value = payee
  if (memo >= 0) memoCol.value = memo
  if (debit >= 0 && credit >= 0) {
    amountMode.value = 'split'
    outflowCol.value = debit
    inflowCol.value = credit
  } else if (amount >= 0) {
    amountMode.value = 'single'
    amountCol.value = amount
  }
}

async function submit() {
  if (normalized.value.length === 0) {
    error.value = 'No importable rows with the current mapping.'
    return
  }
  busy.value = true
  error.value = ''
  try {
    result.value = await apiFetch<{ imported: number, skipped: number }>(
      `${store.base}/transactions-import`,
      {
        method: 'POST',
        body: { account_id: props.accountUuid, transactions: normalized.value.slice(0, 1000) },
      },
    )
    emit('done')
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Import failed.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
    <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
      <h2 class="mb-1 text-lg font-semibold">Import transactions</h2>
      <p class="mb-4 text-sm text-slate-500">
        Upload a CSV from your bank, map the columns, and Lil' Budgie will skip anything it has seen before.
      </p>

      <div v-if="result" class="space-y-4">
        <p class="rounded-md bg-emerald-50 px-4 py-3 text-emerald-800">
          Imported {{ result.imported }} transaction(s); skipped {{ result.skipped }} duplicate(s).
          New rows are marked <strong>New</strong> until you approve them.
        </p>
        <div class="flex justify-end">
          <button class="rounded-md bg-emerald-600 px-4 py-2 font-medium text-white hover:bg-emerald-700" @click="emit('close')">Done</button>
        </div>
      </div>

      <template v-else>
        <input type="file" accept=".csv,text/csv" class="mb-4 text-sm" @change="onFile">

        <template v-if="rows.length">
          <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="hasHeader" type="checkbox"> First row is a header
            </label>
            <label class="text-sm">Date format
              <select v-model="dateFormat" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1">
                <option value="auto">Auto (AU: DD/MM/YYYY)</option>
                <option value="dmy">DD/MM/YYYY</option>
                <option value="mdy">MM/DD/YYYY</option>
                <option value="ymd">YYYY-MM-DD</option>
              </select>
            </label>
            <label class="text-sm">Amount layout
              <select v-model="amountMode" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1">
                <option value="single">One signed column</option>
                <option value="split">Debit + credit columns</option>
              </select>
            </label>
            <label class="text-sm">Date column
              <select v-model.number="dateCol" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1">
                <option v-for="(name, i) in columns" :key="i" :value="i">{{ name }}</option>
              </select>
            </label>
            <label class="text-sm">Payee column
              <select v-model.number="payeeCol" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1">
                <option :value="-1">—</option>
                <option v-for="(name, i) in columns" :key="i" :value="i">{{ name }}</option>
              </select>
            </label>
            <label class="text-sm">Memo column
              <select v-model.number="memoCol" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1">
                <option :value="-1">—</option>
                <option v-for="(name, i) in columns" :key="i" :value="i">{{ name }}</option>
              </select>
            </label>
            <label v-if="amountMode === 'single'" class="text-sm">Amount column
              <select v-model.number="amountCol" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1">
                <option v-for="(name, i) in columns" :key="i" :value="i">{{ name }}</option>
              </select>
            </label>
            <template v-else>
              <label class="text-sm">Outflow column
                <select v-model.number="outflowCol" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1">
                  <option v-for="(name, i) in columns" :key="i" :value="i">{{ name }}</option>
                </select>
              </label>
              <label class="text-sm">Inflow column
                <select v-model.number="inflowCol" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1">
                  <option v-for="(name, i) in columns" :key="i" :value="i">{{ name }}</option>
                </select>
              </label>
            </template>
          </div>

          <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
            Preview — {{ normalized.length }} importable row(s) from {{ fileName }}
          </p>
          <div class="mb-4 overflow-x-auto rounded-lg border border-slate-200">
            <table class="w-full text-sm">
              <tbody>
                <tr v-for="(row, i) in preview" :key="i" class="border-b border-slate-100 last:border-0">
                  <td class="px-3 py-1.5 text-slate-600">{{ row.date }}</td>
                  <td class="px-3 py-1.5">{{ row.payee_name ?? '—' }}</td>
                  <td class="px-3 py-1.5 text-slate-400">{{ row.memo }}</td>
                  <td class="px-3 py-1.5 text-right font-medium" :class="row.amount < 0 ? '' : 'text-emerald-700'">
                    {{ formatMoney(row.amount, store.current?.currency) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <p v-if="error" class="mb-3 text-sm text-red-600">{{ error }}</p>

        <div class="flex justify-end gap-2">
          <button class="rounded-md px-4 py-2 text-slate-600 hover:bg-slate-100" @click="emit('close')">Cancel</button>
          <button
            :disabled="busy || normalized.length === 0"
            class="rounded-md bg-emerald-600 px-4 py-2 font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
            @click="submit"
          >
            {{ busy ? 'Importing…' : `Import ${normalized.length} row(s)` }}
          </button>
        </div>
      </template>
    </div>
  </div>
</template>
