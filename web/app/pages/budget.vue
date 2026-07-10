<script setup lang="ts">
import type { MonthCategory } from '~/stores/budget'

definePageMeta({ middleware: 'auth', layout: 'app' })

const store = useBudgetStore()

const editing = ref<string | null>(null)
const editValue = ref('')
const moveTarget = ref<MonthCategory | null>(null)
const moveForm = reactive({ to: 'rta', amount: '' })
const error = ref('')

const monthLabel = computed(() => {
  if (!store.month) return ''
  const [year, month] = store.month.month.split('-').map(Number)
  return new Date(year!, month! - 1, 1).toLocaleDateString('en-AU', { month: 'long', year: 'numeric' })
})

watch(() => store.current, loadWhenReady, { immediate: true })

async function loadWhenReady() {
  if (store.current && !store.month) await store.loadMonth()
}

function startEdit(category: MonthCategory) {
  editing.value = category.uuid
  editValue.value = centsToInput(category.assigned)
}

async function commitEdit(category: MonthCategory) {
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
  return 'bg-slate-100 text-slate-500'
}
</script>

<template>
  <div class="mx-auto max-w-5xl p-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-4">
      <div class="flex items-center gap-3">
        <button class="rounded-md border border-slate-300 px-2.5 py-1 hover:bg-white" @click="store.shiftMonth(-1)">‹</button>
        <h1 class="w-44 text-center text-xl font-bold">{{ monthLabel }}</h1>
        <button class="rounded-md border border-slate-300 px-2.5 py-1 hover:bg-white" @click="store.shiftMonth(1)">›</button>
      </div>

      <div
        v-if="store.month"
        class="rounded-lg px-5 py-2 text-center"
        :class="store.month.ready_to_assign >= 0 ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white'"
      >
        <p class="text-lg font-bold leading-tight">{{ formatMoney(store.month.ready_to_assign, store.current?.currency) }}</p>
        <p class="text-xs opacity-80">Ready to Assign</p>
      </div>
    </header>

    <p v-if="error" class="mb-4 rounded-md bg-red-50 px-4 py-2 text-sm text-red-700">{{ error }}</p>

    <p
      v-if="store.month && store.month.credit_overspend > 0"
      class="mb-4 rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-800"
    >
      {{ formatMoney(store.month.credit_overspend, store.current?.currency) }} of credit card spending
      isn't covered by envelopes — it will become card debt unless you assign money to those categories.
    </p>

    <div v-if="store.month" class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400">
            <th class="px-4 py-3">Category</th>
            <th class="w-32 px-4 py-3 text-right">Assigned</th>
            <th class="w-32 px-4 py-3 text-right">Activity</th>
            <th class="w-36 px-4 py-3 text-right">Available</th>
            <th class="w-10" />
          </tr>
        </thead>
        <tbody>
          <template v-for="group in store.month.groups" :key="group.uuid">
            <tr class="border-b border-slate-100 bg-slate-50">
              <td colspan="5" class="px-4 py-2 font-semibold text-slate-700">{{ group.name }}</td>
            </tr>
            <tr
              v-for="category in group.categories"
              :key="category.uuid"
              class="border-b border-slate-100 hover:bg-slate-50"
            >
              <td class="px-4 py-2">{{ category.name }}</td>
              <td class="px-4 py-2 text-right">
                <input
                  v-if="editing === category.uuid"
                  ref="assignInput"
                  v-model="editValue"
                  inputmode="decimal"
                  class="w-24 rounded border border-emerald-400 px-2 py-0.5 text-right focus:outline-none"
                  autofocus
                  @keydown.enter.prevent="commitEdit(category)"
                  @keydown.esc="editing = null"
                  @blur="commitEdit(category)"
                >
                <button
                  v-else
                  class="rounded px-2 py-0.5 hover:bg-emerald-50 hover:text-emerald-700"
                  @click="startEdit(category)"
                >
                  {{ formatMoney(category.assigned, store.current?.currency) }}
                </button>
              </td>
              <td class="px-4 py-2 text-right text-slate-500">
                {{ formatMoney(category.activity, store.current?.currency) }}
              </td>
              <td class="px-4 py-2 text-right">
                <span class="inline-block min-w-20 rounded-full px-2.5 py-0.5 font-medium" :class="availableClass(category.available)">
                  {{ formatMoney(category.available, store.current?.currency) }}
                </span>
              </td>
              <td class="pr-3 text-right">
                <button
                  class="rounded px-1.5 py-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                  title="Move money"
                  @click="openMove(category)"
                >⇄</button>
              </td>
            </tr>
          </template>
        </tbody>
        <tfoot>
          <tr class="bg-slate-50 font-semibold">
            <td class="px-4 py-2">Total</td>
            <td class="px-4 py-2 text-right">{{ formatMoney(store.month.assigned_total, store.current?.currency) }}</td>
            <td class="px-4 py-2 text-right">{{ formatMoney(store.month.activity_total, store.current?.currency) }}</td>
            <td class="px-4 py-2 text-right">{{ formatMoney(store.month.available_total, store.current?.currency) }}</td>
            <td />
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- Move money modal -->
    <div v-if="moveTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-sm rounded-xl bg-white p-6 shadow-xl">
        <h2 class="mb-1 text-lg font-semibold">Move money</h2>
        <p class="mb-4 text-sm text-slate-500">
          From <strong>{{ moveTarget.name }}</strong>
          ({{ formatMoney(moveTarget.available, store.current?.currency) }} available)
        </p>
        <form class="space-y-4" @submit.prevent="submitMove">
          <div>
            <label class="mb-1 block text-sm font-medium">To</label>
            <select v-model="moveForm.to" class="w-full rounded-md border border-slate-300 px-3 py-2">
              <option value="rta">Ready to Assign</option>
              <template v-for="group in store.month?.groups" :key="group.uuid">
                <optgroup :label="group.name">
                  <option
                    v-for="category in group.categories.filter(c => c.uuid !== moveTarget?.uuid)"
                    :key="category.uuid"
                    :value="category.uuid"
                  >
                    {{ category.name }}
                  </option>
                </optgroup>
              </template>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium">Amount</label>
            <input v-model="moveForm.amount" inputmode="decimal" required class="w-full rounded-md border border-slate-300 px-3 py-2">
          </div>
          <div class="flex justify-end gap-2">
            <button type="button" class="rounded-md px-4 py-2 text-slate-600 hover:bg-slate-100" @click="moveTarget = null">Cancel</button>
            <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 font-medium text-white hover:bg-emerald-700">Move</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
