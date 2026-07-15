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
const addKey = ref(0) // remounts the add row so the account menu re-opens
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
  account: openAccounts.value.find(a => a.on_budget)?.uuid ?? openAccounts.value[0]?.uuid ?? '',
  date: new Date().toISOString().slice(0, 10),
  payee: '',
  category: 'none' as string,
  memo: '',
  outflow: '',
  inflow: '',
  repeat: 'none' as string,
})
const form = reactive(blankForm())

// Whole-of-budget balances across every open account.
const clearedTotal = computed(() => openAccounts.value.reduce((sum, a) => sum + (a.cleared_balance ?? 0), 0))
const workingTotal = computed(() => openAccounts.value.reduce((sum, a) => sum + (a.balance ?? 0), 0))
const unclearedTotal = computed(() => workingTotal.value - clearedTotal.value)

const accountName = (uuid: string) => store.accounts.find(a => a.uuid === uuid)?.name ?? '—'

const editingTransfer = computed(() =>
  editingUuid.value !== null
  && transactions.value.find(t => t.uuid === editingUuid.value)?.transfer_account_uuid != null,
)

watch(() => store.current, load, { immediate: true })
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

function openAdd() {
  editingUuid.value = null
  Object.assign(form, blankForm())
  showAdd.value = true
  addKey.value++
}

function startEdit(txn: Txn) {
  editingUuid.value = txn.uuid
  showAdd.value = true
  addKey.value++
  form.account = txn.account_uuid
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

  saving.value = true
  try {
    if (editingUuid.value) {
      delete body.transfer_account_id
      await apiFetch(`${store.base}/transactions/${editingUuid.value}`, { method: 'PATCH', body })
    } else if (form.repeat !== 'none') {
      body.account_id = form.account
      body.frequency = form.repeat
      body.next_date = form.date
      delete body.date
      await apiFetch(`${store.base}/scheduled-transactions`, { method: 'POST', body })
    } else {
      body.account_id = form.account
      await apiFetch(`${store.base}/transactions`, { method: 'POST', body })
    }
    await Promise.all([load(), store.loadAccounts()])
    if (addAnother && !editingUuid.value) {
      // Keep account + date for rapid entry; reset the rest and re-open the row.
      const { account, date } = form
      Object.assign(form, blankForm(), { account, date })
      addKey.value++
    } else {
      cancelEdit()
    }
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
  if (!confirm('Delete this transaction?')) return
  await apiFetch(`${store.base}/transactions/${txn.uuid}`, { method: 'DELETE' })
  if (editingUuid.value === txn.uuid) cancelEdit()
  await Promise.all([load(), store.loadAccounts()])
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
  <div class="w-full p-6">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-xl font-bold">All Accounts</h1>
        <p class="text-sm text-mist-700">{{ openAccounts.length }} open account{{ openAccounts.length === 1 ? '' : 's' }}</p>
      </div>
      <div class="flex items-center gap-3 text-right">
        <div>
          <p class="font-semibold text-ink-700">{{ formatMoney(clearedTotal, store.current?.currency) }}</p>
          <p class="text-xs text-mist-700">Cleared</p>
        </div>
        <span class="text-mist-700">+</span>
        <div>
          <p class="font-semibold text-ink-700">{{ formatMoney(unclearedTotal, store.current?.currency) }}</p>
          <p class="text-xs text-mist-700">Uncleared</p>
        </div>
        <span class="text-mist-700">=</span>
        <div>
          <p class="text-lg font-bold" :class="workingTotal < 0 ? 'text-red-600' : 'text-ink-800'">
            {{ formatMoney(workingTotal, store.current?.currency) }}
          </p>
          <p class="text-xs text-mist-700">Working balance</p>
        </div>
      </div>
    </header>

    <div class="mb-4 flex flex-wrap items-center gap-3">
      <button
        class="flex items-center gap-1.5 bg-accent-400 px-3 py-1.5 text-sm font-medium text-ink-900 hover:bg-accent-500"
        @click="showAdd ? cancelEdit() : openAdd()"
      >
        <span class="text-base leading-none">+</span> Add Transaction
      </button>
      <span class="text-paper-400">|</span>
      <input
        v-model="search"
        placeholder="Search all accounts…"
        class="w-64 border border-paper-400 bg-white px-3 py-1.5 text-sm text-ink-800 placeholder:text-mist-700"
      >
      <span
        v-if="unapprovedCount > 0"
        class="ml-auto border border-accent-400 bg-accent-100 px-3 py-1.5 text-sm font-medium text-accent-600"
        title="Open an account to review and approve its imported transactions"
      >
        {{ unapprovedCount }} imported to review
      </span>
    </div>

    <p v-if="error" class="mb-4 bg-red-100 px-4 py-2 text-sm text-red-700">{{ error }}</p>

    <div class="overflow-x-auto border border-paper-300 bg-white text-ink-800">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-paper-300 text-left text-xs uppercase tracking-wide text-mist-700">
            <th class="w-8 px-3 py-3" title="Cleared">C</th>
            <th class="w-44 px-3 py-3">Account</th>
            <th class="w-36 px-3 py-3">Date</th>
            <th class="px-3 py-3">Payee</th>
            <th class="px-3 py-3">Category</th>
            <th class="px-3 py-3">Memo</th>
            <th class="w-32 px-3 py-3 text-right">Outflow</th>
            <th class="w-32 px-3 py-3 text-right">Inflow</th>
            <th class="w-10" />
          </tr>
        </thead>
        <tbody>
          <!-- Inline add / edit row -->
          <template v-if="showAdd">
            <tr :key="addKey" class="border-b border-paper-300 bg-accent-100/60 align-top">
              <td class="px-2 py-2" />
              <td class="px-2 py-2">
                <UiSelectMenu
                  :model-value="form.account"
                  :options="accountOptions"
                  size="sm"
                  :disabled="!!editingUuid"
                  :auto-open="!editingUuid"
                  @update:model-value="form.account = $event"
                />
              </td>
              <td class="px-2 py-2">
                <UiDateField v-model="form.date" size="sm" required />
              </td>
              <td class="px-2 py-2">
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
              <td class="px-2 py-2">
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
              <td class="px-2 py-2">
                <input v-model="form.memo" placeholder="Memo" class="w-full border border-paper-400 bg-paper-50 px-2 py-1.5 text-sm" @keydown.enter.prevent="submit(false)">
              </td>
              <td class="px-2 py-2">
                <input ref="outflowInput" v-model="form.outflow" placeholder="Outflow" inputmode="decimal" class="w-full border border-paper-400 bg-paper-50 px-2 py-1.5 text-right text-sm" @keydown.enter.prevent="submit(false)">
              </td>
              <td class="px-2 py-2">
                <input ref="inflowInput" v-model="form.inflow" placeholder="Inflow" inputmode="decimal" class="w-full border border-paper-400 bg-paper-50 px-2 py-1.5 text-right text-sm" @keydown.enter.prevent="submit(false)">
              </td>
              <td class="px-2 py-2" />
            </tr>
            <tr class="border-b border-paper-300 bg-accent-100/60">
              <td colspan="9" class="px-3 py-2">
                <div class="flex flex-wrap items-center justify-end gap-2">
                  <UiSelect v-if="!editingUuid" v-model="form.repeat" size="sm" title="Repeat" class="mr-auto w-40">
                    <option value="none">No repeat</option>
                    <option value="once">Once (scheduled)</option>
                    <option value="weekly">Weekly</option>
                    <option value="fortnightly">Fortnightly</option>
                    <option value="monthly">Monthly</option>
                    <option value="yearly">Yearly</option>
                  </UiSelect>
                  <UiButton variant="ghost" size="sm" :disabled="saving" @click="cancelEdit">Cancel</UiButton>
                  <UiButton size="sm" :loading="saving" @click="submit(false)">Save</UiButton>
                  <UiButton v-if="!editingUuid" variant="secondary" size="sm" :disabled="saving" @click="submit(true)">
                    Save and add another
                  </UiButton>
                </div>
              </td>
            </tr>
          </template>

          <tr v-if="loadingRows"><td colspan="9" class="px-4 py-6 text-center text-mist-700">Loading…</td></tr>
          <tr v-else-if="transactions.length === 0 && !showAdd"><td colspan="9" class="px-4 py-6 text-center text-mist-700">No transactions yet.</td></tr>
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
            <td class="px-3 py-2">
              <NuxtLink :to="`/accounts/${txn.account_uuid}`" class="text-ink-700 hover:text-accent-600 hover:underline">
                {{ accountName(txn.account_uuid) }}
              </NuxtLink>
            </td>
            <td class="cursor-pointer px-3 py-2 text-ink-700" @click="startEdit(txn)">{{ txn.date }}</td>
            <td class="cursor-pointer px-3 py-2" @click="startEdit(txn)">
              {{ txn.payee?.icon ? txn.payee.icon + ' ' : '' }}{{ txn.payee?.name ?? '—' }}
              <span v-if="!txn.approved" class="ml-1 bg-accent-400 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-ink-900">New</span>
            </td>
            <td class="cursor-pointer px-3 py-2 text-ink-700" @click="startEdit(txn)">{{ rowLabel(txn) }}</td>
            <td class="cursor-pointer px-3 py-2 text-mist-700" @click="startEdit(txn)">{{ txn.memo }}</td>
            <td class="cursor-pointer px-3 py-2 text-right font-medium" @click="startEdit(txn)">
              {{ txn.amount < 0 ? formatMoney(-txn.amount, store.current?.currency) : '' }}
            </td>
            <td class="cursor-pointer px-3 py-2 text-right font-medium text-emerald-700" @click="startEdit(txn)">
              {{ txn.amount > 0 ? formatMoney(txn.amount, store.current?.currency) : '' }}
            </td>
            <td class="pr-2 text-right">
              <span v-if="txn.cleared === 'reconciled'" class="px-1.5 text-paper-400" title="Reconciled (locked)">🔒</span>
              <button v-else class=" px-1.5 text-paper-400 hover:bg-red-100 hover:text-red-700" title="Delete" @click="remove(txn)">✕</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Add category (in-situ, from the category combobox) -->
    <div v-if="catCreate" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-sm border border-paper-300 bg-white p-6 text-ink-800 shadow-xl">
        <h2 class="mb-4 text-lg font-semibold">Add Category</h2>
        <form class="space-y-4" @submit.prevent="saveCategory">
          <div>
            <label class="mb-1 block text-sm font-medium">Category Name</label>
            <input
              v-model="catForm.name"
              required
              autofocus
              class="w-full border border-paper-400 bg-paper-50 px-3 py-2 text-sm focus:border-accent-400 focus:outline-none"
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
