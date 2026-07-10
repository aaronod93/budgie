<script setup lang="ts">
definePageMeta({ middleware: 'auth', layout: 'app' })

interface PayeeRow {
  uuid: string
  name: string
  transfer_account_uuid: string | null
  default_category: { uuid: string, name: string } | null
}

const store = useBudgetStore()

const payees = ref<PayeeRow[]>([])
const loading = ref(false)
const error = ref('')
const renaming = ref<string | null>(null)
const renameValue = ref('')
const merging = ref<PayeeRow | null>(null)
const mergeInto = ref('')

const regular = computed(() => payees.value.filter(p => !p.transfer_account_uuid))

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

async function setDefaultCategory(payee: PayeeRow, categoryUuid: string) {
  await run(() => apiFetch(`${store.base}/payees/${payee.uuid}`, {
    method: 'PATCH',
    body: { default_category_id: categoryUuid === 'none' ? null : categoryUuid },
  }))
}

async function submitMerge() {
  if (!merging.value || !mergeInto.value) return
  await run(() => apiFetch(`${store.base}/payees/${merging.value!.uuid}/merge`, {
    method: 'POST',
    body: { into_payee_id: mergeInto.value },
  }))
  merging.value = null
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
    <p class="mb-6 text-sm text-slate-500">
      Set a default category to auto-categorise future transactions; merge duplicates to tidy history.
    </p>

    <p v-if="error" class="mb-4 rounded-md bg-red-50 px-4 py-2 text-sm text-red-700">{{ error }}</p>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400">
            <th class="px-4 py-3">Payee</th>
            <th class="w-64 px-4 py-3">Default category</th>
            <th class="w-20 px-4 py-3" />
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading"><td colspan="3" class="px-4 py-6 text-center text-slate-400">Loading…</td></tr>
          <tr v-else-if="regular.length === 0"><td colspan="3" class="px-4 py-6 text-center text-slate-400">No payees yet.</td></tr>
          <tr v-for="payee in regular" :key="payee.uuid" class="border-b border-slate-100 hover:bg-slate-50">
            <td class="px-4 py-2">
              <input
                v-if="renaming === payee.uuid"
                v-model="renameValue"
                class="rounded border border-emerald-400 px-2 py-0.5"
                autofocus
                @keydown.enter.prevent="rename(payee)"
                @keydown.esc="renaming = null"
                @blur="rename(payee)"
              >
              <button v-else class="rounded px-1 py-0.5 hover:bg-emerald-50" @click="renaming = payee.uuid; renameValue = payee.name">
                {{ payee.name }}
              </button>
            </td>
            <td class="px-4 py-2">
              <select
                :value="payee.default_category?.uuid ?? 'none'"
                class="w-full rounded-md border border-slate-200 px-2 py-1 text-sm"
                @change="setDefaultCategory(payee, ($event.target as HTMLSelectElement).value)"
              >
                <option value="none">—</option>
                <optgroup v-for="group in store.groups" :key="group.uuid" :label="group.name">
                  <option v-for="category in group.categories" :key="category.uuid" :value="category.uuid">
                    {{ category.name }}
                  </option>
                </optgroup>
              </select>
            </td>
            <td class="px-4 py-2 text-right">
              <button
                class="text-xs text-slate-400 hover:text-emerald-700 hover:underline"
                @click="merging = payee; mergeInto = ''"
              >
                Merge…
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Merge modal -->
    <div v-if="merging" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-sm rounded-xl bg-white p-6 shadow-xl">
        <h2 class="mb-1 text-lg font-semibold">Merge "{{ merging.name }}"</h2>
        <p class="mb-4 text-sm text-slate-500">
          All of its transactions move to the payee you choose, and "{{ merging.name }}" is removed.
        </p>
        <form class="space-y-4" @submit.prevent="submitMerge">
          <select v-model="mergeInto" required class="w-full rounded-md border border-slate-300 px-3 py-2">
            <option value="" disabled>Merge into…</option>
            <option
              v-for="payee in regular.filter(p => p.uuid !== merging?.uuid)"
              :key="payee.uuid"
              :value="payee.uuid"
            >
              {{ payee.name }}
            </option>
          </select>
          <div class="flex justify-end gap-2">
            <button type="button" class="rounded-md px-4 py-2 text-slate-600 hover:bg-slate-100" @click="merging = null">Cancel</button>
            <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 font-medium text-white hover:bg-emerald-700">Merge</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
