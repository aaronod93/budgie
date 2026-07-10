<script setup lang="ts">
const auth = useAuthStore()
const store = useBudgetStore()

const showAddAccount = ref(false)
const accountForm = reactive({ name: '', type: 'checking', balance: '' })
const accountError = ref('')

onMounted(() => store.init())

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
    <aside class="flex w-64 shrink-0 flex-col bg-emerald-900 text-emerald-50">
      <div class="px-4 py-5">
        <h1 class="text-xl font-bold">Budgie</h1>
        <p class="truncate text-sm text-emerald-300">{{ store.current?.name }}</p>
      </div>

      <nav class="px-2">
        <NuxtLink
          to="/budget"
          class="block rounded-md px-3 py-2 font-medium hover:bg-emerald-800"
          active-class="bg-emerald-800"
        >
          Budget
        </NuxtLink>
      </nav>

      <div class="mt-6 flex-1 overflow-y-auto px-2">
        <p class="px-3 text-xs font-semibold uppercase tracking-wide text-emerald-400">Accounts</p>
        <NuxtLink
          v-for="account in onBudgetAccounts"
          :key="account.uuid"
          :to="`/accounts/${account.uuid}`"
          class="mt-1 flex items-center justify-between rounded-md px-3 py-1.5 text-sm hover:bg-emerald-800"
          active-class="bg-emerald-800"
        >
          <span class="truncate">{{ account.name }}</span>
          <span :class="account.balance < 0 ? 'text-red-300' : 'text-emerald-200'">
            {{ formatMoney(account.balance, store.current?.currency) }}
          </span>
        </NuxtLink>

        <template v-if="trackingAccounts.length">
          <p class="mt-4 px-3 text-xs font-semibold uppercase tracking-wide text-emerald-400">Tracking</p>
          <NuxtLink
            v-for="account in trackingAccounts"
            :key="account.uuid"
            :to="`/accounts/${account.uuid}`"
            class="mt-1 flex items-center justify-between rounded-md px-3 py-1.5 text-sm hover:bg-emerald-800"
            active-class="bg-emerald-800"
          >
            <span class="truncate">{{ account.name }}</span>
            <span class="text-emerald-200">{{ formatMoney(account.balance, store.current?.currency) }}</span>
          </NuxtLink>
        </template>

        <button
          class="mt-3 w-full rounded-md border border-emerald-700 px-3 py-1.5 text-sm text-emerald-200 hover:bg-emerald-800"
          @click="showAddAccount = true"
        >
          + Add account
        </button>
      </div>

      <div class="border-t border-emerald-800 p-4">
        <div class="flex items-center justify-between">
          <span class="truncate text-sm text-emerald-200">{{ auth.user?.name }}</span>
          <button class="text-sm text-emerald-400 hover:text-emerald-100" @click="logout">Sign out</button>
        </div>
      </div>
    </aside>

    <main class="min-w-0 flex-1 bg-slate-50">
      <slot />
    </main>

    <!-- Add account modal -->
    <div v-if="showAddAccount" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-sm rounded-xl bg-white p-6 shadow-xl">
        <h2 class="mb-4 text-lg font-semibold">Add account</h2>
        <form class="space-y-4" @submit.prevent="submitAccount">
          <div>
            <label class="mb-1 block text-sm font-medium">Name</label>
            <input v-model="accountForm.name" required class="w-full rounded-md border border-slate-300 px-3 py-2">
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium">Type</label>
            <select v-model="accountForm.type" class="w-full rounded-md border border-slate-300 px-3 py-2">
              <option value="checking">Checking</option>
              <option value="savings">Savings</option>
              <option value="cash">Cash</option>
              <option value="credit">Credit card</option>
              <option value="tracking">Tracking (off budget)</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium">Current balance</label>
            <input v-model="accountForm.balance" placeholder="0.00" inputmode="decimal" class="w-full rounded-md border border-slate-300 px-3 py-2">
          </div>
          <p v-if="accountError" class="text-sm text-red-600">{{ accountError }}</p>
          <div class="flex justify-end gap-2">
            <button type="button" class="rounded-md px-4 py-2 text-slate-600 hover:bg-slate-100" @click="showAddAccount = false">Cancel</button>
            <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 font-medium text-white hover:bg-emerald-700">Add</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
