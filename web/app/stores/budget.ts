import { defineStore } from 'pinia'

export interface Budget {
  uuid: string
  name: string
  currency: string
  role: 'owner' | 'editor' | 'viewer'
  ready_to_assign_category_uuid: string
}

export interface PendingInvitation {
  uuid: string
  budget_name: string
  invited_by: string
  role: string
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

export interface CategoryTarget {
  type: 'refill_monthly' | 'monthly_builder' | 'balance_by_date'
  amount: number
  target_date: string | null
  underfunded: number
  progress: number
}

export interface MonthCategory {
  uuid: string
  name: string
  is_credit_card_payment: boolean
  assigned: number
  activity: number
  available: number
  target: CategoryTarget | null
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
  credit_overspend: number
  assigned_total: number
  activity_total: number
  available_total: number
  underfunded_total: number
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
  const invitations = ref<PendingInvitation[]>([])
  const liveMessage = ref<string | null>(null)

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

  async function assignUnderfunded(): Promise<void> {
    month.value = await apiFetch<MonthPayload>(
      `${base.value}/months/${monthKey.value}/assign-underfunded`,
      { method: 'POST' },
    )
  }

  async function setTarget(categoryUuid: string, target: { type: string, amount: number, target_date?: string | null }): Promise<void> {
    await apiFetch(`${base.value}/categories/${categoryUuid}/target`, { method: 'PUT', body: target })
    await loadMonth()
  }

  async function removeTarget(categoryUuid: string): Promise<void> {
    await apiFetch(`${base.value}/categories/${categoryUuid}/target`, { method: 'DELETE' })
    await loadMonth()
  }

  async function loadInvitations(): Promise<void> {
    invitations.value = (await apiFetch<{ data: PendingInvitation[] }>('/api/v1/invitations')).data
  }

  async function acceptInvitation(uuid: string): Promise<void> {
    await apiFetch(`/api/v1/invitations/${uuid}/accept`, { method: 'POST' })
    budgets.value = (await apiFetch<{ data: Budget[] }>('/api/v1/budgets')).data
    await loadInvitations()
  }

  async function declineInvitation(uuid: string): Promise<void> {
    await apiFetch(`/api/v1/invitations/${uuid}`, { method: 'DELETE' })
    await loadInvitations()
  }

  /** Another device changed the budget: refresh what's on screen. */
  async function refreshFromLive(description: string | null): Promise<void> {
    liveMessage.value = description
    await Promise.all([loadAccounts(), month.value ? loadMonth() : Promise.resolve()])
    if (description) {
      setTimeout(() => {
        if (liveMessage.value === description) liveMessage.value = null
      }, 5000)
    }
  }

  return {
    budgets, current, accounts, groups, month, monthKey, initialized, base,
    invitations, liveMessage,
    init, selectBudget, createBudget, loadAccounts, loadGroups, addAccount,
    loadMonth, shiftMonth, assign, moveMoney, assignUnderfunded, setTarget, removeTarget,
    loadInvitations, acceptInvitation, declineInvitation, refreshFromLive,
  }
})
