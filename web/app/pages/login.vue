<script setup lang="ts">
definePageMeta({ middleware: 'guest' })

const auth = useAuthStore()

const email = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)

async function submit() {
  error.value = ''
  busy.value = true
  try {
    const { twoFactor } = await auth.login(email.value, password.value)
    await navigateTo(twoFactor ? '/two-factor' : '/')
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Login failed. Please try again.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center p-4">
    <div class="w-full max-w-sm rounded-sm bg-paper-200 p-8 text-ink-800 shadow-lg">
      <h1 class="mb-1 text-2xl font-bold text-accent-500">Lil' Budgie</h1>
      <p class="mb-6 text-sm text-mist-700">Sign in to your budget</p>

      <form class="space-y-4" @submit.prevent="submit">
        <div>
          <label for="email" class="mb-1 block text-sm font-medium">Email</label>
          <input
            id="email"
            v-model="email"
            type="email"
            required
            autocomplete="email"
            class="w-full rounded-sm border border-paper-400 bg-white px-3 py-2 focus:border-ink-500 focus:outline-none"
          >
        </div>

        <div>
          <label for="password" class="mb-1 block text-sm font-medium">Password</label>
          <input
            id="password"
            v-model="password"
            type="password"
            required
            autocomplete="current-password"
            class="w-full rounded-sm border border-paper-400 bg-white px-3 py-2 focus:border-ink-500 focus:outline-none"
          >
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <button
          type="submit"
          :disabled="busy"
          class="w-full rounded-sm bg-accent-400 px-4 py-2 font-medium text-ink-900 hover:bg-accent-500 disabled:opacity-50"
        >
          {{ busy ? 'Signing in…' : 'Sign in' }}
        </button>
      </form>

      <p class="mt-6 text-center text-sm text-mist-700">
        No account?
        <NuxtLink to="/register" class="font-medium text-accent-600 hover:underline">Register</NuxtLink>
      </p>
    </div>
  </div>
</template>
