import { defineStore } from 'pinia'

export interface Budget {
  uuid: string
  name: string
  currency: string
  ready_to_assign_category_uuid: string
}

export interface Account {
  uuid: string
  name: string
  type: 'checking' | 'savings' | 'cash' | 'credit' | 'tracking'
  on_budget: boolean
  closed: boolean
  balance: number
  cleared_balance: number
}

export interface CategoryRef {
  uuid: string
  name: string
}

export interface CategoryGroupFull {
  uuid: string
  name: string
  categories: CategoryRef[]
}

export interface MonthCategory {
  uuid: string
  name: string
  assigned: number
  activity: number
  available: number
}

export interface MonthGroup {
  uuid: string
  name: string
  categories: MonthCategory[]
}

export interface MonthPayload {
  month: string
  ready_to_assign: number
  income: number
  assigned_total: number
  activity_total: number
  available_total: number
  groups: MonthGroup[]
}

function currentMonthKey(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

function shiftMonthKey(key: string, delta: number): string {
  const [year, month] = key.split('-').map(Number)
  const date = new Date(year!, month! - 1 + delta, 1)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

export const useBudgetStore = defineStore('budget', () => {
  const budgets = ref<Budget[]>([])
  const current = ref<Budget | null>(null)
  const accounts = ref<Account[]>([])
  const groups = ref<CategoryGroupFull[]>([])
  const month = ref<MonthPayload | null>(null)
  const monthKey = ref(currentMonthKey())
  const initialized = ref(false)

  const base = computed(() => `/api/v1/budgets/${current.value?.uuid}`)

  async function init(): Promise<void> {
    if (initialized.value) return
    budgets.value = (await apiFetch<{ data: Budget[] }>('/api/v1/budgets')).data

    const savedUuid = localStorage.getItem('budgie:budget')
    const budget = budgets.value.find(b => b.uuid === savedUuid) ?? budgets.value[0] ?? null
    if (budget) await selectBudget(budget)

    initialized.value = true
  }

  async function selectBudget(budget: Budget): Promise<void> {
    current.value = budget
    localStorage.setItem('budgie:budget', budget.uuid)
    await Promise.all([loadAccounts(), loadGroups()])
  }

  async function createBudget(name: string): Promise<void> {
    const created = (await apiFetch<{ data: Budget }>('/api/v1/budgets', {
      method: 'POST',
      body: { name },
    })).data
    budgets.value.push(created)
    await selectBudget(created)
  }

  async function loadAccounts(): Promise<void> {
    accounts.value = (await apiFetch<{ data: Account[] }>(`${base.value}/accounts`)).data
  }

  async function loadGroups(): Promise<void> {
    groups.value = (await apiFetch<{ data: CategoryGroupFull[] }>(`${base.value}/category-groups`)).data
  }

  async function addAccount(payload: { name: string, type: string, balance: number }): Promise<void> {
    await apiFetch(`${base.value}/accounts`, { method: 'POST', body: payload })
    await loadAccounts()
  }

  async function loadMonth(key?: string): Promise<void> {
    if (key) monthKey.value = key
    month.value = await apiFetch<MonthPayload>(`${base.value}/months/${monthKey.value}`)
  }

  function shiftMonth(delta: number): Promise<void> {
    return loadMonth(shiftMonthKey(monthKey.value, delta))
  }

  async function assign(categoryUuid: string, amount: number): Promise<void> {
    month.value = await apiFetch<MonthPayload>(
      `${base.value}/months/${monthKey.value}/categories/${categoryUuid}/assign`,
      { method: 'POST', body: { amount } },
    )
  }

  async function moveMoney(from: string | null, to: string | null, amount: number): Promise<void> {
    month.value = await apiFetch<MonthPayload>(
      `${base.value}/months/${monthKey.value}/move-money`,
      { method: 'POST', body: { from_category_id: from, to_category_id: to, amount } },
    )
  }

  return {
    budgets, current, accounts, groups, month, monthKey, initialized, base,
    init, selectBudget, createBudget, loadAccounts, loadGroups, addAccount,
    loadMonth, shiftMonth, assign, moveMoney,
  }
})
