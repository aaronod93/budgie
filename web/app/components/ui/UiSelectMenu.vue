<script setup lang="ts">
// Rich select: a button that opens a teleported popover listing options grouped
// by an optional `group` label, with a pinned "Selected" section and checkmarks.
// Teleports to <body> so it is never clipped by an overflow-scrolling table.
export interface UiSelectMenuOption {
  value: string
  label: string
  group?: string
}

const props = withDefaults(defineProps<{
  modelValue: string
  options: UiSelectMenuOption[]
  placeholder?: string
  size?: 'sm' | 'md'
  disabled?: boolean
  autoOpen?: boolean
}>(), {
  placeholder: 'Select…',
  size: 'md',
  disabled: false,
  autoOpen: false,
})

const emit = defineEmits<{ 'update:modelValue': [string] }>()

const open = ref(false)
const trigger = ref<HTMLButtonElement | null>(null)
const menu = ref<HTMLElement | null>(null)
const pos = reactive({ top: 0, left: 0, width: 0, bottom: 0, above: false })
const highlighted = ref(0)

const selected = computed(() => props.options.find(o => o.value === props.modelValue) ?? null)

// Groups in first-seen order; a "Selected" section is pinned on top when a value
// is chosen, mirroring the reference design.
const sections = computed(() => {
  const groups: { name: string, options: UiSelectMenuOption[] }[] = []
  if (selected.value) groups.push({ name: 'Selected', options: [selected.value] })
  const byName = new Map<string, UiSelectMenuOption[]>()
  for (const option of props.options) {
    const name = option.group ?? 'Accounts'
    if (!byName.has(name)) byName.set(name, [])
    byName.get(name)!.push(option)
  }
  for (const [name, options] of byName) groups.push({ name, options })
  return groups
})

// Flat option order for keyboard navigation (matches render order).
const flat = computed(() => sections.value.flatMap(s => s.options))

function position() {
  const rect = trigger.value?.getBoundingClientRect()
  if (!rect) return
  const below = window.innerHeight - rect.bottom
  pos.above = below < 280 && rect.top > below
  pos.left = rect.left
  pos.width = rect.width
  pos.top = pos.above ? rect.top : rect.bottom
  pos.bottom = window.innerHeight - pos.top
}

function show() {
  if (props.disabled) return
  position()
  highlighted.value = Math.max(0, flat.value.findIndex(o => o.value === props.modelValue))
  open.value = true
}

function close() {
  open.value = false
}

function toggle() {
  open.value ? close() : show()
}

function choose(value: string) {
  emit('update:modelValue', value)
  close()
  trigger.value?.focus()
}

function move(delta: number) {
  if (!open.value) return show()
  const count = flat.value.length
  if (!count) return
  highlighted.value = (highlighted.value + delta + count) % count
  nextTick(() => menu.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' }))
}

function onEnter() {
  if (!open.value) return show()
  const option = flat.value[highlighted.value]
  if (option) choose(option.value)
}

function onDocMouseDown(event: MouseEvent) {
  const target = event.target as Node
  if (!trigger.value?.contains(target) && !menu.value?.contains(target)) close()
}

watch(open, (isOpen) => {
  if (isOpen) {
    document.addEventListener('mousedown', onDocMouseDown)
    window.addEventListener('scroll', close, true)
    window.addEventListener('resize', close)
  } else {
    document.removeEventListener('mousedown', onDocMouseDown)
    window.removeEventListener('scroll', close, true)
    window.removeEventListener('resize', close)
  }
})

onMounted(() => {
  if (props.autoOpen) nextTick(() => { trigger.value?.focus(); show() })
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocMouseDown)
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', close)
})
</script>

<template>
  <div class="w-full">
    <button
      ref="trigger"
      type="button"
      :disabled="disabled"
      :class="[
        'flex w-full items-center justify-between gap-1 border bg-paper-50 text-left text-ink-800',
        'focus:border-accent-400 focus:outline-none disabled:bg-paper-300 disabled:text-mist-700',
        open ? 'border-accent-400' : 'border-paper-400',
        size === 'sm' ? 'px-2 py-1.5 text-sm' : 'px-3 py-2',
      ]"
      @click="toggle"
      @blur="close"
      @keydown.down.prevent="move(1)"
      @keydown.up.prevent="move(-1)"
      @keydown.enter.prevent="onEnter"
      @keydown.esc="close"
    >
      <span :class="['truncate', selected ? '' : 'text-mist-700']">{{ selected?.label ?? placeholder }}</span>
      <svg class="h-3.5 w-3.5 shrink-0 text-mist-700" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
      </svg>
    </button>

    <Teleport to="body">
      <div
        v-if="open"
        ref="menu"
        class="fixed z-50 max-h-72 overflow-y-auto border border-paper-400 bg-white py-1 shadow-xl"
        :style="{
          left: `${pos.left}px`,
          width: `${Math.max(pos.width, 208)}px`,
          top: pos.above ? undefined : `${pos.top + 4}px`,
          bottom: pos.above ? `${pos.bottom + 4}px` : undefined,
        }"
      >
        <template v-for="section in sections" :key="section.name">
          <p class="px-3 pb-0.5 pt-1.5 text-xs font-semibold text-ink-900">{{ section.name }}</p>
          <button
            v-for="option in section.options"
            :key="`${section.name}-${option.value}`"
            type="button"
            :data-active="flat[highlighted]?.value === option.value"
            :class="[
              'flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-ink-800',
              flat[highlighted]?.value === option.value ? 'bg-accent-100' : 'hover:bg-paper-100',
            ]"
            @mousedown.prevent="choose(option.value)"
          >
            <span class="w-4 shrink-0 text-accent-600">{{ option.value === modelValue ? '✓' : '' }}</span>
            <span class="truncate">{{ option.label }}</span>
          </button>
        </template>
      </div>
    </Teleport>
  </div>
</template>
