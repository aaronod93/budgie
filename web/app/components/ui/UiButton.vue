<script setup lang="ts">
// Tailwind-styled button with a built-in loading state. When `loading` is true
// it shows a spinner and becomes unclickable, so an in-flight submit can't be
// fired twice by an impatient double-click.
const props = withDefaults(defineProps<{
  type?: 'button' | 'submit'
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
  size?: 'sm' | 'md'
  loading?: boolean
  disabled?: boolean
}>(), {
  type: 'button',
  variant: 'primary',
  size: 'md',
  loading: false,
  disabled: false,
})

const variants: Record<NonNullable<typeof props.variant>, string> = {
  primary: 'bg-accent-400 font-medium text-ink-900 hover:bg-accent-500',
  secondary: 'border border-accent-500 text-accent-600 hover:bg-accent-100',
  ghost: 'text-ink-600 hover:bg-paper-300',
  danger: 'bg-red-600 font-medium text-white hover:bg-red-700',
}

const classes = computed(() => [
  'relative inline-flex items-center justify-center gap-2 transition-colors',
  'disabled:cursor-not-allowed disabled:opacity-60',
  props.size === 'sm' ? 'px-3 py-1 text-xs' : 'px-4 py-2',
  variants[props.variant],
])
</script>

<template>
  <button :type="type" :disabled="disabled || loading" :class="classes" :aria-busy="loading">
    <span
      v-if="loading"
      class="h-3.5 w-3.5 shrink-0 animate-spin border-2 border-current border-t-transparent"
      aria-hidden="true"
    />
    <slot />
  </button>
</template>
