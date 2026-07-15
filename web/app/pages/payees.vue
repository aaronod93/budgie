<script setup lang="ts">
definePageMeta({ middleware: 'auth', layout: 'app' })

interface PayeeRow {
  uuid: string
  name: string
  icon: string | null
  transfer_account_uuid: string | null
  default_category: { uuid: string, name: string } | null
  inflow_total: number | null
  outflow_total: number | null
}

const store = useBudgetStore()

const payees = ref<PayeeRow[]>([])
const loading = ref(false)
const error = ref('')
const renaming = ref<string | null>(null)
const renameValue = ref('')
const merging = ref<PayeeRow | null>(null)
const mergeInto = ref('')
const newName = ref('')
const newIcon = ref<string | null>(null)
const adding = ref(false)
const mergeBusy = ref(false)

const regular = computed(() => payees.value.filter(p => !p.transfer_account_uuid))
const totalIn = computed(() => regular.value.reduce((sum, p) => sum + (p.inflow_total ?? 0), 0))
const totalOut = computed(() => regular.value.reduce((sum, p) => sum + (p.outflow_total ?? 0), 0))

watch(() => store.current, load, { immediate: true })

async function load() {
  if (!store.current) return
  loading.value = true
  try {
    payees.value = (await apiFetch<{ data: PayeeRow[] }>(`${store.base}/payees`)).data
  } finally {
    loading.value = false
  }
}

async function rename(payee: PayeeRow) {
  renaming.value = null
  if (!renameValue.value.trim() || renameValue.value === payee.name) return
  await run(() => apiFetch(`${store.base}/payees/${payee.uuid}`, {
    method: 'PATCH',
    body: { name: renameValue.value.trim() },
  }))
}

async function setIcon(payee: PayeeRow, icon: string) {
  const trimmed = icon.trim()
  if (trimmed === (payee.icon ?? '')) return
  await run(() => apiFetch(`${store.base}/payees/${payee.uuid}`, {
    method: 'PATCH',
    body: { icon: trimmed || null },
  }))
}

async function addPayee() {
  const name = newName.value.trim()
  if (!name || adding.value) return
  adding.value = true
  try {
    await run(() => apiFetch(`${store.base}/payees`, {
      method: 'POST',
      body: { name, icon: newIcon.value || null },
    }))
    newName.value = ''
    newIcon.value = null
  } finally {
    adding.value = false
  }
}

async function setDefaultCategory(payee: PayeeRow, categoryUuid: string) {
  await run(() => apiFetch(`${store.base}/payees/${payee.uuid}`, {
    method: 'PATCH',
    body: { default_category_id: categoryUuid === 'none' ? null : categoryUuid },
  }))
}

async function submitMerge() {
  if (!merging.value || !mergeInto.value || mergeBusy.value) return
  mergeBusy.value = true
  try {
    await run(() => apiFetch(`${store.base}/payees/${merging.value!.uuid}/merge`, {
      method: 'POST',
      body: { into_payee_id: mergeInto.value },
    }))
    merging.value = null
  } finally {
    mergeBusy.value = false
  }
}

async function run(action: () => Promise<unknown>) {
  error.value = ''
  try {
    await action()
    await load()
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'That did not work.'
  }
}
</script>

<template>
  <div class="mx-auto max-w-3xl p-6">
    <h1 class="mb-1 text-xl font-bold">Payees</h1>
    <p class="mb-6 text-sm text-mist-700">
      Set a default category to auto-categorise future transactions; merge duplicates to tidy history.
    </p>

    <p v-if="error" class="mb-4 bg-red-100 px-4 py-2 text-sm text-red-700">{{ error }}</p>

    <!-- Add payee -->
    <form class="mb-4 flex flex-wrap items-center gap-2" @submit.prevent="addPayee">
      <UiEmojiPicker v-model="newIcon" />
      <input
        v-model="newName"
        placeholder="New payee name…"
        class="min-w-0 flex-1 border border-paper-400 bg-paper-50 px-3 py-2 text-sm text-ink-800"
        @keydown.enter.prevent="addPayee"
      >
      <UiButton type="submit" :loading="adding" :disabled="!newName.trim()">
        Add payee
      </UiButton>
    </form>

    <div class="overflow-x-auto border border-paper-300 bg-paper-200 text-ink-800">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-paper-300 text-left text-xs uppercase tracking-wide text-mist-700">
            <th class="w-14 px-4 py-3" title="Icon">Icon</th>
            <th class="px-4 py-3">Payee</th>
            <th class="w-64 px-4 py-3">Default category</th>
            <th class="w-28 px-4 py-3 text-right" title="Total money received from this payee">In</th>
            <th class="w-28 px-4 py-3 text-right" title="Total money paid to this payee">Out</th>
            <th class="w-20 px-4 py-3" />
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading"><td colspan="6" class="px-4 py-6 text-center text-mist-700">Loading…</td></tr>
          <tr v-else-if="regular.length === 0"><td colspan="6" class="px-4 py-6 text-center text-mist-700">No payees yet.</td></tr>
          <tr v-for="payee in regular" :key="payee.uuid" class="border-b border-paper-300 hover:bg-paper-100">
            <td class="px-4 py-2">
              <UiEmojiPicker
                :model-value="payee.icon"
                @update:model-value="setIcon(payee, $event)"
              />
            </td>
            <td class="px-4 py-2">
              <input
                v-if="renaming === payee.uuid"
                v-model="renameValue"
                class=" border border-accent-400 bg-paper-50 px-2 py-0.5"
                autofocus
                @keydown.enter.prevent="rename(payee)"
                @keydown.esc="renaming = null"
                @blur="rename(payee)"
              >
              <button v-else class=" px-1 py-0.5 hover:bg-paper-100 hover:text-accent-600" @click="renaming = payee.uuid; renameValue = payee.name">
                {{ payee.name }}
              </button>
            </td>
            <td class="px-4 py-2">
              <UiSelect
                size="sm"
                :model-value="payee.default_category?.uuid ?? 'none'"
                @update:model-value="setDefaultCategory(payee, $event)"
              >
                <option value="none">—</option>
                <optgroup v-for="group in store.groups" :key="group.uuid" :label="group.name">
                  <option v-for="category in group.categories" :key="category.uuid" :value="category.uuid">
                    {{ category.icon ? category.icon + ' ' : '' }}{{ category.name }}
                  </option>
                </optgroup>
              </UiSelect>
            </td>
            <td class="px-4 py-2 text-right tabular-nums text-emerald-700">
              {{ payee.inflow_total ? formatMoney(payee.inflow_total, store.current?.currency) : '—' }}
            </td>
            <td class="px-4 py-2 text-right tabular-nums text-ink-700">
              {{ payee.outflow_total ? formatMoney(payee.outflow_total, store.current?.currency) : '—' }}
            </td>
            <td class="px-4 py-2 text-right">
              <button
                class="text-xs text-mist-700 hover:text-accent-600 hover:underline"
                @click="merging = payee; mergeInto = ''"
              >
                Merge…
              </button>
            </td>
          </tr>
        </tbody>
        <tfoot v-if="!loading && regular.length">
          <tr class="border-t border-paper-300 text-xs uppercase tracking-wide text-mist-700">
            <td colspan="3" class="px-4 py-3 font-semibold">Totals across {{ regular.length }} payees</td>
            <td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ formatMoney(totalIn, store.current?.currency) }}</td>
            <td class="px-4 py-3 text-right tabular-nums text-ink-700">{{ formatMoney(totalOut, store.current?.currency) }}</td>
            <td />
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- Merge modal -->
    <div v-if="merging" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-sm bg-paper-200 p-6 text-ink-800 shadow-xl">
        <h2 class="mb-1 text-lg font-semibold">Merge "{{ merging.name }}"</h2>
        <p class="mb-4 text-sm text-mist-700">
          All of its transactions move to the payee you choose, and "{{ merging.name }}" is removed.
        </p>
        <form class="space-y-4" @submit.prevent="submitMerge">
          <UiSelect v-model="mergeInto">
            <option value="" disabled>Merge into…</option>
            <option
              v-for="payee in regular.filter(p => p.uuid !== merging?.uuid)"
              :key="payee.uuid"
              :value="payee.uuid"
            >
              {{ payee.icon ? payee.icon + ' ' : '' }}{{ payee.name }}
            </option>
          </UiSelect>
          <div class="flex justify-end gap-2">
            <UiButton variant="ghost" :disabled="mergeBusy" @click="merging = null">Cancel</UiButton>
            <UiButton type="submit" :loading="mergeBusy" :disabled="!mergeInto">Merge</UiButton>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
