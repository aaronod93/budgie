<script setup lang="ts">
// Tailwind-styled native <select>. Options (including <optgroup>) go in the
// default slot, so grouping and keyboard behavior stay fully native.
const props = withDefaults(defineProps<{
  modelValue: string
  size?: 'sm' | 'md'
  disabled?: boolean
}>(), {
  size: 'md',
  disabled: false,
})

const emit = defineEmits<{ 'update:modelValue': [string] }>()

const classes = computed(() => [
  'w-full rounded-sm border border-paper-400 bg-white text-ink-800',
  'focus:border-ink-500 focus:outline-none disabled:bg-paper-300 disabled:text-mist-700',
  props.size === 'sm' ? 'px-2 py-1 text-sm' : 'px-3 py-2',
])
</script>

<template>
  <select
    :value="modelValue"
    :disabled="disabled"
    :class="classes"
    @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
  >
    <slot />
  </select>
</template>
