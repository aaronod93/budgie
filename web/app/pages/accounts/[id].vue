<script setup lang="ts">
import type { TransactionRegister } from '#components'

definePageMeta({ middleware: 'auth', layout: 'app' })

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

const route = useRoute()
const store = useBudgetStore()

const register = ref<InstanceType<typeof TransactionRegister> | null>(null)
const schedules = ref<Scheduled[]>([])
const error = ref('')
const showReconcile = ref(false)
const statementBalance = ref('')
const reconcileBusy = ref(false)
const reconcileMessage = ref('')
const showImport = ref(false)
const showDeleteAccount = ref(false)
const deleteConfirmText = ref('')
const deleteBusy = ref(false)

const accountUuid = computed(() => route.params.id as string)
const account = computed(() => store.accounts.find(a => a.uuid === accountUuid.value))
const unclearedBalance = computed(() => (account.value?.balance ?? 0) - (account.value?.cleared_balance ?? 0))

watch([() => store.current, accountUuid], loadSchedules, { immediate: true })

async function loadSchedules() {
  if (!store.current) return
  schedules.value = (await apiFetch<{ data: Scheduled[] }>(
    `${store.base}/scheduled-transactions?account_id=${accountUuid.value}`,
  )).data
}

async function refreshAll() {
  await Promise.all([loadSchedules(), store.loadAccounts()])
  register.value?.reload()
}

async function enterScheduled(scheduled: Scheduled) {
  await apiFetch(`${store.base}/scheduled-transactions/${scheduled.uuid}/enter`, { method: 'POST' })
  await refreshAll()
}

async function removeScheduled(scheduled: Scheduled) {
  try {
    await $confirm('Delete scheduled transaction', 'It will no longer be entered automatically.', 'Delete', 'Cancel')
  } catch { return }
  await apiFetch(`${store.base}/scheduled-transactions/${scheduled.uuid}`, { method: 'DELETE' })
  await loadSchedules()
}

async function reconcile() {
  const balance = parseMoney(statementBalance.value)
  if (balance === null || reconcileBusy.value) return
  reconcileBusy.value = true
  reconcileMessage.value = ''
  error.value = ''
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
    await refreshAll()
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Could not reconcile.'
    showReconcile.value = false
  } finally {
    reconcileBusy.value = false
  }
}

async function closeAccount() {
  if ((account.value?.balance ?? 0) !== 0) {
    error.value = 'Transfer the remaining balance out before closing this account.'
    return
  }
  try {
    await $confirm(
      `Close ${account.value?.name}?`,
      'Its history stays; you can reopen it any time.',
      'Close account',
      'Cancel',
      { danger: false },
    )
  } catch { return }
  error.value = ''
  try {
    await apiFetch(`${store.base}/accounts/${accountUuid.value}`, { method: 'PATCH', body: { closed: true } })
    await store.loadAccounts()
    await navigateTo('/accounts')
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
    await navigateTo('/accounts')
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Could not delete the account.'
    showDeleteAccount.value = false
  } finally {
    deleteBusy.value = false
  }
}
</script>

<template>
  <div class="w-full p-6">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-xl font-bold">
          {{ account?.name ?? 'Account' }}
          <span v-if="account?.closed" class="ml-2 rounded-sm bg-paper-300 px-2 py-0.5 align-middle text-xs font-medium text-mist-700">Closed</span>
        </h1>
        <p class="text-sm capitalize text-mist-700">{{ account?.type }}{{ account?.on_budget ? '' : ' · off budget' }}</p>
      </div>
      <div class="flex items-center gap-3 text-right">
        <div>
          <p class="font-semibold text-ink-700">{{ formatMoney(account?.cleared_balance ?? 0, store.current?.currency) }}</p>
          <p class="text-xs text-mist-700">Cleared</p>
        </div>
        <span class="text-mist-700">+</span>
        <div>
          <p class="font-semibold text-ink-700">{{ formatMoney(unclearedBalance, store.current?.currency) }}</p>
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
      <div class="flex flex-wrap items-center gap-2">
        <button
          class="rounded-sm border border-paper-400 px-3 py-1.5 text-sm text-ink-700 hover:bg-paper-100"
          @click="showImport = true"
        >
          Import
        </button>
        <button
          class="rounded-sm border border-paper-400 px-3 py-1.5 text-sm text-ink-700 hover:bg-paper-100"
          @click="showReconcile = true; statementBalance = centsToInput(account?.cleared_balance ?? 0)"
        >
          Reconcile
        </button>
        <button
          v-if="account?.closed"
          class="rounded-sm border border-accent-400 bg-accent-100 px-3 py-1.5 text-sm font-medium text-accent-600 hover:bg-accent-200"
          @click="reopenAccount"
        >
          Reopen account
        </button>
        <button
          v-else
          class="rounded-sm border border-paper-400 px-3 py-1.5 text-sm text-ink-700 hover:bg-paper-100"
          @click="closeAccount"
        >
          Close account
        </button>
        <button
          class="rounded-sm border border-red-300 px-3 py-1.5 text-sm text-red-600 hover:bg-red-100"
          @click="showDeleteAccount = true; deleteConfirmText = ''"
        >
          Delete account
        </button>
      </div>
    </header>

    <p v-if="error" class="mb-4 rounded-sm bg-red-100 px-4 py-2 text-sm text-red-700">{{ error }}</p>
    <p v-if="reconcileMessage" class="mb-4 rounded-sm bg-mist-200 px-4 py-2 text-sm text-ink-700">
      {{ reconcileMessage }}
    </p>

    <!-- Scheduled transactions -->
    <div v-if="schedules.length" class="mb-6 overflow-x-auto rounded-sm border border-paper-300 bg-white text-ink-800">
      <p class="border-b border-paper-300 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-mist-700">
        Scheduled
      </p>
      <table class="w-full text-sm">
        <tbody>
          <tr v-for="scheduled in schedules" :key="scheduled.uuid" class="border-b border-paper-300 last:border-0">
            <td class="w-28 px-3 py-2 text-ink-700">{{ formatDate(scheduled.next_date) }}</td>
            <td class="w-24 px-3 py-2 capitalize text-mist-700">{{ scheduled.frequency }}</td>
            <td class="px-3 py-2">{{ scheduled.payee?.icon ? scheduled.payee.icon + ' ' : '' }}{{ scheduled.payee?.name ?? (scheduled.transfer_account_uuid ? 'Transfer' : '—') }}</td>
            <td class="px-3 py-2 text-ink-700">{{ scheduled.category?.icon ? scheduled.category.icon + ' ' : '' }}{{ scheduled.category?.name ?? '' }}</td>
            <td class="w-28 px-3 py-2 text-right font-medium" :class="scheduled.amount < 0 ? 'text-ink-800' : 'text-emerald-700'">
              {{ formatMoney(scheduled.amount, store.current?.currency) }}
            </td>
            <td class="w-32 pr-2 text-right">
              <button
                class="rounded-sm border border-accent-500 px-2 py-0.5 text-xs text-accent-600 hover:bg-accent-100"
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

    <TransactionRegister ref="register" :account-uuid="accountUuid" />

    <ImportWizard
      v-if="showImport"
      :account-uuid="accountUuid"
      @close="showImport = false"
      @done="refreshAll()"
    />

    <!-- Delete account modal (type-to-confirm) -->
    <div v-if="showDeleteAccount" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-sm rounded-sm border border-paper-300 bg-white p-6 text-ink-800 shadow-xl">
        <h2 class="mb-1 text-lg font-semibold text-red-700">Delete {{ account?.name }}?</h2>
        <p class="mb-2 text-sm text-mist-700">
          This permanently deletes the account and <strong>all of its transactions</strong>,
          plus any schedules on it. Envelope balances will recalculate as if this
          account's activity never happened. This cannot be undone.
        </p>
        <p class="mb-4 text-sm text-mist-700">
          Type <strong class="font-mono text-ink-800">CONFIRMDELETE</strong> to confirm.
        </p>
        <form class="space-y-4" @submit.prevent="deleteAccount">
          <input
            v-model="deleteConfirmText"
            placeholder="CONFIRMDELETE"
            autocomplete="off"
            class="w-full rounded-sm border border-red-400 bg-white px-3 py-2 font-mono focus:border-red-500 focus:outline-none"
          >
          <div class="flex justify-end gap-2">
            <UiButton variant="ghost" :disabled="deleteBusy" @click="showDeleteAccount = false">Cancel</UiButton>
            <UiButton
              type="submit"
              variant="danger"
              :loading="deleteBusy"
              :disabled="deleteConfirmText !== 'CONFIRMDELETE'"
            >
              Delete forever
            </UiButton>
          </div>
        </form>
      </div>
    </div>

    <!-- Reconcile modal -->
    <div v-if="showReconcile" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-sm rounded-sm border border-paper-300 bg-white p-6 text-ink-800 shadow-xl">
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
            class="w-full rounded-sm border border-paper-400 bg-white px-3 py-2 focus:border-ink-500 focus:outline-none"
          >
          <div class="flex justify-end gap-2">
            <UiButton variant="ghost" :disabled="reconcileBusy" @click="showReconcile = false">Cancel</UiButton>
            <UiButton type="submit" :loading="reconcileBusy">Reconcile</UiButton>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
