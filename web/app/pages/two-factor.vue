<script setup lang="ts">
definePageMeta({ middleware: 'guest' })

const auth = useAuthStore()

const code = ref('')
const useRecovery = ref(false)
const error = ref('')
const busy = ref(false)

async function submit() {
  error.value = ''
  busy.value = true
  try {
    await auth.twoFactorChallenge(
      useRecovery.value ? { recovery_code: code.value } : { code: code.value },
    )
    await navigateTo('/')
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Invalid code. Please try again.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center p-4">
    <div class="w-full max-w-sm bg-paper-200 p-8 text-ink-800 shadow-lg">
      <h1 class="mb-1 text-2xl font-bold text-accent-500">Two-factor authentication</h1>
      <p class="mb-6 text-sm text-mist-700">
        {{ useRecovery ? 'Enter one of your recovery codes.' : 'Enter the code from your authenticator app.' }}
      </p>

      <form class="space-y-4" @submit.prevent="submit">
        <input
          v-model="code"
          :placeholder="useRecovery ? 'Recovery code' : '123456'"
          required
          autocomplete="one-time-code"
          :inputmode="useRecovery ? 'text' : 'numeric'"
          class="w-full border border-paper-400 bg-paper-50 px-3 py-2 text-center tracking-widest focus:border-accent-400 focus:outline-none"
        >

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <button
          type="submit"
          :disabled="busy"
          class="w-full bg-accent-400 px-4 py-2 font-medium text-ink-900 hover:bg-accent-500 disabled:opacity-50"
        >
          {{ busy ? 'Verifying…' : 'Verify' }}
        </button>
      </form>

      <button
        type="button"
        class="mt-4 w-full text-center text-sm text-accent-600 hover:underline"
        @click="useRecovery = !useRecovery; code = ''"
      >
        {{ useRecovery ? 'Use an authenticator code instead' : 'Use a recovery code instead' }}
      </button>
    </div>
  </div>
</template>
