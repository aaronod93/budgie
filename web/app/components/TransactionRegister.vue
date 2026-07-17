<script setup lang="ts">
// The transaction register shared by All Accounts and individual account
// pages: inline entry row, click-any-cell-to-edit-in-place, payee/category
// pickers with in-situ creation, optimistic save-and-add-another, and the
// cleared "C" toggle. Pass account-uuid to scope it to one account (the
// account column locks and rows filter to that account).
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

interface PayeeOption {
  uuid: string
  name: string
  icon: string | null
  transfer_account_uuid: string | null
  default_category: { uuid: string, name: string } | null
  last_category_uuid: string | null
  last_flow: 'outflow' | 'inflow' | null
}

const ACCOUNT_GROUP: Record<string, string> = {
  checking: 'Bank Accounts',
  savings: 'Bank Accounts',
  cash: 'Cash Accounts',
  credit: 'Credit Cards',
  tracking: 'Tracking Accounts',
}

const props = withDefaults(defineProps<{
  accountUuid?: string | null
}>(), {
  accountUuid: null,
})

const store = useBudgetStore()

const transactions = ref<Txn[]>([])
const payees = ref<PayeeOption[]>([])
const outflowInput = ref<HTMLInputElement | null>(null)
const inflowInput = ref<HTMLInputElement | null>(null)
const loadingRows = ref(false)
const error = ref('')
const editingUuid = ref<string | null>(null)
const showAdd = ref(false)
const saving = ref(false)
const approving = ref(false)
const addKey = ref(0) // remounts the add row for a clean re-entry state
const addRowEl = ref<HTMLTableRowElement | null>(null)

// Function ref: inside v-for a string ref would collect an array; there is
// only ever one entry row, so bind it directly.
function setAddRow(el: unknown) {
  addRowEl.value = (el as HTMLTableRowElement | null) ?? null
}
const search = ref('')
let searchTimer: ReturnType<typeof setTimeout> | undefined

// In-situ "Add Category" dialog, opened from the category combobox's create row.
const catCreate = ref(false)
const catForm = reactive({ name: '', group: '' })
const catBusy = ref(false)

const openAccounts = computed(() => store.accounts.filter(a => !a.closed))
const accountOptions = computed(() => openAccounts.value.map(a => ({
  value: a.uuid,
  label: a.name,
  group: ACCOUNT_GROUP[a.type] ?? 'Accounts',
})))

const blankForm = () => ({
  account: props.accountUuid
    ?? openAccounts.value.find(a => a.on_budget)?.uuid
    ?? openAccounts.value[0]?.uuid
    ?? '',
  date: new Date().toISOString().slice(0, 10),
  payee: '',
  category: 'none' as string,
  memo: '',
  outflow: '',
  inflow: '',
  repeat: 'none' as string,
  cleared: false,
})
const form = reactive(blankForm())

const editingReconciled = computed(() =>
  transactions.value.find(t => t.uuid === editingUuid.value)?.cleared === 'reconciled')

// The register with the entry row spliced in: at the top when adding, or in
// place of the transaction being edited (inline editing).
const displayRows = computed<(Txn | 'entry')[]>(() => {
  if (editingUuid.value) {
    return transactions.value.map(t => (t.uuid === editingUuid.value ? 'entry' as const : t))
  }
  return showAdd.value ? ['entry' as const, ...transactions.value] : transactions.value
})

const accountName = (uuid: string) => store.accounts.find(a => a.uuid === uuid)?.name ?? '—'

const editingTransfer = computed(() =>
  editingUuid.value !== null
  && transactions.value.find(t => t.uuid === editingUuid.value)?.transfer_account_uuid != null,
)

watch([() => store.current, () => props.accountUuid], () => {
  cancelEdit()
  load()
}, { immediate: true })
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(load, 300)
})

const unapprovedCount = computed(() => transactions.value.filter(t => !t.approved).length)

async function load() {
  if (!store.current) return
  loadingRows.value = true
  try {
    const query = new URLSearchParams()
    if (props.accountUuid) query.set('account_id', props.accountUuid)
    if (search.value.trim()) query.set('search', search.value.trim())
    const [txns, payeeList] = await Promise.all([
      apiFetch<{ data: Txn[] }>(`${store.base}/transactions?${query}`),
      apiFetch<{ data: PayeeOption[] }>(`${store.base}/payees`),
      store.month ? Promise.resolve() : store.loadMonth().catch(() => {}),
      store.accounts.length ? Promise.resolve() : store.loadAccounts().catch(() => {}),
    ])
    transactions.value = txns.data
    payees.value = payeeList.data
  } finally {
    loadingRows.value = false
  }
}

defineExpose({ reload: load })

// --- Payee memory: pick a payee, get its last category + usual flow ---

const savedPayees = computed(() => payees.value.filter(p => !p.transfer_account_uuid))

const payeeOptions = computed(() => savedPayees.value.map(p => ({
  value: p.uuid,
  label: `${p.icon ? p.icon + ' ' : ''}${p.name}`,
})))

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

  const categoryUuid = payee.last_category_uuid ?? payee.default_category?.uuid ?? null
  if (categoryUuid) {
    if (categoryUuid === store.current?.ready_to_assign_category_uuid) {
      form.category = 'rta'
    } else if (store.groups.some(g => g.categories.some(c => c.uuid === categoryUuid))) {
      form.category = categoryUuid
    }
  }

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
    for (const acc of openAccounts.value.filter(a => a.uuid !== form.account)) {
      options.push({ value: `transfer:${acc.uuid}`, label: `Transfer : ${acc.name}` })
    }
  }
  return options
})

function rowLabel(txn: Txn): string {
  if (txn.splits.length > 0) return `Split (${txn.splits.length} categories)`
  if (txn.category) return (txn.category.icon ? txn.category.icon + ' ' : '') + txn.category.name
  return txn.transfer_account_uuid ? 'Transfer' : 'Uncategorised'
}

// Focus a specific cell of the entry row (defaults to date — the account is
// already prefilled). Used both for fresh entry and click-to-edit-in-place.
function focusCell(cell = 'date') {
  nextTick(() => {
    const row = addRowEl.value
    if (!row) return
    const el = row.querySelector<HTMLElement>(`[data-cell="${cell}"] input`)
      ?? row.querySelector<HTMLElement>('[data-cell="date"] input')
    el?.focus()
  })
}

/** Enter walks the row left-to-right like Tab; on the last field it saves. */
function onRowEnter(event: KeyboardEvent) {
  const target = event.target as HTMLElement
  const row = addRowEl.value
  if (!row || !(target instanceof HTMLInputElement)) return
  const fields = Array.from(row.querySelectorAll<HTMLElement>('input:not([disabled]), button:not([disabled])'))
  const index = fields.indexOf(target)
  if (index === -1) return
  event.preventDefault()
  const next = fields[index + 1]
  if (next) next.focus()
  else submit(false)
}

function openAdd() {
  editingUuid.value = null
  Object.assign(form, blankForm())
  showAdd.value = true
  addKey.value++
  focusCell('date')
}

/** Clicking any cell of a row turns it into the entry row, focused on that cell. */
function startEdit(txn: Txn, cell = 'date') {
  editingUuid.value = txn.uuid
  showAdd.value = true
  addKey.value++
  focusCell(cell)
  form.account = txn.account_uuid
  form.date = txn.date
  form.payee = txn.payee?.name ?? ''
  form.memo = txn.memo ?? ''
  form.outflow = txn.amount < 0 ? centsToInput(-txn.amount) : ''
  form.inflow = txn.amount > 0 ? centsToInput(txn.amount) : ''
  form.cleared = txn.cleared !== 'uncleared'
  if (txn.transfer_account_uuid) form.category = 'none'
  else if (txn.category?.uuid === store.current?.ready_to_assign_category_uuid) form.category = 'rta'
  else form.category = txn.category?.uuid ?? 'none'
}

function cancelEdit() {
  editingUuid.value = null
  showAdd.value = false
  Object.assign(form, blankForm())
}

async function submit(addAnother = false): Promise<void> {
  error.value = ''
  const outflow = parseMoney(form.outflow) ?? 0
  const inflow = parseMoney(form.inflow) ?? 0
  const amount = inflow - outflow
  if (amount === 0 && outflow === 0) {
    error.value = 'Enter an outflow or inflow amount.'
    return
  }
  if (!editingUuid.value && !form.account) {
    error.value = 'Choose an account.'
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

  const send = async () => {
    if (editingUuid.value) {
      delete body.transfer_account_id
      await apiFetch(`${store.base}/transactions/${editingUuid.value}`, { method: 'PATCH', body })
    } else if (body.frequency) {
      await apiFetch(`${store.base}/scheduled-transactions`, { method: 'POST', body })
    } else {
      await apiFetch(`${store.base}/transactions`, { method: 'POST', body })
    }
    await Promise.all([load(), store.loadAccounts()])
  }

  if (!editingUuid.value) {
    body.account_id = form.account
    if (form.repeat !== 'none') {
      body.frequency = form.repeat
      body.next_date = form.date
      delete body.date
    }
  }

  // Cleared toggle applies to real transactions; never demote a reconciled one.
  if (!body.frequency && !editingReconciled.value) {
    body.cleared = form.cleared ? 'cleared' : 'uncleared'
  }

  if (addAnother && !editingUuid.value) {
    // Optimistic: the next row opens immediately; the save and the balance
    // refresh happen in the background, surfacing any failure in the banner.
    // Account, date and cleared all carry over for rapid entry.
    const { account, date, cleared } = form
    Object.assign(form, blankForm(), { account, date, cleared })
    addKey.value++
    focusCell('date')
    send().catch((e) => {
      const err = e as { data?: { message?: string } }
      error.value = err.data?.message ?? 'Could not save the transaction — check the list before re-entering it.'
    })
    return
  }

  saving.value = true
  try {
    await send()
    cancelEdit()
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Could not save the transaction.'
  } finally {
    saving.value = false
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
  try {
    await $confirm('Delete transaction', 'This transaction will be removed and balances will recalculate.', 'Delete', 'Cancel')
  } catch { return }
  await apiFetch(`${store.base}/transactions/${txn.uuid}`, { method: 'DELETE' })
  if (editingUuid.value === txn.uuid) cancelEdit()
  await Promise.all([load(), store.loadAccounts()])
}

async function approveAll() {
  if (!props.accountUuid || approving.value) return
  approving.value = true
  try {
    await apiFetch(`${store.base}/transactions-approve-all`, {
      method: 'POST',
      body: { account_id: props.accountUuid },
    })
    await load()
  } finally {
    approving.value = false
  }
}

// A brand-new payee needs no dialog — the name is enough, and the API creates
// it when the transaction saves.
function onCreatePayee(name: string) {
  form.payee = name
}

// A new category needs a group, so gather that in a small dialog, then select it.
function openCategoryCreate(name: string) {
  catForm.name = name
  catForm.group = store.groups[0]?.uuid ?? ''
  catCreate.value = true
}

async function saveCategory() {
  const name = catForm.name.trim()
  if (!name || !catForm.group || catBusy.value) return
  catBusy.value = true
  error.value = ''
  try {
    form.category = await store.createCategory(catForm.group, name)
    catCreate.value = false
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Could not create the category.'
  } finally {
    catBusy.value = false
  }
}
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <button
        class="flex items-center gap-1.5 rounded-sm bg-ink-800 px-3 py-1.5 text-sm font-medium text-white hover:bg-ink-600"
        @click="showAdd ? cancelEdit() : openAdd()"
      >
        <span class="text-base leading-none">+</span> Add Transaction
      </button>
      <span class="text-paper-400">|</span>
      <input
        v-model="search"
        :placeholder="accountUuid ? 'Search this account…' : 'Search all accounts…'"
        class="w-64 rounded-sm border border-paper-400 bg-white px-3 py-1.5 text-sm text-ink-800 placeholder:text-mist-700"
      >
      <UiButton
        v-if="accountUuid && unapprovedCount > 0"
        class="ml-auto"
        size="sm"
        variant="secondary"
        :loading="approving"
        @click="approveAll"
      >
        Approve {{ unapprovedCount }} imported
      </UiButton>
      <span
        v-else-if="unapprovedCount > 0"
        class="ml-auto rounded-sm border border-mist-500 bg-mist-200/40 px-3 py-1.5 text-sm font-medium text-ink-700"
        title="Open an account to review and approve its imported transactions"
      >
        {{ unapprovedCount }} imported to review
      </span>
    </div>

    <p v-if="error" class="mb-4 rounded-sm bg-red-100 px-4 py-2 text-sm text-red-700">{{ error }}</p>

    <div class="overflow-x-auto rounded-sm border border-paper-300 bg-white text-ink-800">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-paper-300 text-left text-xs uppercase tracking-wide text-mist-700">
            <th class="w-44 px-3 py-3">Account</th>
            <th class="w-36 px-3 py-3">Date</th>
            <th class="px-3 py-3">Payee</th>
            <th class="px-3 py-3">Category</th>
            <th class="px-3 py-3">Memo</th>
            <th class="w-32 px-3 py-3 text-right">Outflow</th>
            <th class="w-32 px-3 py-3 text-right">Inflow</th>
            <th class="w-10 px-2 py-3 text-center" title="Cleared">C</th>
            <th class="w-10" />
          </tr>
        </thead>
        <tbody>
          <tr v-if="loadingRows && transactions.length === 0"><td colspan="9" class="px-4 py-8"><UiLoading label="Fetching transactions…" /></td></tr>
          <tr v-else-if="transactions.length === 0 && !showAdd"><td colspan="9" class="px-4 py-6 text-center text-mist-700">No transactions yet.</td></tr>
          <template v-for="item in displayRows" :key="item === 'entry' ? `entry-${addKey}` : item.uuid">
            <!-- Entry row: at the top when adding, in place of the row being edited -->
            <template v-if="item === 'entry'">
              <tr :ref="setAddRow" class="border-b border-paper-300 bg-mist-200/30 align-top" @keydown.enter="onRowEnter">
                <td class="px-2 py-2" data-cell="account">
                  <UiSelectMenu
                    :model-value="form.account"
                    :options="accountOptions"
                    size="sm"
                    :disabled="!!editingUuid || !!accountUuid"
                    @update:model-value="form.account = $event"
                  />
                </td>
                <td class="px-2 py-2" data-cell="date">
                  <UiDateField v-model="form.date" size="sm" required />
                </td>
                <td class="px-2 py-2" data-cell="payee">
                  <UiCombobox
                    size="sm"
                    placeholder="Payee"
                    heading="Saved Payees"
                    create-label="Payee"
                    footer-label="Manage Payees"
                    footer-to="/payees"
                    allow-custom
                    :disabled="editingTransfer"
                    :model-value="form.payee"
                    :options="payeeOptions"
                    @update:model-value="onPayeeChange"
                    @create="onCreatePayee"
                  />
                </td>
                <td class="px-2 py-2" data-cell="category">
                  <UiCombobox
                    size="sm"
                    placeholder="Category"
                    create-label="Category"
                    :disabled="editingTransfer"
                    :model-value="form.category"
                    :options="categoryOptions"
                    @update:model-value="form.category = $event || 'none'"
                    @create="openCategoryCreate"
                  />
                </td>
                <td class="px-2 py-2" data-cell="memo">
                  <input v-model="form.memo" placeholder="Memo" class="w-full rounded-sm border border-paper-400 bg-white px-2 py-1.5 text-sm focus:border-ink-500 focus:outline-none">
                </td>
                <td class="px-2 py-2" data-cell="outflow">
                  <input ref="outflowInput" v-model="form.outflow" placeholder="Outflow" inputmode="decimal" class="w-full rounded-sm border border-paper-400 bg-white px-2 py-1.5 text-right text-sm focus:border-ink-500 focus:outline-none">
                </td>
                <td class="px-2 py-2" data-cell="inflow">
                  <input ref="inflowInput" v-model="form.inflow" placeholder="Inflow" inputmode="decimal" class="w-full rounded-sm border border-paper-400 bg-white px-2 py-1.5 text-right text-sm focus:border-ink-500 focus:outline-none">
                </td>
                <td class="px-2 py-2 pt-3 text-center">
                  <button
                    type="button"
                    class="inline-flex h-5 w-5 items-center justify-center rounded-full border text-[10px] font-bold leading-none"
                    :class="form.cleared
                      ? 'border-accent-500 bg-accent-400 text-ink-900'
                      : 'border-paper-400 bg-white text-mist-700 hover:border-accent-400 hover:text-accent-500'"
                    :title="editingReconciled ? 'Reconciled (locked)' : (form.cleared ? 'Cleared' : 'Uncleared')"
                    :disabled="editingReconciled"
                    @click="form.cleared = !form.cleared"
                  >C</button>
                </td>
                <td class="px-2 py-2" />
              </tr>
              <tr class="border-b border-paper-300 bg-mist-200/30">
                <td colspan="9" class="px-3 py-2">
                  <div class="flex flex-wrap items-center justify-end gap-2">
                    <div v-if="!editingUuid" class="mr-auto w-44">
                      <UiSelect v-model="form.repeat" size="sm" title="Repeat">
                        <option value="none">No repeat</option>
                        <option value="once">Once (scheduled)</option>
                        <option value="weekly">Weekly</option>
                        <option value="fortnightly">Fortnightly</option>
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                      </UiSelect>
                    </div>
                    <UiButton variant="ghost" size="sm" :disabled="saving" @click="cancelEdit">Cancel</UiButton>
                    <UiButton size="sm" :loading="saving" @click="submit(false)">
                      {{ editingUuid ? 'Save Transaction' : 'Save' }}
                    </UiButton>
                    <UiButton v-if="!editingUuid" variant="secondary" size="sm" :disabled="saving" @click="submit(true)">
                      Save and add another
                    </UiButton>
                  </div>
                </td>
              </tr>
            </template>

            <tr
              v-else
              class="border-b border-paper-300 hover:bg-paper-100"
            >
              <td class="px-3 py-2">
                <NuxtLink :to="`/accounts/${item.account_uuid}`" class="text-ink-700 hover:text-accent-600 hover:underline">
                  {{ accountName(item.account_uuid) }}
                </NuxtLink>
              </td>
              <td class="cursor-text px-3 py-2 text-ink-700" @click="startEdit(item, 'date')">{{ formatDate(item.date) }}</td>
              <td class="cursor-text px-3 py-2" @click="startEdit(item, 'payee')">
                {{ item.payee?.icon ? item.payee.icon + ' ' : '' }}{{ item.payee?.name ?? '—' }}
                <span v-if="!item.approved" class="ml-1 rounded-sm bg-ink-800 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-white">New</span>
              </td>
              <td class="cursor-text px-3 py-2 text-ink-700" @click="startEdit(item, 'category')">{{ rowLabel(item) }}</td>
              <td class="cursor-text px-3 py-2 text-mist-700" @click="startEdit(item, 'memo')">{{ item.memo }}</td>
              <td class="cursor-text px-3 py-2 text-right font-medium" @click="startEdit(item, 'outflow')">
                {{ item.amount < 0 ? formatMoney(-item.amount, store.current?.currency) : '' }}
              </td>
              <td class="cursor-text px-3 py-2 text-right font-medium text-emerald-700" @click="startEdit(item, 'inflow')">
                {{ item.amount > 0 ? formatMoney(item.amount, store.current?.currency) : '' }}
              </td>
              <td class="px-2 py-2 text-center">
                <span v-if="item.cleared === 'reconciled'" class="px-1 text-paper-400" title="Reconciled (locked)">🔒</span>
                <button
                  v-else
                  class="inline-flex h-5 w-5 items-center justify-center rounded-full border text-[10px] font-bold leading-none"
                  :class="item.cleared === 'cleared'
                    ? 'border-accent-500 bg-accent-400 text-ink-900'
                    : 'border-paper-400 bg-white text-mist-700 hover:border-accent-400 hover:text-accent-500'"
                  :title="item.cleared"
                  @click="toggleCleared(item)"
                >C</button>
              </td>
              <td class="pr-2 text-right">
                <button v-if="item.cleared !== 'reconciled'" class="px-1.5 text-paper-400 hover:bg-red-100 hover:text-red-700" title="Delete" @click="remove(item)">✕</button>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <!-- Add category (in-situ, from the category combobox) -->
    <div v-if="catCreate" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-sm rounded-sm border border-paper-300 bg-white p-6 text-ink-800 shadow-xl">
        <h2 class="mb-4 text-lg font-semibold">Add Category</h2>
        <form class="space-y-4" @submit.prevent="saveCategory">
          <div>
            <label class="mb-1 block text-sm font-medium">Category Name</label>
            <input
              v-model="catForm.name"
              required
              autofocus
              class="w-full rounded-sm border border-paper-400 bg-white px-3 py-2 text-sm focus:border-ink-500 focus:outline-none"
            >
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium">In Category Group</label>
            <UiSelect v-model="catForm.group">
              <option v-for="group in store.groups" :key="group.uuid" :value="group.uuid">{{ group.name }}</option>
            </UiSelect>
          </div>
          <div class="flex justify-end gap-2">
            <UiButton variant="ghost" size="sm" :disabled="catBusy" @click="catCreate = false">Cancel</UiButton>
            <UiButton type="submit" size="sm" :loading="catBusy" :disabled="!catForm.name.trim() || !catForm.group">Save</UiButton>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
