<script setup lang="ts">
// Date field backed by @vuepic/vue-datepicker, working in plain YYYY-MM-DD
// strings. Themed via the .dp__theme_light variables in main.css.
import { VueDatePicker } from '@vuepic/vue-datepicker'
import '@vuepic/vue-datepicker/dist/main.css'

const props = withDefaults(defineProps<{
  modelValue: string
  size?: 'sm' | 'md'
  required?: boolean
}>(), {
  size: 'md',
  required: false,
})

const emit = defineEmits<{ 'update:modelValue': [string] }>()

function onUpdate(value: string | null) {
  emit('update:modelValue', value ?? '')
}
</script>

<template>
  <VueDatePicker
    :model-value="modelValue"
    model-type="yyyy-MM-dd"
    format="yyyy-MM-dd"
    :enable-time-picker="false"
    auto-apply
    :clearable="false"
    :required="required"
    text-input
    :class="size === 'sm' ? 'dp-sm' : ''"
    @update:model-value="onUpdate"
  />
</template>

<style>
/* Compact variant to sit inside the register entry row. */
.dp-sm .dp__input {
  padding-top: 0.375rem;
  padding-bottom: 0.375rem;
  font-size: 0.875rem;
}
</style>
