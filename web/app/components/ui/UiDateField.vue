<script setup lang="ts">
// Custom date field — no library. Works in plain yyyy-MM-dd strings and
// displays per the user's preference (default dd/MM/yyyy).
//
// Behaviour:
// - Focus (click, Tab, or programmatic) opens the calendar popover.
// - Type a date free-form (17/07/2026, 17/7, 2026-07-17…) — parsed on Enter/blur.
// - Shift+Arrows step the day from the input: ←/→ ±1 day, ↑/↓ ±1 week.
// - Tab closes the popover and moves to the next field (nothing inside the
//   popover is tabbable). Enter commits and bubbles to the row handler.
// - Click a day to select: emits, closes, keeps focus on the input.

interface Ymd { y: number, m: number, d: number }

const props = withDefaults(defineProps<{
  modelValue: string
  size?: 'sm' | 'md'
  required?: boolean
}>(), {
  size: 'md',
  required: false,
})

const emit = defineEmits<{ 'update:modelValue': [string] }>()

const displayFormat = useDateDisplayFormat()

const root = ref<HTMLElement | null>(null)
const inputEl = ref<HTMLInputElement | null>(null)
const menuEl = ref<HTMLElement | null>(null)
const open = ref(false)
const typing = ref<string | null>(null) // null = show the formatted model value
const pos = reactive({ left: 0, top: 0, bottom: 0, above: false })
const view = reactive({ y: 2000, m: 1 }) // displayed calendar month

const WEEKDAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']

function pad(n: number): string {
  return String(n).padStart(2, '0')
}

function toIso({ y, m, d }: Ymd): string {
  return `${y}-${pad(m)}-${pad(d)}`
}

function parseIso(value: string): Ymd | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!match) return null
  return { y: Number(match[1]), m: Number(match[2]), d: Number(match[3]) }
}

function todayYmd(): Ymd {
  const now = new Date()
  return { y: now.getFullYear(), m: now.getMonth() + 1, d: now.getDate() }
}

const selected = computed(() => parseIso(props.modelValue))

function format(date: Ymd): string {
  return displayFormat.value
    .replace('dd', pad(date.d))
    .replace('MM', pad(date.m))
    .replace('yyyy', String(date.y))
}

const display = computed(() => typing.value ?? (selected.value ? format(selected.value) : ''))

/** Parse free-typed text: the preferred format's day/month order, 2- or 3-part
 *  dates, 2-digit years, and ISO yyyy-MM-dd. Returns null if unintelligible. */
function parseTyped(text: string): Ymd | null {
  const t = text.trim()
  if (!t) return null
  const iso = parseIso(t)
  if (iso) return isValid(iso) ? iso : null

  const parts = t.split(/[\s/.-]+/).filter(Boolean).map(Number)
  if (parts.length < 2 || parts.length > 3 || parts.some(Number.isNaN)) return null

  const dayFirst = displayFormat.value.indexOf('dd') < displayFormat.value.indexOf('MM')
  const [a, b, c] = parts
  const d = dayFirst ? a! : b!
  const m = dayFirst ? b! : a!
  let y = c ?? todayYmd().y
  if (y < 100) y += 2000

  const candidate = { y, m, d }
  return isValid(candidate) ? candidate : null
}

function isValid({ y, m, d }: Ymd): boolean {
  if (m < 1 || m > 12 || d < 1 || d > 31 || y < 1900 || y > 2200) return false
  const date = new Date(y, m - 1, d)
  return date.getFullYear() === y && date.getMonth() === m - 1 && date.getDate() === d
}

// --- Calendar grid (Monday-first, 6 fixed weeks) ---

const monthLabel = computed(() =>
  new Date(view.y, view.m - 1, 1).toLocaleDateString('en-AU', { month: 'long', year: 'numeric' }))

const cells = computed(() => {
  const first = new Date(view.y, view.m - 1, 1)
  const lead = (first.getDay() + 6) % 7 // days shown from the previous month
  const today = todayYmd()
  return Array.from({ length: 42 }, (_, i) => {
    const date = new Date(view.y, view.m - 1, 1 - lead + i)
    const ymd = { y: date.getFullYear(), m: date.getMonth() + 1, d: date.getDate() }
    return {
      ...ymd,
      iso: toIso(ymd),
      inMonth: ymd.m === view.m && ymd.y === view.y,
      isToday: ymd.y === today.y && ymd.m === today.m && ymd.d === today.d,
    }
  })
})

function moveMonth(delta: number) {
  const date = new Date(view.y, view.m - 1 + delta, 1)
  view.y = date.getFullYear()
  view.m = date.getMonth() + 1
}

// --- Popover management ---

function position() {
  const rect = inputEl.value?.getBoundingClientRect()
  if (!rect) return
  const below = window.innerHeight - rect.bottom
  pos.above = below < 340 && rect.top > below
  pos.left = Math.max(8, Math.min(rect.left, window.innerWidth - 296))
  pos.top = rect.bottom
  pos.bottom = window.innerHeight - rect.top
}

function openMenu() {
  if (open.value) return
  const base = selected.value ?? todayYmd()
  view.y = base.y
  view.m = base.m
  position()
  open.value = true
}

function close() {
  open.value = false
}

function onDocMouseDown(event: MouseEvent) {
  const target = event.target as Node
  if (!root.value?.contains(target) && !menuEl.value?.contains(target)) close()
}

watch(open, (isOpen) => {
  if (isOpen) {
    document.addEventListener('mousedown', onDocMouseDown)
    window.addEventListener('scroll', position, true)
    window.addEventListener('resize', position)
  } else {
    document.removeEventListener('mousedown', onDocMouseDown)
    window.removeEventListener('scroll', position, true)
    window.removeEventListener('resize', position)
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocMouseDown)
  window.removeEventListener('scroll', position, true)
  window.removeEventListener('resize', position)
})

// --- Input interaction ---

function choose(iso: string) {
  emit('update:modelValue', iso)
  typing.value = null
  close()
  inputEl.value?.focus()
}

function commitTyped() {
  if (typing.value === null) return
  const parsed = parseTyped(typing.value)
  typing.value = null
  if (parsed) emit('update:modelValue', toIso(parsed))
}

function shiftDays(days: number) {
  const base = selected.value ?? todayYmd()
  const date = new Date(base.y, base.m - 1, base.d + days)
  const next = { y: date.getFullYear(), m: date.getMonth() + 1, d: date.getDate() }
  emit('update:modelValue', toIso(next))
  typing.value = null
  view.y = next.y
  view.m = next.m
}

function onInput(event: Event) {
  typing.value = (event.target as HTMLInputElement).value
  openMenu()
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Tab') {
    commitTyped()
    close()
    return
  }
  if (event.key === 'Escape') {
    typing.value = null
    close()
    return
  }
  if (event.key === 'Enter') {
    // Commit, close, and let the event bubble (the register row advances focus).
    commitTyped()
    close()
    return
  }
  if (event.shiftKey && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) {
    event.preventDefault()
    openMenu()
    shiftDays(event.key === 'ArrowUp' ? -7 : event.key === 'ArrowDown' ? 7 : event.key === 'ArrowLeft' ? -1 : 1)
  }
}

function onBlur() {
  commitTyped()
}
</script>

<template>
  <div ref="root" class="w-full">
    <input
      ref="inputEl"
      :value="display"
      :placeholder="displayFormat.toLowerCase()"
      :required="required"
      autocomplete="off"
      inputmode="numeric"
      :class="[
        'w-full rounded-sm border border-paper-400 bg-white text-ink-800',
        'focus:border-ink-500 focus:outline-none',
        size === 'sm' ? 'px-2 py-1.5 text-sm' : 'px-3 py-2',
      ]"
      @focus="openMenu"
      @click="openMenu"
      @input="onInput"
      @blur="onBlur"
      @keydown="onKeydown"
    >
    <Teleport to="body">
      <div
        v-if="open"
        ref="menuEl"
        class="fixed z-50 w-72 rounded-sm border border-paper-400 bg-white p-3 shadow-xl"
        :style="{
          left: `${pos.left}px`,
          top: pos.above ? undefined : `${pos.top + 4}px`,
          bottom: pos.above ? `${pos.bottom + 4}px` : undefined,
        }"
      >
        <div class="mb-2 flex items-center justify-between">
          <button type="button" tabindex="-1" class="rounded-sm px-2 py-0.5 text-ink-700 hover:bg-paper-100" @mousedown.prevent @click="moveMonth(-1)">‹</button>
          <span class="text-sm font-semibold">{{ monthLabel }}</span>
          <button type="button" tabindex="-1" class="rounded-sm px-2 py-0.5 text-ink-700 hover:bg-paper-100" @mousedown.prevent @click="moveMonth(1)">›</button>
        </div>
        <div class="mb-1 grid grid-cols-7 text-center text-xs font-semibold text-mist-700">
          <span v-for="day in WEEKDAYS" :key="day">{{ day }}</span>
        </div>
        <div class="grid grid-cols-7">
          <button
            v-for="cell in cells"
            :key="cell.iso"
            type="button"
            tabindex="-1"
            class="h-8 rounded-sm text-center text-sm"
            :class="[
              cell.iso === modelValue
                ? 'bg-ink-800 font-medium text-white'
                : cell.isToday
                  ? 'rounded-sm border border-ink-500 text-ink-800 hover:bg-mist-200/50'
                  : cell.inMonth
                    ? 'text-ink-800 hover:bg-mist-200/50'
                    : 'text-paper-400 hover:bg-paper-100',
            ]"
            @mousedown.prevent
            @click="choose(cell.iso)"
          >
            {{ cell.d }}
          </button>
        </div>
      </div>
    </Teleport>
  </div>
</template>
