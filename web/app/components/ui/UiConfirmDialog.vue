<script setup lang="ts">
// Renders the dialog queued by $confirm() (see composables/confirm.ts).
// Mounted once at the app root — do not place it in individual pages.
const confirmBtn = ref<HTMLButtonElement | null>(null)

function settle(confirmed: boolean) {
  const { resolve, reject } = confirmState
  confirmState.open = false
  confirmState.resolve = null
  confirmState.reject = null
  if (confirmed) resolve?.()
  else reject?.(new Error('cancelled'))
}

function onKeydown(event: KeyboardEvent) {
  if (confirmState.open && event.key === 'Escape') settle(false)
}

watch(() => confirmState.open, (open) => {
  if (open) nextTick(() => confirmBtn.value?.focus())
})

onMounted(() => document.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body">
    <div
      v-if="confirmState.open"
      class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40 p-4"
      role="alertdialog"
      aria-modal="true"
      :aria-label="confirmState.title"
      @mousedown.self="settle(false)"
    >
      <div class="w-full max-w-sm rounded-sm border border-paper-300 bg-white p-6 text-ink-800 shadow-xl">
        <h2 class="mb-2 text-lg font-semibold">{{ confirmState.title }}</h2>
        <p class="mb-5 text-sm text-mist-700">{{ confirmState.text }}</p>
        <div class="flex justify-end gap-2">
          <UiButton variant="ghost" @click="settle(false)">{{ confirmState.rejectText }}</UiButton>
          <button
            ref="confirmBtn"
            type="button"
            class="rounded-sm px-4 py-2 font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-1"
            :class="confirmState.danger
              ? 'bg-red-600 hover:bg-red-700 focus:ring-red-400'
              : 'bg-ink-800 hover:bg-ink-600 focus:ring-ink-500'"
            @click="settle(true)"
          >
            {{ confirmState.confirmText }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
