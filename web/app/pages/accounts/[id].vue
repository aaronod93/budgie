<script setup lang="ts">
definePageMeta({ middleware: 'auth', layout: 'app' })

interface Txn {
  uuid: string
  account_uuid: string
  date: string
  amount: number
  memo: string | null
  cleared: 'uncleared' | 'cleared' | 'reconciled'
  approved: boolean
  payee: { uuid: string, name: string } | null
  category: { uuid: string, name: string } | null
  transfer_account_uuid: string | null
  splits: { uuid: string, amount: number, category_uuid: string | null, memo: string | null }[]
}

interface Scheduled {
  uuid: string
  frequency: string
  next_date: string
  amount: number
  memo: string | null
  payee: { uuid: string, name: string } | null
  category: { uuid: string, name: string } | null
  transfer_account_uuid: string | null
}

const route = useRoute()
const store = useBudgetStore()

const transactions = ref<Txn[]>([])
const schedules = ref<Scheduled[]>([])
const loadingRows = ref(false)
const error = ref('')
const editingUuid = ref<string | null>(null)
const showReconcile = ref(false)
const statementBalance = ref('')
const reconcileMessage = ref('')
const search = ref('')
const showImport = ref(false)
let searchTimer: ReturnType<typeof setTimeout> | undefined

const blankForm = () => ({
  date: new Date().toISOString().slice(0, 10),
  payee: '',
  category: 'none' as string,
  memo: '',
  outflow: '',
  inflow: '',
  repeat: 'none' as string,
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
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(load, 300)
})

const unapprovedCount = computed(() => transactions.value.filter(t => !t.approved).length)

async function load() {
  if (!store.current) return
  loadingRows.value = true
  try {
    const query = new URLSearchParams({ account_id: accountUuid.value })
    if (search.value.trim()) query.set('search', search.value.trim())
    const [txns, scheds] = await Promise.all([
      apiFetch<{ data: Txn[] }>(`${store.base}/transactions?${query}`),
      apiFetch<{ data: Scheduled[] }>(`${store.base}/scheduled-transactions?account_id=${accountUuid.value}`),
    ])
    transactions.value = txns.data
    schedules.value = scheds.data
  } finally {
    loadingRows.value = false
  }
}

async function approveAll() {
  await apiFetch(`${store.base}/transactions-approve-all`, {
    method: 'POST',
    body: { account_id: accountUuid.value },
  })
  await load()
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
    } else if (form.repeat !== 'none') {
      // Repeat selected: create a schedule instead of posting immediately.
      body.account_id = accountUuid.value
      body.frequency = form.repeat
      body.next_date = form.date
      delete body.date
      await apiFetch(`${store.base}/scheduled-transactions`, { method: 'POST', body })
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

async function enterScheduled(scheduled: Scheduled) {
  await apiFetch(`${store.base}/scheduled-transactions/${scheduled.uuid}/enter`, { method: 'POST' })
  await Promise.all([load(), store.loadAccounts()])
}

async function removeScheduled(scheduled: Scheduled) {
  if (!confirm('Delete this scheduled transaction?')) return
  await apiFetch(`${store.base}/scheduled-transactions/${scheduled.uuid}`, { method: 'DELETE' })
  await load()
}

async function reconcile() {
  const balance = parseMoney(statementBalance.value)
  if (balance === null) return
  reconcileMessage.value = ''
  try {
    const result = await apiFetch<{ adjustment: { amount: number } | null }>(
      `${store.base}/accounts/${accountUuid.value}/reconcile`,
      { method: 'POST', body: { statement_balance: balance } },
    )
    reconcileMessage.value = result.adjustment
      ? `Reconciled — an adjustment of ${formatMoney(result.adjustment.amount, store.current?.currency)} was created.`
      : 'Reconciled — everything matched.'
    showReconcile.value = false
    statementBalance.value = ''
    await Promise.all([load(), store.loadAccounts()])
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Could not reconcile.'
    showReconcile.value = false
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
        <p class="text-sm text-mist-300 capitalize">{{ account?.type }}{{ account?.on_budget ? '' : ' · off budget' }}</p>
      </div>
      <div class="flex items-center gap-4">
        <div class="text-right">
          <p class="text-lg font-bold" :class="(account?.balance ?? 0) < 0 ? 'text-red-400' : 'text-paper-100'">
            {{ formatMoney(account?.balance ?? 0, store.current?.currency) }}
          </p>
          <p class="text-xs text-mist-500">
            Cleared: {{ formatMoney(account?.cleared_balance ?? 0, store.current?.currency) }}
          </p>
        </div>
        <button
          class="rounded-md border border-ink-600 text-mist-200 px-3 py-1.5 text-sm hover:bg-ink-700"
          @click="showImport = true"
        >
          Import
        </button>
        <button
          class="rounded-md border border-ink-600 text-mist-200 px-3 py-1.5 text-sm hover:bg-ink-700"
          @click="showReconcile = true; statementBalance = centsToInput(account?.cleared_balance ?? 0)"
        >
          Reconcile
        </button>
      </div>
    </header>

    <div class="mb-4 flex flex-wrap items-center gap-3">
      <input
        v-model="search"
        placeholder="Search payee or memo…"
        class="w-64 rounded-md border border-ink-600 bg-ink-700 px-3 py-1.5 text-sm text-paper-100 placeholder:text-mist-500"
      >
      <button
        v-if="unapprovedCount > 0"
        class="rounded-md border border-accent-400/60 bg-accent-400/10 px-3 py-1.5 text-sm font-medium text-accent-300 hover:bg-accent-400/20"
        @click="approveAll"
      >
        Approve {{ unapprovedCount }} imported
      </button>
    </div>

    <p v-if="reconcileMessage" class="mb-4 rounded-md bg-mist-500/15 px-4 py-2 text-sm text-mist-200">
      {{ reconcileMessage }}
    </p>

    <!-- Add / edit form -->
    <form
      class="mb-6 grid grid-cols-2 gap-3 rounded-xl border border-ink-700 bg-paper-200 p-4 text-ink-800 md:grid-cols-8"
      @submit.prevent="submit"
    >
      <input v-model="form.date" type="date" required class="rounded-md border border-paper-400 bg-paper-50 px-2 py-1.5 text-sm">
      <input
        v-model="form.payee"
        placeholder="Payee"
        :disabled="editingTransfer"
        class="rounded-md border border-paper-400 bg-paper-50 px-2 py-1.5 text-sm disabled:bg-paper-300"
      >
      <select
        v-model="form.category"
        :disabled="editingTransfer"
        class="rounded-md border border-paper-400 bg-paper-50 px-2 py-1.5 text-sm disabled:bg-paper-300"
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
      <input v-model="form.memo" placeholder="Memo" class="rounded-md border border-paper-400 bg-paper-50 px-2 py-1.5 text-sm">
      <input v-model="form.outflow" placeholder="Outflow" inputmode="decimal" class="rounded-md border border-paper-400 bg-paper-50 px-2 py-1.5 text-right text-sm">
      <input v-model="form.inflow" placeholder="Inflow" inputmode="decimal" class="rounded-md border border-paper-400 bg-paper-50 px-2 py-1.5 text-right text-sm">
      <select
        v-if="!editingUuid"
        v-model="form.repeat"
        title="Repeat"
        class="rounded-md border border-paper-400 bg-paper-50 px-2 py-1.5 text-sm"
      >
        <option value="none">No repeat</option>
        <option value="once">Once (scheduled)</option>
        <option value="weekly">Weekly</option>
        <option value="fortnightly">Fortnightly</option>
        <option value="monthly">Monthly</option>
        <option value="yearly">Yearly</option>
      </select>
      <div class="flex gap-1">
        <button type="submit" class="flex-1 rounded-md bg-accent-400 px-3 py-1.5 text-sm font-medium text-ink-900 hover:bg-accent-500">
          {{ editingUuid ? 'Save' : 'Add' }}
        </button>
        <button
          v-if="editingUuid"
          type="button"
          class="rounded-md border border-paper-400 bg-paper-50 px-2 py-1.5 text-sm text-ink-600 hover:bg-paper-300"
          @click="cancelEdit"
        >
          ✕
        </button>
      </div>
    </form>

    <p v-if="error" class="mb-4 rounded-md bg-red-500/15 px-4 py-2 text-sm text-red-300">{{ error }}</p>

    <!-- Scheduled transactions -->
    <div v-if="schedules.length" class="mb-6 rounded-xl border border-ink-700 bg-paper-200 text-ink-800">
      <p class="border-b border-paper-300 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-mist-700">
        Scheduled
      </p>
      <table class="w-full text-sm">
        <tbody>
          <tr v-for="scheduled in schedules" :key="scheduled.uuid" class="border-b border-paper-300 last:border-0">
            <td class="w-28 px-3 py-2 text-ink-700">{{ scheduled.next_date }}</td>
            <td class="w-24 px-3 py-2 capitalize text-mist-700">{{ scheduled.frequency }}</td>
            <td class="px-3 py-2">{{ scheduled.payee?.name ?? (scheduled.transfer_account_uuid ? 'Transfer' : '—') }}</td>
            <td class="px-3 py-2 text-ink-700">{{ scheduled.category?.name ?? '' }}</td>
            <td class="w-28 px-3 py-2 text-right font-medium" :class="scheduled.amount < 0 ? 'text-ink-800' : 'text-emerald-700'">
              {{ formatMoney(scheduled.amount, store.current?.currency) }}
            </td>
            <td class="w-32 pr-2 text-right">
              <button
                class="rounded border border-accent-500 px-2 py-0.5 text-xs text-accent-600 hover:bg-accent-100"
                @click="enterScheduled(scheduled)"
              >
                Enter now
              </button>
              <button
                class="ml-1 rounded px-1.5 text-paper-400 hover:bg-red-100 hover:text-red-700"
                title="Delete"
                @click="removeScheduled(scheduled)"
              >✕</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="overflow-x-auto rounded-xl border border-ink-700 bg-paper-200 text-ink-800">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-paper-300 text-left text-xs uppercase tracking-wide text-mist-700">
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
          <tr v-if="loadingRows"><td colspan="7" class="px-4 py-6 text-center text-mist-700">Loading…</td></tr>
          <tr v-else-if="transactions.length === 0"><td colspan="7" class="px-4 py-6 text-center text-mist-700">No transactions yet.</td></tr>
          <tr
            v-for="txn in transactions"
            :key="txn.uuid"
            class="border-b border-paper-300 hover:bg-paper-100"
            :class="{ 'bg-accent-100': editingUuid === txn.uuid }"
          >
            <td class="px-3 py-2">
              <button
                class="h-4 w-4 rounded-full border"
                :class="txn.cleared === 'uncleared' ? 'border-paper-400 bg-paper-50' : 'border-emerald-700 bg-emerald-600'"
                :title="txn.cleared"
                @click="toggleCleared(txn)"
              />
            </td>
            <td class="cursor-pointer px-3 py-2 text-ink-700" @click="startEdit(txn)">{{ txn.date }}</td>
            <td class="cursor-pointer px-3 py-2" @click="startEdit(txn)">
              {{ txn.payee?.name ?? '—' }}
              <span v-if="!txn.approved" class="ml-1 rounded bg-accent-400 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-ink-900">New</span>
            </td>
            <td class="cursor-pointer px-3 py-2 text-ink-700" @click="startEdit(txn)">{{ rowLabel(txn) }}</td>
            <td class="cursor-pointer px-3 py-2 text-mist-700" @click="startEdit(txn)">{{ txn.memo }}</td>
            <td
              class="cursor-pointer px-3 py-2 text-right font-medium"
              :class="txn.amount < 0 ? 'text-ink-800' : 'text-emerald-700'"
              @click="startEdit(txn)"
            >
              {{ formatMoney(txn.amount, store.current?.currency) }}
            </td>
            <td class="pr-2 text-right">
              <span v-if="txn.cleared === 'reconciled'" class="px-1.5 text-paper-400" title="Reconciled (locked)">🔒</span>
              <button v-else class="rounded px-1.5 text-paper-400 hover:bg-red-100 hover:text-red-700" title="Delete" @click="remove(txn)">✕</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <ImportWizard
      v-if="showImport"
      :account-uuid="accountUuid"
      @close="showImport = false"
      @done="load(); store.loadAccounts()"
    />

    <!-- Reconcile modal -->
    <div v-if="showReconcile" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-sm rounded-xl bg-paper-200 p-6 text-ink-800 shadow-xl">
        <h2 class="mb-1 text-lg font-semibold">Reconcile {{ account?.name }}</h2>
        <p class="mb-4 text-sm text-mist-700">
          Cleared balance is {{ formatMoney(account?.cleared_balance ?? 0, store.current?.currency) }}.
          Enter the balance from your bank statement — if it differs, an adjustment
          transaction closes the gap, then all cleared transactions are locked.
        </p>
        <form class="space-y-4" @submit.prevent="reconcile">
          <input
            v-model="statementBalance"
            inputmode="decimal"
            required
            placeholder="Statement balance"
            class="w-full rounded-md border border-paper-400 bg-paper-50 px-3 py-2"
          >
          <div class="flex justify-end gap-2">
            <button type="button" class="rounded-md px-4 py-2 text-ink-600 hover:bg-paper-300" @click="showReconcile = false">Cancel</button>
            <button type="submit" class="rounded-md bg-accent-400 px-4 py-2 font-medium text-ink-900 hover:bg-accent-500">Reconcile</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
