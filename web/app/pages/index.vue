<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const auth = useAuthStore()

async function logout() {
  await auth.logout()
  await navigateTo('/login')
}
</script>

<template>
  <div class="mx-auto max-w-4xl p-6">
    <header class="mb-8 flex items-center justify-between">
      <h1 class="text-2xl font-bold text-emerald-700">Budgie</h1>
      <div class="flex items-center gap-4">
        <span class="text-sm text-slate-600">{{ auth.user?.name }}</span>
        <button
          class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100"
          @click="logout"
        >
          Sign out
        </button>
      </div>
    </header>

    <div class="rounded-xl border border-dashed border-slate-300 bg-white p-12 text-center">
      <p class="text-lg font-medium">Your budget will live here</p>
      <p class="mt-2 text-sm text-slate-500">
        Phase 1 brings accounts, envelopes and the monthly budget screen.
      </p>
    </div>
  </div>
</template>
