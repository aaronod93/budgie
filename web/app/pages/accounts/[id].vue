<script setup lang="ts">
definePageMeta({ middleware: 'auth', layout: 'app' })

interface Txn {
  uuid: string
  account_uuid: string
  date: string
  amount: number
  memo: string | null
  cleared: 'uncleared' | 'cleared' | 'reconciled'
  payee: { uuid: string, name: string } | null
  category: { uuid: string, name: string } | null
  transfer_account_uuid: string | null
  splits: { uuid: string, amount: number, category_uuid: string | null, memo: string | null }[]
}

const route = useRoute()
const store = useBudgetStore()

const transactions = ref<Txn[]>([])
const loadingRows = ref(false)
const error = ref('')
const editingUuid = ref<string | null>(null)

const blankForm = () => ({
  date: new Date().toISOString().slice(0, 10),
  payee: '',
  category: 'none' as string,
  memo: '',
  outflow: '',
  inflow: '',
})
const form = reactive(blankForm())

const accountUuid = computed(() => route.params.id as string)
const account = computed(() => store.accounts.find(a => a.uuid === accountUuid.value))
const otherAccounts = computed(() => store.accounts.filter(a => a.uuid !== accountUuid.value && !a.closed))
const editingTransfer = computed(() =>
  editingUuid.value !== null
  && transactions.value.find(t => t.uuid === editingUuid.value)?.transfer_account_uuid != null,
)

watch([() => store.current, accountUuid], load, { immediate: true })

async function load() {
  if (!store.current) return
  loadingRows.value = true
  try {
    transactions.value = (await apiFetch<{ data: Txn[] }>(
      `${store.base}/transactions?account_id=${accountUuid.value}`,
    )).data
  } finally {
    loadingRows.value = false
  }
}

function rowLabel(txn: Txn): string {
  if (txn.splits.length > 0) return `Split (${txn.splits.length} categories)`
  return txn.category?.name ?? (txn.transfer_account_uuid ? 'Transfer' : 'Uncategorised')
}

function startEdit(txn: Txn) {
  editingUuid.value = txn.uuid
  form.date = txn.date
  form.payee = txn.payee?.name ?? ''
  form.memo = txn.memo ?? ''
  form.outflow = txn.amount < 0 ? centsToInput(-txn.amount) : ''
  form.inflow = txn.amount > 0 ? centsToInput(txn.amount) : ''
  if (txn.transfer_account_uuid) form.category = 'none'
  else if (txn.category?.uuid === store.current?.ready_to_assign_category_uuid) form.category = 'rta'
  else form.category = txn.category?.uuid ?? 'none'
}

function cancelEdit() {
  editingUuid.value = null
  Object.assign(form, blankForm())
}

async function submit() {
  error.value = ''
  const outflow = parseMoney(form.outflow) ?? 0
  const inflow = parseMoney(form.inflow) ?? 0
  const amount = inflow - outflow
  if (amount === 0 && outflow === 0) {
    error.value = 'Enter an outflow or inflow amount.'
    return
  }

  const body: Record<string, unknown> = {
    date: form.date,
    amount,
    memo: form.memo || null,
  }

  if (!editingTransfer.value) {
    if (form.category.startsWith('transfer:')) {
      body.transfer_account_id = form.category.slice('transfer:'.length)
    } else if (form.category === 'rta') {
      body.category_id = store.current?.ready_to_assign_category_uuid
    } else {
      body.category_id = form.category === 'none' ? null : form.category
    }
    body.payee_name = form.payee || null
  }

  try {
    if (editingUuid.value) {
      // account_id / transfer_account_id are fixed after creation
      delete body.transfer_account_id
      await apiFetch(`${store.base}/transactions/${editingUuid.value}`, { method: 'PATCH', body })
    } else {
      body.account_id = accountUuid.value
      await apiFetch(`${store.base}/transactions`, { method: 'POST', body })
    }
    cancelEdit()
    await Promise.all([load(), store.loadAccounts()])
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Could not save the transaction.'
  }
}

async function toggleCleared(txn: Txn) {
  if (txn.cleared === 'reconciled') return
  const next = txn.cleared === 'cleared' ? 'uncleared' : 'cleared'
  await apiFetch(`${store.base}/transactions/${txn.uuid}`, { method: 'PATCH', body: { cleared: next } })
  txn.cleared = next
  await store.loadAccounts()
}

async function remove(txn: Txn) {
  if (!confirm('Delete this transaction?')) return
  await apiFetch(`${store.base}/transactions/${txn.uuid}`, { method: 'DELETE' })
  if (editingUuid.value === txn.uuid) cancelEdit()
  await Promise.all([load(), store.loadAccounts()])
}
</script>

<template>
  <div class="mx-auto max-w-5xl p-6">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-xl font-bold">{{ account?.name ?? 'Account' }}</h1>
        <p class="text-sm text-slate-500 capitalize">{{ account?.type }}{{ account?.on_budget ? '' : ' · off budget' }}</p>
      </div>
      <div class="text-right">
        <p class="text-lg font-bold" :class="(account?.balance ?? 0) < 0 ? 'text-red-600' : 'text-emerald-700'">
          {{ formatMoney(account?.balance ?? 0, store.current?.currency) }}
        </p>
        <p class="text-xs text-slate-400">
          Cleared: {{ formatMoney(account?.cleared_balance ?? 0, store.current?.currency) }}
        </p>
      </div>
    </header>

    <!-- Add / edit form -->
    <form
      class="mb-6 grid grid-cols-2 gap-3 rounded-xl border border-slate-200 bg-white p-4 md:grid-cols-7"
      @submit.prevent="submit"
    >
      <input v-model="form.date" type="date" required class="rounded-md border border-slate-300 px-2 py-1.5 text-sm">
      <input
        v-model="form.payee"
        placeholder="Payee"
        :disabled="editingTransfer"
        class="rounded-md border border-slate-300 px-2 py-1.5 text-sm disabled:bg-slate-100"
      >
      <select
        v-model="form.category"
        :disabled="editingTransfer"
        class="rounded-md border border-slate-300 px-2 py-1.5 text-sm disabled:bg-slate-100"
      >
        <option value="none">No category</option>
        <option value="rta">Inflow: Ready to Assign</option>
        <optgroup v-for="group in store.groups" :key="group.uuid" :label="group.name">
          <option v-for="category in group.categories" :key="category.uuid" :value="category.uuid">
            {{ category.name }}
          </option>
        </optgroup>
        <optgroup v-if="!editingUuid && otherAccounts.length" label="Transfer to…">
          <option v-for="acc in otherAccounts" :key="acc.uuid" :value="`transfer:${acc.uuid}`">
            Transfer : {{ acc.name }}
          </option>
        </optgroup>
      </select>
      <input v-model="form.memo" placeholder="Memo" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm">
      <input v-model="form.outflow" placeholder="Outflow" inputmode="decimal" class="rounded-md border border-slate-300 px-2 py-1.5 text-right text-sm">
      <input v-model="form.inflow" placeholder="Inflow" inputmode="decimal" class="rounded-md border border-slate-300 px-2 py-1.5 text-right text-sm">
      <div class="flex gap-1">
        <button type="submit" class="flex-1 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">
          {{ editingUuid ? 'Save' : 'Add' }}
        </button>
        <button
          v-if="editingUuid"
          type="button"
          class="rounded-md border border-slate-300 px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-100"
          @click="cancelEdit"
        >
          ✕
        </button>
      </div>
    </form>

    <p v-if="error" class="mb-4 rounded-md bg-red-50 px-4 py-2 text-sm text-red-700">{{ error }}</p>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400">
            <th class="w-8 px-3 py-3" title="Cleared">C</th>
            <th class="w-28 px-3 py-3">Date</th>
            <th class="px-3 py-3">Payee</th>
            <th class="px-3 py-3">Category</th>
            <th class="px-3 py-3">Memo</th>
            <th class="w-28 px-3 py-3 text-right">Amount</th>
            <th class="w-10" />
          </tr>
        </thead>
        <tbody>
          <tr v-if="loadingRows"><td colspan="7" class="px-4 py-6 text-center text-slate-400">Loading…</td></tr>
          <tr v-else-if="transactions.length === 0"><td colspan="7" class="px-4 py-6 text-center text-slate-400">No transactions yet.</td></tr>
          <tr
            v-for="txn in transactions"
            :key="txn.uuid"
            class="border-b border-slate-100 hover:bg-slate-50"
            :class="{ 'bg-emerald-50': editingUuid === txn.uuid }"
          >
            <td class="px-3 py-2">
              <button
                class="h-4 w-4 rounded-full border"
                :class="txn.cleared === 'uncleared' ? 'border-slate-300 bg-white' : 'border-emerald-600 bg-emerald-500'"
                :title="txn.cleared"
                @click="toggleCleared(txn)"
              />
            </td>
            <td class="cursor-pointer px-3 py-2 text-slate-600" @click="startEdit(txn)">{{ txn.date }}</td>
            <td class="cursor-pointer px-3 py-2" @click="startEdit(txn)">{{ txn.payee?.name ?? '—' }}</td>
            <td class="cursor-pointer px-3 py-2 text-slate-600" @click="startEdit(txn)">{{ rowLabel(txn) }}</td>
            <td class="cursor-pointer px-3 py-2 text-slate-400" @click="startEdit(txn)">{{ txn.memo }}</td>
            <td
              class="cursor-pointer px-3 py-2 text-right font-medium"
              :class="txn.amount < 0 ? 'text-slate-800' : 'text-emerald-700'"
              @click="startEdit(txn)"
            >
              {{ formatMoney(txn.amount, store.current?.currency) }}
            </td>
            <td class="pr-2 text-right">
              <button class="rounded px-1.5 text-slate-300 hover:bg-red-50 hover:text-red-600" title="Delete" @click="remove(txn)">✕</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
