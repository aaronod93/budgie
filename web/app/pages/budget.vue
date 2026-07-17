<script setup lang="ts">
import type { MonthCategory, MonthGroup } from '~/stores/budget'

definePageMeta({ middleware: 'auth', layout: 'app' })

const store = useBudgetStore()
const sidebar = useSidebar()

const editing = ref<string | null>(null)
const editValue = ref('')
const moveTarget = ref<MonthCategory | null>(null)
const moveForm = reactive({ to: 'rta', amount: '' })
const filter = ref<'all' | 'underfunded' | 'available'>('all')
const error = ref('')
const selectedUuid = ref<string | null>(null)

// --- Target slide-over ---
const editorOpen = ref(false)
const editorCategory = ref<MonthCategory | null>(null)
const editorBusy = ref(false)
const sidebarWasCollapsed = ref(false)
const targetForm = reactive({
  type: 'monthly_builder',
  amount: '',
  cadence: 'month',
  recurrence: 'forever' as 'forever' | 'until' | 'times',
  starts_on: '',
  ends_on: '',
  repeat_times: '',
  target_date: '',
})

// --- Snooze controls ---
const snoozeBusy = ref(false)
const snoozeUntil = ref('')
const snoozeTimes = ref('')

const visibleGroups = computed(() => {
  const groups = store.month?.groups ?? []
  if (filter.value === 'all') return groups
  return groups
    .map(group => ({
      ...group,
      categories: group.categories.filter(category =>
        filter.value === 'underfunded'
          ? (category.target?.underfunded ?? 0) > 0 || category.available < 0
          : category.available > 0),
    }))
    .filter(group => group.categories.length > 0)
})

const selectedCategory = computed<MonthCategory | null>(() => {
  if (!selectedUuid.value) return null
  for (const group of store.month?.groups ?? []) {
    const found = group.categories.find(c => c.uuid === selectedUuid.value)
    if (found) return found
  }
  return null
})

// Derivable summary: available = leftover + assigned + activity.
const leftOver = computed(() => {
  const m = store.month
  return m ? m.available_total - m.assigned_total - m.activity_total : 0
})

function groupTotal(group: MonthGroup, field: 'assigned' | 'activity' | 'available'): number {
  return group.categories.reduce((sum, category) => sum + category[field], 0)
}

const monthLabel = computed(() => {
  if (!store.month) return ''
  const [year, month] = store.month.month.split('-').map(Number)
  return new Date(year!, month! - 1, 1).toLocaleDateString('en-AU', { month: 'long', year: 'numeric' })
})

watch(() => store.current, loadWhenReady, { immediate: true })

async function loadWhenReady() {
  if (store.current && !store.month) await store.loadMonth()
}

// --- Assigning (typable on first click) ---

function startEdit(category: MonthCategory, event: FocusEvent) {
  editing.value = category.uuid
  editValue.value = centsToInput(category.assigned)
  nextTick(() => (event.target as HTMLInputElement).select())
}

async function commitEdit(category: MonthCategory) {
  if (editing.value !== category.uuid) return
  const cents = parseMoney(editValue.value) ?? 0
  editing.value = null
  if (cents === category.assigned) return
  error.value = ''
  try {
    await store.assign(category.uuid, cents)
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Could not assign.'
  }
}

function openMove(category: MonthCategory) {
  moveTarget.value = category
  moveForm.to = 'rta'
  moveForm.amount = centsToInput(Math.max(category.available, 0))
}

async function submitMove() {
  if (!moveTarget.value) return
  const amount = parseMoney(moveForm.amount)
  if (!amount || amount <= 0) return
  error.value = ''
  try {
    await store.moveMoney(
      moveTarget.value.uuid,
      moveForm.to === 'rta' ? null : moveForm.to,
      amount,
    )
    moveTarget.value = null
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Could not move money.'
  }
}

function availableClass(cents: number): string {
  if (cents > 0) return 'bg-emerald-100 text-emerald-800'
  if (cents < 0) return 'bg-red-100 text-red-700'
  return 'bg-paper-100 text-mist-700'
}

// --- Target slide-over ---

function openEditor(category: MonthCategory) {
  editorCategory.value = category
  const target = category.target
  targetForm.type = target?.type ?? 'monthly_builder'
  targetForm.amount = target ? centsToInput(target.amount) : ''
  targetForm.cadence = target?.cadence ?? 'month'
  targetForm.recurrence = target?.ends_on ? 'until' : (target?.repeat_times ? 'times' : 'forever')
  targetForm.starts_on = target?.starts_on ?? ''
  targetForm.ends_on = target?.ends_on ?? ''
  targetForm.repeat_times = target?.repeat_times ? String(target.repeat_times) : ''
  targetForm.target_date = target?.target_date ?? ''
  sidebarWasCollapsed.value = sidebar.collapsed.value
  sidebar.collapse()
  editorOpen.value = true
}

function closeEditor() {
  editorOpen.value = false
  editorCategory.value = null
  if (!sidebarWasCollapsed.value) sidebar.expand()
}

async function saveTarget() {
  if (!editorCategory.value || editorBusy.value) return
  const amount = parseMoney(targetForm.amount)
  if (!amount || amount <= 0) {
    error.value = 'Enter a target amount.'
    return
  }
  const isBalance = targetForm.type === 'balance_by_date'
  editorBusy.value = true
  error.value = ''
  try {
    await store.setTarget(editorCategory.value.uuid, {
      type: targetForm.type,
      amount,
      target_date: isBalance ? (targetForm.target_date || null) : null,
      cadence: isBalance ? undefined : targetForm.cadence,
      starts_on: isBalance ? null : (targetForm.starts_on || null),
      ends_on: !isBalance && targetForm.recurrence === 'until' ? (targetForm.ends_on || null) : null,
      repeat_times: !isBalance && targetForm.recurrence === 'times' && targetForm.repeat_times
        ? Number(targetForm.repeat_times)
        : null,
    })
    closeEditor()
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Could not save the target.'
  } finally {
    editorBusy.value = false
  }
}

async function deleteTarget() {
  if (!editorCategory.value) return
  try {
    await $confirm('Remove this target?', 'The category keeps its money; only the goal goes away.', 'Remove', 'Cancel')
  } catch { return }
  await store.removeTarget(editorCategory.value.uuid)
  closeEditor()
}

// --- Snooze ---

const currentMonthKey = computed(() => store.month?.month ?? '')

const snoozedThisMonth = computed(() =>
  selectedCategory.value?.target?.snoozed_months.includes(currentMonthKey.value) ?? false)

async function runSnooze(months: string[], until: string | null) {
  const category = selectedCategory.value
  if (!category?.target || snoozeBusy.value) return
  snoozeBusy.value = true
  error.value = ''
  try {
    await store.snoozeTarget(category.uuid, months, until)
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Could not update the snooze.'
  } finally {
    snoozeBusy.value = false
  }
}

function toggleSnoozeThisMonth() {
  const target = selectedCategory.value?.target
  if (!target) return
  const months = snoozedThisMonth.value
    ? target.snoozed_months.filter(m => m !== currentMonthKey.value)
    : [...target.snoozed_months, currentMonthKey.value]
  runSnooze(months, target.snoozed_until)
}

function applySnoozeUntil() {
  const target = selectedCategory.value?.target
  if (!target) return
  runSnooze(target.snoozed_months, snoozeUntil.value || null)
}

function applySnoozeTimes() {
  const target = selectedCategory.value?.target
  const count = Number(snoozeTimes.value)
  if (!target || !count || count < 1) return
  const [y, m] = currentMonthKey.value.split('-').map(Number)
  const extra: string[] = []
  for (let i = 0; i < count; i++) {
    const date = new Date(y!, (m! - 1) + i, 1)
    extra.push(`${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`)
  }
  runSnooze([...new Set([...target.snoozed_months, ...extra])], target.snoozed_until)
  snoozeTimes.value = ''
}

function clearSnoozes() {
  runSnooze([], null)
}

const cadenceLabel: Record<string, string> = {
  week: 'Week', fortnight: 'Fortnight', month: 'Month', quarter: 'Quarter', year: 'Year',
}

async function assignAllUnderfunded() {
  error.value = ''
  try {
    await store.assignUnderfunded()
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Could not assign underfunded.'
  }
}
</script>

<template>
  <div class="p-6">
    <!-- Header: month nav | RTA centered & prominent | actions -->
    <header class="mb-6 grid items-center gap-4 lg:grid-cols-3">
      <div class="flex items-center gap-3">
        <button class="rounded-sm border border-paper-400 px-2.5 py-1 text-ink-700 hover:bg-paper-100" @click="store.shiftMonth(-1)">‹</button>
        <h1 class="w-44 text-center text-xl font-bold">{{ monthLabel }}</h1>
        <button class="rounded-sm border border-paper-400 px-2.5 py-1 text-ink-700 hover:bg-paper-100" @click="store.shiftMonth(1)">›</button>
      </div>

      <div class="flex justify-start">
        <div
          v-if="store.month"
          class="rounded-sm px-8 py-3 text-center"
          :class="store.month.ready_to_assign >= 0 ? 'bg-accent-400 text-ink-900' : 'bg-red-600 text-white'"
        >
          <p class="text-2xl font-bold leading-tight">{{ formatMoney(store.month.ready_to_assign, store.current?.currency) }}</p>
          <p class="text-sm opacity-80">Ready to Assign</p>
        </div>
      </div>

      <div class="flex items-center justify-end gap-3">
        <NuxtLink
          to="/categories"
          class="rounded-sm border border-paper-400 px-3 py-2 text-sm text-ink-700 hover:bg-paper-100"
        >
          Edit categories
        </NuxtLink>
        <button
          v-if="store.month && store.month.underfunded_total > 0"
          class="rounded-sm border border-amber-400 rounded-sm bg-amber-100 px-3 py-2 text-sm font-medium text-amber-700 hover:bg-amber-200"
          @click="assignAllUnderfunded"
        >
          Assign underfunded ({{ formatMoney(store.month.underfunded_total, store.current?.currency) }})
        </button>
      </div>
    </header>

    <div class="mb-4 flex gap-2">
      <button
        v-for="option in ([
          { key: 'all', label: 'All' },
          { key: 'underfunded', label: 'Underfunded' },
          { key: 'available', label: 'Money available' },
        ] as const)"
        :key="option.key"
        class="rounded-sm border px-3 py-1 text-sm"
        :class="filter === option.key
          ? 'border-accent-400 bg-accent-100 font-medium text-accent-600'
          : 'border-paper-400 text-ink-700 hover:bg-paper-100'"
        @click="filter = option.key"
      >
        {{ option.label }}
      </button>
    </div>

    <p v-if="error" class="mb-4 rounded-sm bg-red-100 px-4 py-2 text-sm text-red-700">{{ error }}</p>

    <p
      v-if="store.month && store.month.credit_overspend > 0"
      class="mb-4 rounded-sm bg-amber-100 px-4 py-2 text-sm text-amber-700"
    >
      {{ formatMoney(store.month.credit_overspend, store.current?.currency) }} of credit card spending
      isn't covered by envelopes — it will become card debt unless you assign money to those categories.
    </p>

    <div v-if="!store.month" class="py-16">
      <UiLoading :size="240" label="Loading your budget…" />
    </div>

    <div class="flex items-start gap-6">
      <!-- Category table -->
      <div v-if="store.month" class="min-w-0 flex-1 overflow-x-auto rounded-sm border border-paper-300 bg-white text-ink-800">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-paper-300 text-left text-xs uppercase tracking-wide text-mist-700">
              <th class="px-4 py-3">Category</th>
              <th class="w-32 px-4 py-3 text-right">Assigned</th>
              <th class="w-32 px-4 py-3 text-right">Activity</th>
              <th class="w-36 px-4 py-3 text-right">Available</th>
              <th class="w-10" />
            </tr>
          </thead>
          <tbody>
            <template v-for="group in visibleGroups" :key="group.uuid">
              <tr class="border-b border-paper-300 bg-paper-100 font-semibold text-ink-700">
                <td class="px-4 py-2">{{ group.name }}</td>
                <td class="px-4 py-2 text-right">{{ formatMoney(groupTotal(group, 'assigned'), store.current?.currency) }}</td>
                <td class="px-4 py-2 text-right">{{ formatMoney(groupTotal(group, 'activity'), store.current?.currency) }}</td>
                <td class="px-4 py-2 text-right">{{ formatMoney(groupTotal(group, 'available'), store.current?.currency) }}</td>
                <td />
              </tr>
              <tr
                v-for="category in group.categories"
                :key="category.uuid"
                class="cursor-pointer border-b border-paper-300"
                :class="selectedUuid === category.uuid ? 'bg-mist-200/40' : 'hover:bg-paper-100'"
                @click="selectedUuid = selectedUuid === category.uuid ? null : category.uuid"
              >
                <td class="px-4 py-2">
                  <div class="flex items-center gap-2">
                    <span>{{ category.icon ? category.icon + ' ' : '' }}{{ category.name }}</span>
                    <span
                      v-if="category.target?.snoozed"
                      class="rounded-sm bg-paper-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-mist-700"
                      title="Target snoozed this month"
                    >z</span>
                  </div>
                  <div v-if="category.target" class="mt-1 flex items-center gap-2">
                    <div class="h-1.5 w-32 rounded-sm overflow-hidden bg-paper-300">
                      <div
                        class="h-full"
                        :class="category.target.underfunded > 0 ? 'bg-accent-400' : 'bg-mist-500'"
                        :style="{ width: `${category.target.progress}%` }"
                      />
                    </div>
                    <span v-if="category.target.snoozed" class="text-xs text-mist-700">Snoozed</span>
                    <span v-else-if="category.target.underfunded > 0" class="text-xs text-accent-600">
                      {{ formatMoney(category.target.underfunded, store.current?.currency) }} more needed
                    </span>
                    <span v-else class="text-xs text-mist-700">Funded</span>
                  </div>
                </td>
                <td class="px-4 py-2 text-right" @click.stop>
                  <input
                    :value="editing === category.uuid ? editValue : formatMoney(category.assigned, store.current?.currency)"
                    inputmode="decimal"
                    class="w-24 border border-transparent bg-transparent px-2 py-0.5 text-right hover:border-paper-400 focus:border-ink-500 focus:bg-white focus:outline-none"
                    @focus="startEdit(category, $event)"
                    @input="editValue = ($event.target as HTMLInputElement).value"
                    @keydown.enter.prevent="($event.target as HTMLInputElement).blur()"
                    @keydown.esc="editing = null; ($event.target as HTMLInputElement).blur()"
                    @blur="commitEdit(category)"
                  >
                </td>
                <td class="px-4 py-2 text-right text-mist-700">
                  {{ formatMoney(category.activity, store.current?.currency) }}
                </td>
                <td class="px-4 py-2 text-right">
                  <span class="rounded-sm inline-block min-w-20 px-2.5 py-0.5 font-medium" :class="availableClass(category.available)">
                    {{ formatMoney(category.available, store.current?.currency) }}
                  </span>
                </td>
                <td class="pr-3 text-right" @click.stop>
                  <button
                    class="px-1.5 py-0.5 text-paper-400 hover:bg-paper-100 hover:text-ink-700"
                    title="Move money"
                    @click="openMove(category)"
                  >⇄</button>
                </td>
              </tr>
            </template>
          </tbody>
          <tfoot>
            <tr class="bg-paper-100 font-semibold">
              <td class="px-4 py-2">Total</td>
              <td class="px-4 py-2 text-right">{{ formatMoney(store.month.assigned_total, store.current?.currency) }}</td>
              <td class="px-4 py-2 text-right">{{ formatMoney(store.month.activity_total, store.current?.currency) }}</td>
              <td class="px-4 py-2 text-right">{{ formatMoney(store.month.available_total, store.current?.currency) }}</td>
              <td />
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Right panel: month summary, or selected category's target -->
      <aside v-if="store.month" class="w-80 shrink-0 space-y-4">
        <!-- Selected category -->
        <div v-if="selectedCategory" class="rounded-sm border border-paper-300 bg-white text-ink-800">
          <div class="flex items-center justify-between border-b border-paper-300 px-4 py-3">
            <h2 class="font-semibold">
              {{ selectedCategory.icon ? selectedCategory.icon + ' ' : '' }}{{ selectedCategory.name }}
            </h2>
            <button class="text-mist-700 hover:text-ink-700" title="Close" @click="selectedUuid = null">✕</button>
          </div>

          <div class="space-y-1 px-4 py-3 text-sm">
            <div class="flex justify-between">
              <span class="text-mist-700">Available Balance</span>
              <span class="px-2 font-medium" :class="availableClass(selectedCategory.available)">
                {{ formatMoney(selectedCategory.available, store.current?.currency) }}
              </span>
            </div>
            <div class="flex justify-between">
              <span class="text-mist-700">Assigned This Month</span>
              <span>{{ formatMoney(selectedCategory.assigned, store.current?.currency) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-mist-700">Activity</span>
              <span>{{ formatMoney(selectedCategory.activity, store.current?.currency) }}</span>
            </div>
          </div>

          <!-- Target block -->
          <div class="border-t border-paper-300 px-4 py-3">
            <template v-if="selectedCategory.target">
              <p class="text-xs font-semibold uppercase tracking-wide text-mist-700">Target</p>
              <p class="mt-1 text-sm font-medium">
                <template v-if="selectedCategory.target.type === 'balance_by_date'">
                  Reach {{ formatMoney(selectedCategory.target.amount, store.current?.currency) }}
                  by {{ formatDate(selectedCategory.target.target_date) }}
                </template>
                <template v-else-if="selectedCategory.target.type === 'refill_monthly'">
                  Refill up to {{ formatMoney(selectedCategory.target.amount, store.current?.currency) }}
                  each {{ cadenceLabel[selectedCategory.target.cadence] }}
                </template>
                <template v-else>
                  Set aside another {{ formatMoney(selectedCategory.target.amount, store.current?.currency) }}
                  each {{ cadenceLabel[selectedCategory.target.cadence] }}
                </template>
              </p>

              <!-- Progress ring -->
              <div class="mt-3 flex items-center justify-center">
                <div
                  class="flex h-24 w-24 items-center justify-center rounded-full"
                  :style="{
                    background: `conic-gradient(${selectedCategory.target.underfunded > 0 ? '#e3854e' : '#6f93a8'} ${selectedCategory.target.progress * 3.6}deg, #d8d6cf 0deg)`,
                  }"
                >
                  <div class="flex h-19 w-19 items-center justify-center rounded-full bg-white text-lg font-bold" style="height: 4.75rem; width: 4.75rem">
                    {{ selectedCategory.target.progress }}%
                  </div>
                </div>
              </div>

              <p
                v-if="!selectedCategory.target.snoozed && selectedCategory.target.underfunded > 0"
                class="mt-3 rounded-sm bg-amber-100 px-3 py-2 text-center text-sm text-amber-800"
              >
                Assign <strong>{{ formatMoney(selectedCategory.target.underfunded, store.current?.currency) }} more</strong> to meet your target
              </p>
              <p v-else-if="selectedCategory.target.snoozed" class="mt-3 rounded-sm bg-paper-100 px-3 py-2 text-center text-sm text-mist-700">
                Snoozed for {{ monthLabel }}
              </p>

              <div class="mt-3 space-y-1 text-sm">
                <div class="flex justify-between">
                  <span class="text-mist-700">Needed This Month</span>
                  <span>{{ formatMoney(selectedCategory.target.needed_this_month, store.current?.currency) }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-mist-700">Assigned So Far</span>
                  <span>{{ formatMoney(selectedCategory.assigned, store.current?.currency) }}</span>
                </div>
                <div class="flex justify-between border-t border-paper-200 pt-1 font-medium">
                  <span>To Go</span>
                  <span>{{ formatMoney(selectedCategory.target.underfunded, store.current?.currency) }}</span>
                </div>
              </div>

              <UiButton class="mt-3 w-full" variant="secondary" size="sm" @click="openEditor(selectedCategory)">
                Edit Target
              </UiButton>

              <!-- Snooze -->
              <div class="mt-4 border-t border-paper-200 pt-3">
                <label class="flex cursor-pointer items-center justify-between text-sm">
                  <span>Snooze target for this month</span>
                  <button
                    type="button"
                    class="relative h-5 w-9 rounded-sm transition-colors"
                    :class="snoozedThisMonth ? 'bg-accent-400' : 'bg-paper-300'"
                    :disabled="snoozeBusy"
                    role="switch"
                    :aria-checked="snoozedThisMonth"
                    @click="toggleSnoozeThisMonth"
                  >
                    <span
                      class="absolute top-0.5 h-4 w-4 rounded-sm bg-white transition-all"
                      :class="snoozedThisMonth ? 'left-4.5' : 'left-0.5'"
                      :style="{ left: snoozedThisMonth ? '1.125rem' : '0.125rem' }"
                    />
                  </button>
                </label>

                <details class="mt-2 text-sm">
                  <summary class="cursor-pointer text-xs text-mist-700 hover:text-ink-700">More snooze options</summary>
                  <div class="mt-2 space-y-3">
                    <div>
                      <label class="mb-1 block text-xs text-mist-700">Snooze until</label>
                      <div class="flex gap-2">
                        <UiDateField v-model="snoozeUntil" size="sm" />
                        <UiButton size="sm" variant="secondary" :disabled="!snoozeUntil || snoozeBusy" @click="applySnoozeUntil">Set</UiButton>
                      </div>
                      <p v-if="selectedCategory.target.snoozed_until" class="mt-1 text-xs text-mist-700">
                        Currently snoozed until {{ formatDate(selectedCategory.target.snoozed_until) }}
                      </p>
                    </div>
                    <div>
                      <label class="mb-1 block text-xs text-mist-700">Snooze the next X months</label>
                      <div class="flex gap-2">
                        <input
                          v-model="snoozeTimes"
                          type="number"
                          min="1"
                          max="24"
                          placeholder="e.g. 3"
                          class="w-24 rounded-sm border border-paper-400 bg-white px-2 py-1 text-sm focus:border-ink-500 focus:outline-none"
                        >
                        <UiButton size="sm" variant="secondary" :disabled="!snoozeTimes || snoozeBusy" @click="applySnoozeTimes">Snooze</UiButton>
                      </div>
                    </div>
                    <button
                      v-if="selectedCategory.target.snoozed_months.length || selectedCategory.target.snoozed_until"
                      class="text-xs text-red-600 hover:underline"
                      :disabled="snoozeBusy"
                      @click="clearSnoozes"
                    >
                      Clear all snoozes
                    </button>
                  </div>
                </details>
              </div>
            </template>

            <template v-else>
              <p class="text-sm text-mist-700">
                Set a target to plan this category — weekly groceries, quarterly rego, a yearly holiday.
              </p>
              <UiButton class="mt-3 w-full" size="sm" @click="openEditor(selectedCategory)">
                Create Target
              </UiButton>
            </template>
          </div>
        </div>

        <!-- Month summary (nothing selected) -->
        <div v-else class="rounded-sm border border-paper-300 bg-white text-ink-800">
          <h2 class="border-b border-paper-300 px-4 py-3 font-semibold">{{ monthLabel }}'s Summary</h2>
          <div class="space-y-1 px-4 py-3 text-sm">
            <div class="flex justify-between">
              <span class="text-mist-700">Left Over from Last Month</span>
              <span>{{ formatMoney(leftOver, store.current?.currency) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-mist-700">Assigned in {{ monthLabel.split(' ')[0] }}</span>
              <span>{{ formatMoney(store.month.assigned_total, store.current?.currency) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-mist-700">Activity</span>
              <span>{{ formatMoney(store.month.activity_total, store.current?.currency) }}</span>
            </div>
            <div class="flex justify-between border-t border-paper-200 pt-1 font-medium">
              <span>Available</span>
              <span>{{ formatMoney(store.month.available_total, store.current?.currency) }}</span>
            </div>
          </div>
          <div class="space-y-1 border-t border-paper-300 px-4 py-3 text-sm">
            <div class="flex justify-between">
              <span class="text-mist-700">Income this month</span>
              <span>{{ formatMoney(store.month.income, store.current?.currency) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-mist-700">Still underfunded</span>
              <span :class="store.month.underfunded_total > 0 ? 'text-accent-600' : ''">
                {{ formatMoney(store.month.underfunded_total, store.current?.currency) }}
              </span>
            </div>
          </div>
          <p class="border-t border-paper-300 px-4 py-3 text-xs text-mist-700">
            Select a category to see its target and snooze options.
          </p>
        </div>
      </aside>
    </div>

    <!-- Target editor slide-over -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition duration-200"
        enter-from-class="translate-x-full"
        leave-active-class="transition duration-200"
        leave-to-class="translate-x-full"
      >
        <div v-if="editorOpen" class="fixed inset-y-0 right-0 z-50 w-full max-w-md border-l border-paper-300 bg-white shadow-2xl">
          <div class="flex h-full flex-col">
            <div class="flex items-center justify-between border-b border-paper-300 px-5 py-4">
              <h2 class="text-lg font-semibold">
                {{ editorCategory?.target ? 'Edit Target' : 'Create Target' }}
                <span class="ml-1 text-sm font-normal text-mist-700">— {{ editorCategory?.name }}</span>
              </h2>
              <button class="text-mist-700 hover:text-ink-700" title="Close" @click="closeEditor">✕</button>
            </div>

            <form class="flex-1 space-y-5 overflow-y-auto px-5 py-4" @submit.prevent="saveTarget">
              <div>
                <label class="mb-1 block text-sm font-medium">Type</label>
                <UiSelect v-model="targetForm.type">
                  <option value="refill_monthly">Refill available up to… (needed for spending)</option>
                  <option value="monthly_builder">Set aside another… (savings builder)</option>
                  <option value="balance_by_date">Reach a balance by a date</option>
                </UiSelect>
              </div>

              <div>
                <label class="mb-1 block text-sm font-medium">Amount</label>
                <input
                  v-model="targetForm.amount"
                  inputmode="decimal"
                  required
                  placeholder="0.00"
                  class="w-full rounded-sm border border-paper-400 bg-white px-3 py-2 focus:border-ink-500 focus:outline-none"
                >
              </div>

              <template v-if="targetForm.type !== 'balance_by_date'">
                <div>
                  <label class="mb-1 block text-sm font-medium">Every</label>
                  <UiSelect v-model="targetForm.cadence">
                    <option value="week">Week</option>
                    <option value="fortnight">Fortnight</option>
                    <option value="month">Month</option>
                    <option value="quarter">Quarter</option>
                    <option value="year">Year</option>
                  </UiSelect>
                  <p class="mt-1 text-xs text-mist-700">
                    Weekly and fortnightly targets add up their occurrences inside each month;
                    quarterly and yearly targets spread evenly across the period.
                  </p>
                </div>

                <div>
                  <label class="mb-1 block text-sm font-medium">
                    {{ targetForm.type === 'refill_monthly' ? 'First amount needed by' : 'Starting on' }}
                  </label>
                  <UiDateField v-model="targetForm.starts_on" />
                  <p class="mt-1 text-xs text-mist-700">
                    <template v-if="targetForm.type === 'refill_monthly'">
                      The date the first amount is due — saving builds up to it, then repeats each
                      {{ targetForm.cadence }} after that. Leave empty to start immediately.
                    </template>
                    <template v-else>
                      Anchors the cycle (e.g. weekly from a Friday). Leave empty to start immediately.
                    </template>
                  </p>
                </div>

                <div>
                  <label class="mb-1 block text-sm font-medium">Repeats</label>
                  <div class="space-y-2 text-sm">
                    <label class="flex items-center gap-2">
                      <input v-model="targetForm.recurrence" type="radio" value="forever" class="accent-accent-500">
                      Forever
                    </label>
                    <label class="flex items-center gap-2">
                      <input v-model="targetForm.recurrence" type="radio" value="until" class="accent-accent-500">
                      Until an end date
                    </label>
                    <UiDateField v-if="targetForm.recurrence === 'until'" v-model="targetForm.ends_on" size="sm" />
                    <label class="flex items-center gap-2">
                      <input v-model="targetForm.recurrence" type="radio" value="times" class="accent-accent-500">
                      A set number of times
                    </label>
                    <input
                      v-if="targetForm.recurrence === 'times'"
                      v-model="targetForm.repeat_times"
                      type="number"
                      min="1"
                      max="600"
                      placeholder="e.g. 6"
                      class="w-28 rounded-sm border border-paper-400 bg-white px-2 py-1.5 text-sm focus:border-ink-500 focus:outline-none"
                    >
                    <p v-if="targetForm.recurrence !== 'forever' && !targetForm.starts_on" class="text-xs text-amber-700">
                      Set a start date so the run has an anchor.
                    </p>
                  </div>
                </div>
              </template>

              <div v-else>
                <label class="mb-1 block text-sm font-medium">By date</label>
                <UiDateField v-model="targetForm.target_date" required />
              </div>
            </form>

            <div class="flex items-center justify-between border-t border-paper-300 px-5 py-4">
              <button
                v-if="editorCategory?.target"
                type="button"
                class="text-sm text-red-600 hover:underline"
                @click="deleteTarget"
              >
                Remove target
              </button>
              <span v-else />
              <div class="flex gap-2">
                <UiButton variant="ghost" :disabled="editorBusy" @click="closeEditor">Cancel</UiButton>
                <UiButton :loading="editorBusy" @click="saveTarget">Save Target</UiButton>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- Move money modal -->
    <div v-if="moveTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-sm rounded-sm border border-paper-300 bg-white p-6 text-ink-800 shadow-xl">
        <h2 class="mb-1 text-lg font-semibold">Move money</h2>
        <p class="mb-4 text-sm text-mist-700">
          From <strong>{{ moveTarget.name }}</strong>
          ({{ formatMoney(moveTarget.available, store.current?.currency) }} available)
        </p>
        <form class="space-y-4" @submit.prevent="submitMove">
          <div>
            <label class="mb-1 block text-sm font-medium">To</label>
            <UiSelect v-model="moveForm.to">
              <option value="rta">Ready to Assign</option>
              <optgroup v-for="group in store.month?.groups" :key="group.uuid" :label="group.name">
                <option
                  v-for="category in group.categories.filter(c => c.uuid !== moveTarget?.uuid)"
                  :key="category.uuid"
                  :value="category.uuid"
                >
                  {{ category.icon ? category.icon + ' ' : '' }}{{ category.name }}
                </option>
              </optgroup>
            </UiSelect>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium">Amount</label>
            <input v-model="moveForm.amount" inputmode="decimal" required class="w-full rounded-sm border border-paper-400 bg-white px-3 py-2 focus:border-ink-500 focus:outline-none">
          </div>
          <div class="flex justify-end gap-2">
            <UiButton variant="ghost" @click="moveTarget = null">Cancel</UiButton>
            <UiButton type="submit">Move</UiButton>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
