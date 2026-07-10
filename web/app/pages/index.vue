<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const store = useBudgetStore()
const name = ref('My Budget')
const error = ref('')
const busy = ref(false)

onMounted(async () => {
  await store.init()
  if (store.current) await navigateTo('/budget')
})

async function create() {
  error.value = ''
  busy.value = true
  try {
    await store.createBudget(name.value)
    await navigateTo('/budget')
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Could not create the budget.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center p-4">
    <div v-if="store.initialized && !store.current" class="w-full max-w-sm rounded-xl bg-white p-8 shadow">
      <h1 class="mb-1 text-2xl font-bold text-emerald-700">Welcome to Budgie</h1>
      <p class="mb-6 text-sm text-slate-500">Name your first budget to get started.</p>
      <form class="space-y-4" @submit.prevent="create">
        <input v-model="name" required class="w-full rounded-md border border-slate-300 px-3 py-2">
        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
        <button
          type="submit"
          :disabled="busy"
          class="w-full rounded-md bg-emerald-600 px-4 py-2 font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
        >
          {{ busy ? 'Creating…' : 'Create budget' }}
        </button>
      </form>
    </div>
    <p v-else class="text-slate-400">Loading…</p>
  </div>
</template>
