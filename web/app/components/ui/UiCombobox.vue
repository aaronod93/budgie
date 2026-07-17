<script setup lang="ts">
// Tailwind-native combobox: a text input with a filtered dropdown.
// - Options are { value, label }; the label is what's displayed and filtered.
// - With allow-custom, free-typed text is emitted as the value on blur/enter.
// - With create-label, a "Create <query> <label>" row lets you add an item
//   in-situ from whatever you typed (no navigation) when nothing matches exactly.
// - Keyboard: ArrowUp/Down to highlight, Enter to pick, Esc to close.
// - The dropdown teleports to <body> so it is never clipped by a scrolling table.
export interface UiComboboxOption {
  value: string
  label: string
}

interface NavItem {
  value: string
  label: string
  create: boolean
  option?: UiComboboxOption
}

const props = withDefaults(defineProps<{
  modelValue: string
  options: UiComboboxOption[]
  placeholder?: string
  allowCustom?: boolean
  disabled?: boolean
  size?: 'sm' | 'md'
  heading?: string
  createLabel?: string
  footerLabel?: string
  footerTo?: string
}>(), {
  placeholder: '',
  allowCustom: false,
  disabled: false,
  size: 'md',
  heading: '',
  createLabel: '',
  footerLabel: '',
  footerTo: '',
})

const emit = defineEmits<{
  'update:modelValue': [string]
  'select': [UiComboboxOption]
  'create': [string]
}>()

const open = ref(false)
const query = ref<string | null>(null) // null = show the selected label
const highlighted = ref(0)
const inputEl = ref<HTMLInputElement | null>(null)
const listEl = ref<HTMLElement | null>(null)
const pos = reactive({ top: 0, left: 0, width: 0, bottom: 0, above: false })

const selectedLabel = computed(() =>
  props.options.find(o => o.value === props.modelValue)?.label
  ?? (props.allowCustom ? props.modelValue : ''))

const displayValue = computed(() => query.value ?? selectedLabel.value)

const trimmedQuery = computed(() => (query.value ?? '').trim())

const filtered = computed(() => {
  const needle = trimmedQuery.value.toLowerCase()
  if (!needle) return props.options
  return props.options.filter(o => o.label.toLowerCase().includes(needle))
})

// Offer in-situ creation when text is typed that doesn't already exist verbatim.
const showCreate = computed(() =>
  !!props.createLabel
  && !!trimmedQuery.value
  && !props.options.some(o => o.label.toLowerCase() === trimmedQuery.value.toLowerCase()))

// Unified keyboard-navigable list: the create row (if any) sits first.
const navItems = computed<NavItem[]>(() => {
  const items: NavItem[] = filtered.value.map(o => ({ value: o.value, label: o.label, create: false, option: o }))
  if (showCreate.value) items.unshift({ value: trimmedQuery.value, label: trimmedQuery.value, create: true })
  return items
})

// Default the highlight to the first real match, not the create row.
watch(navItems, () => {
  const firstReal = navItems.value.findIndex(i => !i.create)
  highlighted.value = firstReal < 0 ? 0 : firstReal
})

function position() {
  const rect = inputEl.value?.getBoundingClientRect()
  if (!rect) return
  const below = window.innerHeight - rect.bottom
  pos.above = below < 280 && rect.top > below
  pos.left = rect.left
  pos.width = rect.width
  pos.top = pos.above ? rect.top : rect.bottom
  pos.bottom = window.innerHeight - pos.top
}

function openMenu() {
  position()
  open.value = true
}

function onInput(event: Event) {
  query.value = (event.target as HTMLInputElement).value
  openMenu()
}

function pick(item: NavItem) {
  query.value = null
  open.value = false
  if (item.create) {
    emit('create', item.value)
    return
  }
  emit('update:modelValue', item.value)
  if (item.option) emit('select', item.option)
}

function onEnter(event: KeyboardEvent) {
  if (!open.value) return
  event.preventDefault()
  const item = navItems.value[highlighted.value]
  if (item) {
    pick(item)
  } else if (props.allowCustom && query.value !== null) {
    emit('update:modelValue', query.value.trim())
    query.value = null
    open.value = false
  }
}

function move(delta: number) {
  if (!open.value) {
    openMenu()
    return
  }
  const count = navItems.value.length
  if (count === 0) return
  highlighted.value = (highlighted.value + delta + count) % count
  nextTick(() => {
    listEl.value?.querySelector('[data-highlighted="true"]')?.scrollIntoView({ block: 'nearest' })
  })
}

function onBlur() {
  open.value = false
  if (query.value === null) return
  const typed = query.value.trim()
  query.value = null
  if (!typed) {
    if (props.allowCustom) emit('update:modelValue', '')
    return
  }
  const match = props.options.find(o => o.label.toLowerCase() === typed.toLowerCase())
  if (match) {
    pick({ value: match.value, label: match.label, create: false, option: match })
  } else if (props.allowCustom) {
    emit('update:modelValue', typed)
  }
}

function goFooter() {
  if (props.footerTo) navigateTo(props.footerTo)
}

watch(open, (isOpen) => {
  if (isOpen) window.addEventListener('scroll', position, true)
  else window.removeEventListener('scroll', position, true)
})

onBeforeUnmount(() => window.removeEventListener('scroll', position, true))
</script>

<template>
  <div class="relative w-full">
    <input
      ref="inputEl"
      :value="displayValue"
      :placeholder="placeholder"
      :disabled="disabled"
      autocomplete="off"
      role="combobox"
      :aria-expanded="open"
      :class="[
        'w-full rounded-sm border border-paper-400 bg-white text-ink-800',
        'focus:border-ink-500 focus:outline-none disabled:bg-paper-300 disabled:text-mist-700',
        size === 'sm' ? 'px-2 py-1.5 text-sm' : 'px-3 py-2',
      ]"
      @focus="openMenu"
      @input="onInput"
      @blur="onBlur"
      @keydown.down.prevent="move(1)"
      @keydown.up.prevent="move(-1)"
      @keydown.enter="onEnter"
      @keydown.esc="open = false; query = null"
    >
    <Teleport to="body">
      <div
        v-if="open && (navItems.length || footerLabel)"
        ref="listEl"
        class="fixed z-50 overflow-hidden rounded-sm border border-paper-400 bg-white shadow-xl"
        :style="{
          left: `${pos.left}px`,
          width: `${Math.max(pos.width, 224)}px`,
          top: pos.above ? undefined : `${pos.top + 4}px`,
          bottom: pos.above ? `${pos.bottom + 4}px` : undefined,
        }"
      >
        <!-- Create-on-the-fly row -->
        <button
          v-if="showCreate"
          type="button"
          :data-highlighted="highlighted === 0"
          :class="[
            'flex w-full items-center gap-2 border-b border-paper-200 px-3 py-2 text-left text-sm font-medium text-ink-600',
            highlighted === 0 ? 'bg-mist-200/50' : 'hover:bg-paper-100',
          ]"
          @mousedown.prevent="pick(navItems[0]!)"
          @mousemove="highlighted = 0"
        >
          <span aria-hidden="true">＋</span>
          <span class="truncate">Create “{{ trimmedQuery }}” {{ createLabel }}</span>
        </button>

        <p v-if="heading && filtered.length" class="border-b border-paper-200 px-3 py-1.5 text-xs font-semibold text-ink-900">{{ heading }}</p>
        <div class="max-h-60 overflow-y-auto py-1">
          <button
            v-for="(option, index) in filtered"
            :key="option.value"
            type="button"
            :data-highlighted="(showCreate ? index + 1 : index) === highlighted"
            :class="[
              'block w-full px-3 py-1.5 text-left text-sm text-ink-800',
              (showCreate ? index + 1 : index) === highlighted ? 'bg-mist-200/50' : 'hover:bg-paper-100',
              option.value === modelValue ? 'font-semibold' : '',
            ]"
            @mousedown.prevent="pick({ value: option.value, label: option.label, create: false, option })"
            @mousemove="highlighted = showCreate ? index + 1 : index"
          >
            {{ option.label }}
          </button>
          <p v-if="!filtered.length && !showCreate" class="px-3 py-2 text-sm text-mist-700">No matches.</p>
        </div>
        <button
          v-if="footerLabel"
          type="button"
          class="block w-full border-t border-paper-200 px-3 py-2 text-left text-sm font-medium text-ink-600 hover:bg-paper-100"
          @mousedown.prevent="goFooter"
        >
          {{ footerLabel }}
        </button>
      </div>
    </Teleport>
  </div>
</template>
