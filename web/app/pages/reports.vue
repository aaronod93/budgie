<script setup lang="ts">
definePageMeta({ middleware: 'auth', layout: 'app' })

// Chart palette validated with the dataviz six-checks script on the paper card
// surface #E7E6E2: burnt orange #B95A25 + teal #1E739E (worst CVD dE 69.0, >=3:1).
const SERIES_1 = '#B95A25'
const SERIES_2 = '#1E739E'

interface SpendingReport {
  total: number
  groups: { uuid: string | null, name: string, amount: number }[]
  monthly: { month: string, amount: number }[]
}
interface NetWorthReport {
  months: { month: string, assets: number, debts: number, net: number }[]
}
interface IncomeExpenseReport {
  months: { month: string, income: number, expense: number, net: number }[]
}

const store = useBudgetStore()

const rangeMonths = ref(6)
const groupBy = ref<'category' | 'payee'>('category')
const spending = ref<SpendingReport | null>(null)
const netWorth = ref<NetWorthReport | null>(null)
const incomeExpense = ref<IncomeExpenseReport | null>(null)
const ageOfMoney = ref<number | null>(null)
const loading = ref(false)

const range = computed(() => {
  const now = new Date()
  const to = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
  const fromDate = new Date(now.getFullYear(), now.getMonth() - (rangeMonths.value - 1), 1)
  const from = `${fromDate.getFullYear()}-${String(fromDate.getMonth() + 1).padStart(2, '0')}`
  return { from, to }
})

watch([() => store.current, rangeMonths, groupBy], load, { immediate: true })

async function load() {
  if (!store.current) return
  loading.value = true
  try {
    const { from, to } = range.value
    const [spendingRes, netWorthRes, incomeRes, aomRes] = await Promise.all([
      apiFetch<SpendingReport>(`${store.base}/reports/spending?from=${from}&to=${to}&group_by=${groupBy.value}`),
      apiFetch<NetWorthReport>(`${store.base}/reports/net-worth`),
      apiFetch<IncomeExpenseReport>(`${store.base}/reports/income-expense?from=${from}&to=${to}`),
      apiFetch<{ age_of_money: number | null }>(`${store.base}/reports/age-of-money`),
    ])
    spending.value = spendingRes
    netWorth.value = netWorthRes
    incomeExpense.value = incomeRes
    ageOfMoney.value = aomRes.age_of_money
  } finally {
    loading.value = false
  }
}

function monthLabel(key: string): string {
  const [year, month] = key.split('-').map(Number)
  return new Date(year!, month! - 1, 1).toLocaleDateString('en-AU', { month: 'short' })
}

const topSpending = computed(() => {
  const groups = spending.value?.groups ?? []
  const top = groups.slice(0, 8)
  const rest = groups.slice(8)
  if (rest.length > 0) {
    top.push({ uuid: null, name: `Other (${rest.length})`, amount: rest.reduce((sum, g) => sum + g.amount, 0) })
  }
  return top
})

const maxSpending = computed(() => Math.max(...topSpending.value.map(g => g.amount), 1))

const netWorthWindow = computed(() => (netWorth.value?.months ?? []).slice(-rangeMonths.value))
const currentNet = computed(() => netWorthWindow.value.at(-1)?.net ?? 0)
</script>

<template>
  <div class="mx-auto max-w-5xl p-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-4">
      <h1 class="text-xl font-bold">Reports</h1>
      <div class="flex gap-2">
        <button
          v-for="months in [3, 6, 12]"
          :key="months"
          class="rounded-md border px-3 py-1.5 text-sm"
          :class="rangeMonths === months
            ? 'border-accent-400 bg-accent-400/10 font-medium text-accent-300'
            : 'border-ink-600 text-mist-200 hover:bg-ink-700'"
          @click="rangeMonths = months"
        >
          {{ months }} months
        </button>
      </div>
    </header>

    <div v-if="loading && !spending" class="py-20 text-center text-mist-300">Loading…</div>

    <template v-else-if="spending">
      <!-- KPI row -->
      <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-ink-700 bg-paper-200 p-5 text-ink-800">
          <p class="text-3xl font-bold text-ink-800">
            {{ ageOfMoney === null ? '—' : `${ageOfMoney} days` }}
          </p>
          <p class="mt-1 text-sm text-mist-700">Age of Money (aim for 30+)</p>
        </div>
        <div class="rounded-xl border border-ink-700 bg-paper-200 p-5 text-ink-800">
          <p class="text-3xl font-bold text-ink-800">{{ formatMoney(spending.total, store.current?.currency) }}</p>
          <p class="mt-1 text-sm text-mist-700">Spending, last {{ rangeMonths }} months</p>
        </div>
        <div class="rounded-xl border border-ink-700 bg-paper-200 p-5 text-ink-800">
          <p class="text-3xl font-bold" :class="currentNet < 0 ? 'text-red-700' : 'text-ink-800'">
            {{ formatMoney(currentNet, store.current?.currency) }}
          </p>
          <p class="mt-1 text-sm text-mist-700">Net worth today</p>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Spending by category/payee: sorted horizontal bars -->
        <section class="rounded-xl border border-ink-700 bg-paper-200 p-5 text-ink-800">
          <div class="mb-4 flex items-center justify-between">
            <h2 class="font-semibold">Spending by {{ groupBy }}</h2>
            <select v-model="groupBy" class="rounded-md border border-paper-400 bg-paper-50 px-2 py-1 text-sm">
              <option value="category">Category</option>
              <option value="payee">Payee</option>
            </select>
          </div>
          <p v-if="topSpending.length === 0" class="py-8 text-center text-sm text-mist-700">No spending in this range.</p>
          <div v-else class="space-y-2.5">
            <div v-for="group in topSpending" :key="group.name">
              <div class="mb-0.5 flex justify-between text-sm">
                <span class="text-ink-700">{{ group.name }}</span>
                <span class="font-medium text-ink-800">{{ formatMoney(group.amount, store.current?.currency) }}</span>
              </div>
              <div class="h-2.5 overflow-hidden rounded-full bg-paper-50">
                <div
                  class="h-full rounded-full"
                  :style="{ width: `${(group.amount / maxSpending) * 100}%`, background: SERIES_1 }"
                />
              </div>
            </div>
          </div>
        </section>

        <!-- Monthly spending trend -->
        <section class="rounded-xl border border-ink-700 bg-paper-200 p-5 text-ink-800">
          <h2 class="mb-4 font-semibold">Monthly spending</h2>
          <ChartsColumnChart
            :labels="spending.monthly.map(m => monthLabel(m.month))"
            :series="[{ name: 'Spending', color: SERIES_1, values: spending.monthly.map(m => m.amount) }]"
            :currency="store.current?.currency"
          />
        </section>

        <!-- Income vs expense -->
        <section class="rounded-xl border border-ink-700 bg-paper-200 p-5 text-ink-800">
          <h2 class="mb-4 font-semibold">Income vs expense</h2>
          <ChartsColumnChart
            v-if="incomeExpense"
            :labels="incomeExpense.months.map(m => monthLabel(m.month))"
            :series="[
              { name: 'Income', color: SERIES_1, values: incomeExpense.months.map(m => m.income) },
              { name: 'Expense', color: SERIES_2, values: incomeExpense.months.map(m => m.expense) },
            ]"
            :currency="store.current?.currency"
          />
        </section>

        <!-- Net worth -->
        <section class="rounded-xl border border-ink-700 bg-paper-200 p-5 text-ink-800">
          <h2 class="mb-4 font-semibold">Net worth</h2>
          <p v-if="netWorthWindow.length === 0" class="py-8 text-center text-sm text-mist-700">No data yet.</p>
          <ChartsLineChart
            v-else
            :labels="netWorthWindow.map(m => monthLabel(m.month))"
            :values="netWorthWindow.map(m => m.net)"
            :color="SERIES_1"
            :currency="store.current?.currency"
          />
          <details v-if="netWorthWindow.length" class="mt-3">
            <summary class="cursor-pointer text-xs text-mist-700 hover:text-ink-700">View data</summary>
            <table class="mt-2 w-full text-xs">
              <thead>
                <tr class="text-left text-mist-700">
                  <th class="py-1 pr-2 font-normal">Month</th>
                  <th class="py-1 pr-2 text-right font-normal">Assets</th>
                  <th class="py-1 pr-2 text-right font-normal">Debts</th>
                  <th class="py-1 text-right font-normal">Net</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in netWorthWindow" :key="m.month" class="border-t border-paper-300">
                  <td class="py-1 pr-2 text-ink-700">{{ m.month }}</td>
                  <td class="py-1 pr-2 text-right">{{ formatMoney(m.assets, store.current?.currency) }}</td>
                  <td class="py-1 pr-2 text-right">{{ formatMoney(m.debts, store.current?.currency) }}</td>
                  <td class="py-1 text-right font-medium">{{ formatMoney(m.net, store.current?.currency) }}</td>
                </tr>
              </tbody>
            </table>
          </details>
        </section>
      </div>
    </template>
  </div>
</template>
