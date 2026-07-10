<script setup lang="ts">
definePageMeta({ middleware: 'guest' })

const auth = useAuthStore()

const form = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})
const error = ref('')
const busy = ref(false)

async function submit() {
  error.value = ''
  busy.value = true
  try {
    await auth.register({ ...form })
    await navigateTo('/')
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Registration failed. Please try again.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center p-4">
    <div class="w-full max-w-sm rounded-xl bg-white p-8 shadow">
      <h1 class="mb-1 text-2xl font-bold text-emerald-700">Create your account</h1>
      <p class="mb-6 text-sm text-slate-500">Start budgeting with Lil' Budgie</p>

      <form class="space-y-4" @submit.prevent="submit">
        <div>
          <label for="name" class="mb-1 block text-sm font-medium">Name</label>
          <input
            id="name"
            v-model="form.name"
            required
            autocomplete="name"
            class="w-full rounded-md border border-slate-300 px-3 py-2 focus:border-emerald-500 focus:outline-none"
          >
        </div>

        <div>
          <label for="email" class="mb-1 block text-sm font-medium">Email</label>
          <input
            id="email"
            v-model="form.email"
            type="email"
            required
            autocomplete="email"
            class="w-full rounded-md border border-slate-300 px-3 py-2 focus:border-emerald-500 focus:outline-none"
          >
        </div>

        <div>
          <label for="password" class="mb-1 block text-sm font-medium">Password</label>
          <input
            id="password"
            v-model="form.password"
            type="password"
            required
            autocomplete="new-password"
            class="w-full rounded-md border border-slate-300 px-3 py-2 focus:border-emerald-500 focus:outline-none"
          >
        </div>

        <div>
          <label for="password_confirmation" class="mb-1 block text-sm font-medium">Confirm password</label>
          <input
            id="password_confirmation"
            v-model="form.password_confirmation"
            type="password"
            required
            autocomplete="new-password"
            class="w-full rounded-md border border-slate-300 px-3 py-2 focus:border-emerald-500 focus:outline-none"
          >
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <button
          type="submit"
          :disabled="busy"
          class="w-full rounded-md bg-emerald-600 px-4 py-2 font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
        >
          {{ busy ? 'Creating account…' : 'Register' }}
        </button>
      </form>

      <p class="mt-6 text-center text-sm text-slate-500">
        Already have an account?
        <NuxtLink to="/login" class="font-medium text-emerald-700 hover:underline">Sign in</NuxtLink>
      </p>
    </div>
  </div>
</template>
