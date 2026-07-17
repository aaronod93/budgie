<script setup lang="ts">
definePageMeta({ middleware: 'auth', layout: 'app' })

const store = useBudgetStore()

const openAccounts = computed(() => store.accounts.filter(a => !a.closed))

// Whole-of-budget balances across every open account.
const clearedTotal = computed(() => openAccounts.value.reduce((sum, a) => sum + (a.cleared_balance ?? 0), 0))
const workingTotal = computed(() => openAccounts.value.reduce((sum, a) => sum + (a.balance ?? 0), 0))
const unclearedTotal = computed(() => workingTotal.value - clearedTotal.value)
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

    <TransactionRegister />
  </div>
</template>
