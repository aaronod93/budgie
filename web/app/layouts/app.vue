<script setup lang="ts">
import type Echo from 'laravel-echo'

type EchoClient = Echo<'reverb'>

const auth = useAuthStore()
const store = useBudgetStore()
const { $echo } = useNuxtApp()

const showAddAccount = ref(false)
const accountForm = reactive({ name: '', type: 'checking', balance: '' })
const accountError = ref('')

onMounted(async () => {
  await store.init()
  store.loadInvitations().catch(() => {})
})

// Live refresh: follow the private channel of whichever budget is open.
let subscribedTo: string | null = null
watch(() => store.current?.uuid, (uuid) => {
  const echo = $echo as EchoClient | undefined
  if (!echo) return
  if (subscribedTo) echo.leave(`budget.${subscribedTo}`)
  subscribedTo = uuid ?? null
  if (!uuid) return
  echo.private(`budget.${uuid}`).listen('.activity', (entry: { description: string, user: string | null }) => {
    const isSomeoneElse = entry.user && entry.user !== auth.user?.name
    store.refreshFromLive(isSomeoneElse ? `${entry.user}: ${entry.description}` : null)
  })
}, { immediate: true })

onUnmounted(() => {
  if (subscribedTo) (($echo as EchoClient | undefined))?.leave(`budget.${subscribedTo}`)
})

const onBudgetAccounts = computed(() => store.accounts.filter(a => a.on_budget && !a.closed))
const trackingAccounts = computed(() => store.accounts.filter(a => !a.on_budget && !a.closed))

async function submitAccount() {
  accountError.value = ''
  try {
    await store.addAccount({
      name: accountForm.name,
      type: accountForm.type,
      balance: parseMoney(accountForm.balance) ?? 0,
    })
    showAddAccount.value = false
    accountForm.name = ''
    accountForm.balance = ''
  } catch (e) {
    const err = e as { data?: { message?: string } }
    accountError.value = err.data?.message ?? 'Could not create the account.'
  }
}

async function logout() {
  await auth.logout()
  await navigateTo('/login')
}
</script>

<template>
  <div class="flex min-h-screen">
    <aside class="flex w-64 shrink-0 flex-col bg-ink-900 text-paper-100">
      <div class="px-4 py-5">
        <h1 class="text-xl font-bold">Lil' Budgie</h1>
        <p class="truncate text-sm text-mist-300">{{ store.current?.name }}</p>
      </div>

      <nav class="px-2">
        <NuxtLink
          to="/budget"
          class="block rounded-md px-3 py-2 font-medium hover:bg-ink-700"
          active-class="bg-ink-700 text-accent-300"
        >
          Budget
        </NuxtLink>
        <NuxtLink
          to="/reports"
          class="block rounded-md px-3 py-2 font-medium hover:bg-ink-700"
          active-class="bg-ink-700 text-accent-300"
        >
          Reports
        </NuxtLink>
        <NuxtLink
          to="/payees"
          class="block rounded-md px-3 py-2 font-medium hover:bg-ink-700"
          active-class="bg-ink-700 text-accent-300"
        >
          Payees
        </NuxtLink>
        <NuxtLink
          to="/sharing"
          class="block rounded-md px-3 py-2 font-medium hover:bg-ink-700"
          active-class="bg-ink-700 text-accent-300"
        >
          Sharing
        </NuxtLink>
      </nav>

      <div class="mt-6 flex-1 overflow-y-auto px-2">
        <p class="px-3 text-xs font-semibold uppercase tracking-wide text-mist-500">Accounts</p>
        <NuxtLink
          v-for="account in onBudgetAccounts"
          :key="account.uuid"
          :to="`/accounts/${account.uuid}`"
          class="mt-1 flex items-center justify-between rounded-md px-3 py-1.5 text-sm hover:bg-ink-700"
          active-class="bg-ink-700 text-accent-300"
        >
          <span class="truncate">{{ account.name }}</span>
          <span :class="account.balance < 0 ? 'text-red-300' : 'text-mist-200'">
            {{ formatMoney(account.balance, store.current?.currency) }}
          </span>
        </NuxtLink>

        <template v-if="trackingAccounts.length">
          <p class="mt-4 px-3 text-xs font-semibold uppercase tracking-wide text-mist-500">Tracking</p>
          <NuxtLink
            v-for="account in trackingAccounts"
            :key="account.uuid"
            :to="`/accounts/${account.uuid}`"
            class="mt-1 flex items-center justify-between rounded-md px-3 py-1.5 text-sm hover:bg-ink-700"
            active-class="bg-ink-700 text-accent-300"
          >
            <span class="truncate">{{ account.name }}</span>
            <span class="text-mist-200">{{ formatMoney(account.balance, store.current?.currency) }}</span>
          </NuxtLink>
        </template>

        <button
          class="mt-3 w-full rounded-md border border-ink-600 px-3 py-1.5 text-sm text-mist-200 hover:bg-ink-700"
          @click="showAddAccount = true"
        >
          + Add account
        </button>
      </div>

      <div class="border-t border-ink-700 p-4">
        <div class="flex items-center justify-between">
          <span class="truncate text-sm text-mist-200">{{ auth.user?.name }}</span>
          <button class="text-sm text-mist-500 hover:text-paper-100" @click="logout">Sign out</button>
        </div>
      </div>
    </aside>

    <main class="min-w-0 flex-1 bg-ink-800">
      <div
        v-for="invitation in store.invitations"
        :key="invitation.uuid"
        class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-600 bg-ink-700 px-6 py-3 text-sm text-paper-100"
      >
        <span>
          <strong>{{ invitation.invited_by }}</strong> invited you to share
          <strong>{{ invitation.budget_name }}</strong> as {{ invitation.role }}.
        </span>
        <span class="flex gap-2">
          <button
            class="rounded-md bg-accent-400 px-3 py-1 font-medium text-ink-900 hover:bg-accent-500"
            @click="store.acceptInvitation(invitation.uuid)"
          >Accept</button>
          <button
            class="rounded-md border border-ink-500 px-3 py-1 text-mist-200 hover:bg-ink-600"
            @click="store.declineInvitation(invitation.uuid)"
          >Decline</button>
        </span>
      </div>

      <slot />

      <!-- Live activity toast (another device/user changed the budget) -->
      <Transition
        enter-active-class="transition duration-200"
        enter-from-class="translate-y-2 opacity-0"
        leave-active-class="transition duration-300"
        leave-to-class="opacity-0"
      >
        <div
          v-if="store.liveMessage"
          class="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg bg-paper-200 px-4 py-3 text-sm text-ink-800 shadow-lg"
        >
          {{ store.liveMessage }}
        </div>
      </Transition>
    </main>

    <!-- Add account modal -->
    <div v-if="showAddAccount" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-sm rounded-xl bg-paper-200 p-6 text-ink-800 shadow-xl">
        <h2 class="mb-4 text-lg font-semibold">Add account</h2>
        <form class="space-y-4" @submit.prevent="submitAccount">
          <div>
            <label class="mb-1 block text-sm font-medium">Name</label>
            <input v-model="accountForm.name" required class="w-full rounded-md border border-paper-400 bg-paper-50 px-3 py-2">
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium">Type</label>
            <select v-model="accountForm.type" class="w-full rounded-md border border-paper-400 bg-paper-50 px-3 py-2">
              <option value="checking">Checking</option>
              <option value="savings">Savings</option>
              <option value="cash">Cash</option>
              <option value="credit">Credit card</option>
              <option value="tracking">Tracking (off budget)</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium">Current balance</label>
            <input v-model="accountForm.balance" placeholder="0.00" inputmode="decimal" class="w-full rounded-md border border-paper-400 bg-paper-50 px-3 py-2">
          </div>
          <p v-if="accountError" class="text-sm text-red-600">{{ accountError }}</p>
          <div class="flex justify-end gap-2">
            <button type="button" class="rounded-md px-4 py-2 text-ink-600 hover:bg-paper-300" @click="showAddAccount = false">Cancel</button>
            <button type="submit" class="rounded-md bg-accent-400 px-4 py-2 font-medium text-ink-900 hover:bg-accent-500">Add</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
