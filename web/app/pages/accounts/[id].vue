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
  payee: { uuid: string, name: string, icon: string | null } | null
  category: { uuid: string, name: string, icon: string | null } | null
  transfer_account_uuid: string | null
  splits: { uuid: string, amount: number, category_uuid: string | null, memo: string | null }[]
}

interface Scheduled {
  uuid: string
  frequency: string
  next_date: string
  amount: number
  memo: string | null
  payee: { uuid: string, name: string, icon: string | null } | null
  category: { uuid: string, name: string, icon: string | null } | null
  transfer_account_uuid: string | null
}

interface PayeeOption {
  uuid: string
  name: string
  icon: string | null
  transfer_account_uuid: string | null
  default_category: { uuid: string, name: string } | null
  last_category_uuid: string | null
  last_flow: 'outflow' | 'inflow' | null
}

const route = useRoute()
const store = useBudgetStore()

const transactions = ref<Txn[]>([])
const schedules = ref<Scheduled[]>([])
const payees = ref<PayeeOption[]>([])
const outflowInput = ref<HTMLInputElement | null>(null)
const inflowInput = ref<HTMLInputElement | null>(null)
const loadingRows = ref(false)
const error = ref('')
const editingUuid = ref<string | null>(null)
const showReconcile = ref(false)
const statementBalance = ref('')
const reconcileMessage = ref('')
const search = ref('')
const showImport = ref(false)
const showDeleteAccount = ref(false)
const deleteConfirmText = ref('')
const deleteBusy = ref(false)
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
    const [txns, scheds, payeeList] = await Promise.all([
      apiFetch<{ data: Txn[] }>(`${store.base}/transactions?${query}`),
      apiFetch<{ data: Scheduled[] }>(`${store.base}/scheduled-transactions?account_id=${accountUuid.value}`),
      apiFetch<{ data: PayeeOption[] }>(`${store.base}/payees`),
      store.month ? Promise.resolve() : store.loadMonth().catch(() => {}),
    ])
    transactions.value = txns.data
    schedules.value = scheds.data
    payees.value = payeeList.data
  } finally {
    loadingRows.value = false
  }
}

// --- Payee memory: pick a payee, get its last category + usual flow ---

const savedPayees = computed(() => payees.value.filter(p => !p.transfer_account_uuid))

const payeeOptions = computed(() => savedPayees.value.map(p => ({
  value: p.uuid,
  label: `${p.icon ? p.icon + ' ' : ''}${p.name}`,
})))

/** Options carry payee UUIDs; free-typed new payee names arrive as-is. */
function onPayeeChange(value: string) {
  const match = savedPayees.value.find(p => p.uuid === value)
  if (!match) {
    form.payee = value
    return
  }
  selectPayee(match)
}

function selectPayee(payee: PayeeOption) {
  form.payee = payee.name
  if (editingUuid.value) return

  // Pre-select the last-used category (falling back to the payee default).
  const categoryUuid = payee.last_category_uuid ?? payee.default_category?.uuid ?? null
  if (categoryUuid) {
    if (categoryUuid === store.current?.ready_to_assign_category_uuid) {
      form.category = 'rta'
    } else if (store.groups.some(g => g.categories.some(c => c.uuid === categoryUuid))) {
      form.category = categoryUuid
    }
  }

  // Pre-focus the side this payee usually is (outflow vs inflow).
  nextTick(() => {
    (payee.last_flow === 'inflow' ? inflowInput.value : outflowInput.value)?.focus()
  })
}

// Available balance shown beside each category in the picker.
const availableByUuid = computed(() => {
  const map = new Map<string, number>()
  for (const group of store.month?.groups ?? []) {
    for (const category of group.categories) map.set(category.uuid, category.available)
  }
  return map
})

function categoryLabel(name: string, uuid: string): string {
  const available = availableByUuid.value.get(uuid)
  return available === undefined
    ? name
    : `${name}  (${formatMoney(available, store.current?.currency)})`
}

/** Flat, searchable option list: special entries, then icon + group + category
 *  + available balance, then transfers (values contain no spaces). */
const categoryOptions = computed(() => {
  const options: { value: string, label: string }[] = [
    { value: 'none', label: 'No category' },
    { value: 'rta', label: 'Inflow: Ready to Assign' },
  ]
  for (const group of store.groups) {
    for (const category of group.categories.filter(c => !c.hidden)) {
      const icon = category.icon ? `${category.icon} ` : ''
      options.push({
        value: category.uuid,
        label: `${icon}${group.name} · ${categoryLabel(category.name, category.uuid)}`,
      })
    }
  }
  if (!editingUuid.value) {
    for (const acc of otherAccounts.value) {
      options.push({ value: `transfer:${acc.uuid}`, label: `Transfer : ${acc.name}` })
    }
  }
  return options
})

async function approveAll() {
  await apiFetch(`${store.base}/transactions-approve-all`, {
    method: 'POST',
    body: { account_id: accountUuid.value },
  })
  await load()
}

function rowLabel(txn: Txn): string {
  if (txn.splits.length > 0) return `Split (${txn.splits.length} categories)`
  if (txn.category) return (txn.category.icon ? txn.category.icon + ' ' : '') + txn.category.name
  return txn.transfer_account_uuid ? 'Transfer' : 'Uncategorised'
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

async function closeAccount() {
  if ((account.value?.balance ?? 0) !== 0) {
    error.value = 'Transfer the remaining balance out before closing this account.'
    return
  }
  if (!confirm(`Close ${account.value?.name}? Its history stays; you can reopen it any time.`)) return
  error.value = ''
  try {
    await apiFetch(`${store.base}/accounts/${accountUuid.value}`, { method: 'PATCH', body: { closed: true } })
    await store.loadAccounts()
    await navigateTo('/budget')
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Could not close the account.'
  }
}

async function reopenAccount() {
  await apiFetch(`${store.base}/accounts/${accountUuid.value}`, { method: 'PATCH', body: { closed: false } })
  await store.loadAccounts()
}

async function deleteAccount() {
  if (deleteConfirmText.value !== 'CONFIRMDELETE') return
  deleteBusy.value = true
  error.value = ''
  try {
    await apiFetch(`${store.base}/accounts/${accountUuid.value}`, { method: 'DELETE' })
    await Promise.all([store.loadAccounts(), store.loadMonth()])
    await navigateTo('/budget')
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Could not delete the account.'
    showDeleteAccount.value = false
  } finally {
    deleteBusy.value = false
  }
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
        <h1 class="text-xl font-bold">
          {{ account?.name ?? 'Account' }}
          <span v-if="account?.closed" class="ml-2 bg-paper-300 px-2 py-0.5 align-middle text-xs font-medium text-mist-700">Closed</span>
        </h1>
        <p class="text-sm text-mist-700 capitalize">{{ account?.type }}{{ account?.on_budget ? '' : ' · off budget' }}</p>
      </div>
      <div class="flex items-center gap-4">
        <div class="flex items-center gap-3 text-right">
          <div>
            <p class="font-semibold text-ink-700">{{ formatMoney(account?.cleared_balance ?? 0, store.current?.currency) }}</p>
            <p class="text-xs text-mist-700">Cleared</p>
          </div>
          <span class="text-mist-700">+</span>
          <div>
            <p class="font-semibold text-ink-700">
              {{ formatMoney((account?.balance ?? 0) - (account?.cleared_balance ?? 0), store.current?.currency) }}
            </p>
            <p class="text-xs text-mist-700">Uncleared</p>
          </div>
          <span class="text-mist-700">=</span>
          <div>
            <p class="text-lg font-bold" :class="(account?.balance ?? 0) < 0 ? 'text-red-600' : 'text-ink-800'">
              {{ formatMoney(account?.balance ?? 0, store.current?.currency) }}
            </p>
            <p class="text-xs text-mist-700">Working balance</p>
          </div>
        </div>
        <button
          class=" border border-paper-400 text-ink-700 px-3 py-1.5 text-sm hover:bg-paper-100"
          @click="showImport = true"
        >
          Import
        </button>
        <button
          class=" border border-paper-400 text-ink-700 px-3 py-1.5 text-sm hover:bg-paper-100"
          @click="showReconcile = true; statementBalance = centsToInput(account?.cleared_balance ?? 0)"
        >
          Reconcile
        </button>
        <button
          v-if="account?.closed"
          class=" border border-accent-400 bg-accent-100 px-3 py-1.5 text-sm text-accent-600 hover:bg-accent-200"
          @click="reopenAccount"
        >
          Reopen account
        </button>
        <button
          v-else
          class=" border border-paper-400 px-3 py-1.5 text-sm text-ink-700 hover:bg-paper-100"
          @click="closeAccount"
        >
          Close account
        </button>
        <button
          class=" border border-red-300 px-3 py-1.5 text-sm text-red-600 hover:bg-red-100"
          @click="showDeleteAccount = true; deleteConfirmText = ''"
        >
          Delete account
        </button>
      </div>
    </header>

    <div class="mb-4 flex flex-wrap items-center gap-3">
      <input
        v-model="search"
        placeholder="Search payee or memo…"
        class="w-64 border border-paper-400 bg-white px-3 py-1.5 text-sm text-ink-800 placeholder:text-mist-700"
      >
      <button
        v-if="unapprovedCount > 0"
        class=" border border-accent-400 bg-accent-100 px-3 py-1.5 text-sm font-medium text-accent-600 hover:bg-accent-200"
        @click="approveAll"
      >
        Approve {{ unapprovedCount }} imported
      </button>
    </div>

    <p v-if="reconcileMessage" class="mb-4 bg-mist-200 px-4 py-2 text-sm text-ink-700">
      {{ reconcileMessage }}
    </p>

    <!-- Add / edit form (hidden while the account is closed) -->
    <form
      v-if="!account?.closed"
      class="mb-6 grid grid-cols-2 gap-3 border border-paper-300 bg-paper-200 p-4 text-ink-800 md:grid-cols-8"
      @submit.prevent="submit"
    >
      <UiDateField v-model="form.date" size="sm" required />
      <UiCombobox
        size="sm"
        placeholder="Payee"
        allow-custom
        :disabled="editingTransfer"
        :model-value="form.payee"
        :options="payeeOptions"
        @update:model-value="onPayeeChange"
      />
      <UiCombobox
        size="sm"
        placeholder="Category"
        :disabled="editingTransfer"
        :model-value="form.category"
        :options="categoryOptions"
        @update:model-value="form.category = $event || 'none'"
      />
      <input v-model="form.memo" placeholder="Memo" class=" border border-paper-400 bg-paper-50 px-2 py-1.5 text-sm">
      <input ref="outflowInput" v-model="form.outflow" placeholder="Outflow" inputmode="decimal" class=" border border-paper-400 bg-paper-50 px-2 py-1.5 text-right text-sm">
      <input ref="inflowInput" v-model="form.inflow" placeholder="Inflow" inputmode="decimal" class=" border border-paper-400 bg-paper-50 px-2 py-1.5 text-right text-sm">
      <UiSelect v-if="!editingUuid" v-model="form.repeat" size="sm" title="Repeat">
        <option value="none">No repeat</option>
        <option value="once">Once (scheduled)</option>
        <option value="weekly">Weekly</option>
        <option value="fortnightly">Fortnightly</option>
        <option value="monthly">Monthly</option>
        <option value="yearly">Yearly</option>
      </UiSelect>
      <div class="flex gap-1">
        <button type="submit" class="flex-1 bg-accent-400 px-3 py-1.5 text-sm font-medium text-ink-900 hover:bg-accent-500">
          {{ editingUuid ? 'Save' : 'Add' }}
        </button>
        <button
          v-if="editingUuid"
          type="button"
          class=" border border-paper-400 bg-paper-50 px-2 py-1.5 text-sm text-ink-600 hover:bg-paper-300"
          @click="cancelEdit"
        >
          ✕
        </button>
      </div>
    </form>

    <p v-if="error" class="mb-4 bg-red-100 px-4 py-2 text-sm text-red-700">{{ error }}</p>

    <!-- Scheduled transactions -->
    <div v-if="schedules.length" class="mb-6 border border-paper-300 bg-paper-200 text-ink-800">
      <p class="border-b border-paper-300 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-mist-700">
        Scheduled
      </p>
      <table class="w-full text-sm">
        <tbody>
          <tr v-for="scheduled in schedules" :key="scheduled.uuid" class="border-b border-paper-300 last:border-0">
            <td class="w-28 px-3 py-2 text-ink-700">{{ scheduled.next_date }}</td>
            <td class="w-24 px-3 py-2 capitalize text-mist-700">{{ scheduled.frequency }}</td>
            <td class="px-3 py-2">{{ scheduled.payee?.icon ? scheduled.payee.icon + ' ' : '' }}{{ scheduled.payee?.name ?? (scheduled.transfer_account_uuid ? 'Transfer' : '—') }}</td>
            <td class="px-3 py-2 text-ink-700">{{ scheduled.category?.icon ? scheduled.category.icon + ' ' : '' }}{{ scheduled.category?.name ?? '' }}</td>
            <td class="w-28 px-3 py-2 text-right font-medium" :class="scheduled.amount < 0 ? 'text-ink-800' : 'text-emerald-700'">
              {{ formatMoney(scheduled.amount, store.current?.currency) }}
            </td>
            <td class="w-32 pr-2 text-right">
              <button
                class=" border border-accent-500 px-2 py-0.5 text-xs text-accent-600 hover:bg-accent-100"
                @click="enterScheduled(scheduled)"
              >
                Enter now
              </button>
              <button
                class="ml-1 px-1.5 text-paper-400 hover:bg-red-100 hover:text-red-700"
                title="Delete"
                @click="removeScheduled(scheduled)"
              >✕</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="overflow-x-auto border border-paper-300 bg-paper-200 text-ink-800">
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
                class="h-4 w-4 border"
                :class="txn.cleared === 'uncleared' ? 'border-paper-400 bg-paper-50' : 'border-emerald-700 bg-emerald-600'"
                :title="txn.cleared"
                @click="toggleCleared(txn)"
              />
            </td>
            <td class="cursor-pointer px-3 py-2 text-ink-700" @click="startEdit(txn)">{{ txn.date }}</td>
            <td class="cursor-pointer px-3 py-2" @click="startEdit(txn)">
              {{ txn.payee?.icon ? txn.payee.icon + ' ' : '' }}{{ txn.payee?.name ?? '—' }}
              <span v-if="!txn.approved" class="ml-1 bg-accent-400 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-ink-900">New</span>
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
              <button v-else class=" px-1.5 text-paper-400 hover:bg-red-100 hover:text-red-700" title="Delete" @click="remove(txn)">✕</button>
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

    <!-- Delete account modal (type-to-confirm) -->
    <div v-if="showDeleteAccount" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-sm bg-paper-200 p-6 text-ink-800 shadow-xl">
        <h2 class="mb-1 text-lg font-semibold text-red-700">Delete {{ account?.name }}?</h2>
        <p class="mb-2 text-sm text-mist-700">
          This permanently deletes the account and <strong>all {{ transactions.length }} of its
          transactions</strong>, plus any schedules on it. Envelope balances will recalculate
          as if this account's activity never happened. This cannot be undone.
        </p>
        <p class="mb-4 text-sm text-mist-700">
          Type <strong class="font-mono text-ink-800">CONFIRMDELETE</strong> to confirm.
        </p>
        <form class="space-y-4" @submit.prevent="deleteAccount">
          <input
            v-model="deleteConfirmText"
            placeholder="CONFIRMDELETE"
            autocomplete="off"
            class="w-full border border-red-400 bg-paper-50 px-3 py-2 font-mono"
          >
          <div class="flex justify-end gap-2">
            <button type="button" class=" px-4 py-2 text-ink-600 hover:bg-paper-300" @click="showDeleteAccount = false">
              Cancel
            </button>
            <button
              type="submit"
              :disabled="deleteConfirmText !== 'CONFIRMDELETE' || deleteBusy"
              class=" bg-red-600 px-4 py-2 font-medium text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40"
            >
              {{ deleteBusy ? 'Deleting…' : 'Delete forever' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Reconcile modal -->
    <div v-if="showReconcile" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-sm bg-paper-200 p-6 text-ink-800 shadow-xl">
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
            class="w-full border border-paper-400 bg-paper-50 px-3 py-2"
          >
          <div class="flex justify-end gap-2">
            <button type="button" class=" px-4 py-2 text-ink-600 hover:bg-paper-300" @click="showReconcile = false">Cancel</button>
            <button type="submit" class=" bg-accent-400 px-4 py-2 font-medium text-ink-900 hover:bg-accent-500">Reconcile</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
