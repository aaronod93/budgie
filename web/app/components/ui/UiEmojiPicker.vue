<script setup lang="ts">
// Compact emoji picker: a button showing the current icon that opens a
// searchable grid, plus a custom-entry field for anything not in the list.
// The popover teleports to <body> so it is never clipped by table/modal
// overflow. Fully Tailwind-styled — no third-party CSS.
const props = withDefaults(defineProps<{
  modelValue: string | null
  size?: 'sm' | 'md'
}>(), {
  size: 'sm',
})

const emit = defineEmits<{ 'update:modelValue': [string] }>()

// Curated set covering budgeting categories; `k` is the search keyword blob.
// Anything missing is still reachable via the custom-entry field.
interface Emoji { e: string, k: string }
const EMOJIS: Emoji[] = [
  // Money & bills
  { e: '💰', k: 'money bag savings' }, { e: '💵', k: 'cash money dollars' },
  { e: '💳', k: 'card credit debit' }, { e: '🏦', k: 'bank' },
  { e: '🧾', k: 'receipt bill invoice tax' }, { e: '💸', k: 'money spend fly' },
  { e: '🪙', k: 'coin money' }, { e: '📈', k: 'chart up invest gains income' },
  { e: '📉', k: 'chart down loss' }, { e: '💹', k: 'chart money yen' },
  { e: '🐷', k: 'piggy bank savings' }, { e: '🎯', k: 'target goal' },
  // Home & utilities
  { e: '🏠', k: 'house home rent mortgage' }, { e: '🏡', k: 'house home garden' },
  { e: '🔑', k: 'key rent' }, { e: '🛋️', k: 'couch furniture home' },
  { e: '🛏️', k: 'bed furniture' }, { e: '🚿', k: 'shower water' },
  { e: '🧹', k: 'clean broom' }, { e: '🧺', k: 'laundry basket' },
  { e: '💡', k: 'light bulb electricity idea' }, { e: '⚡', k: 'electricity power energy' },
  { e: '🔌', k: 'plug power electricity' }, { e: '💧', k: 'water drop' },
  { e: '🚰', k: 'water tap' }, { e: '🔥', k: 'gas heat fire' },
  { e: '🌡️', k: 'heating temperature' }, { e: '🧯', k: 'insurance fire safety' },
  { e: '🛠️', k: 'repairs maintenance tools' }, { e: '🪑', k: 'chair furniture' },
  // Food & dining
  { e: '🛒', k: 'groceries shopping cart supermarket' }, { e: '🍔', k: 'burger food fast' },
  { e: '🍕', k: 'pizza food' }, { e: '🍟', k: 'fries food fast' },
  { e: '🌮', k: 'taco food mexican' }, { e: '🍣', k: 'sushi food japanese' },
  { e: '🍜', k: 'noodles ramen food' }, { e: '🥗', k: 'salad food healthy' },
  { e: '🍎', k: 'apple fruit food' }, { e: '🥦', k: 'broccoli veg food' },
  { e: '🥩', k: 'meat steak food butcher' }, { e: '🍞', k: 'bread bakery food' },
  { e: '☕', k: 'coffee cafe drink' }, { e: '🍺', k: 'beer alcohol drink pub' },
  { e: '🍷', k: 'wine alcohol drink' }, { e: '🍽️', k: 'dining restaurant eating out' },
  { e: '🥡', k: 'takeaway takeout food' }, { e: '🍰', k: 'cake dessert treat' },
  // Transport
  { e: '🚗', k: 'car auto transport' }, { e: '🚙', k: 'car suv transport' },
  { e: '🚕', k: 'taxi cab transport' }, { e: '🚌', k: 'bus transport public' },
  { e: '🚆', k: 'train transport public' }, { e: '🚲', k: 'bike bicycle cycling' },
  { e: '🛵', k: 'scooter moped transport' }, { e: '⛽', k: 'fuel petrol gas' },
  { e: '🅿️', k: 'parking' }, { e: '🛣️', k: 'road tolls' },
  { e: '✈️', k: 'flight plane travel' }, { e: '🚢', k: 'ship boat cruise' },
  // Shopping & personal
  { e: '🛍️', k: 'shopping bags retail' }, { e: '👕', k: 'clothes shirt clothing' },
  { e: '👖', k: 'jeans clothes clothing' }, { e: '👗', k: 'dress clothes clothing' },
  { e: '👟', k: 'shoes sneakers clothing' }, { e: '👜', k: 'handbag bag' },
  { e: '💄', k: 'makeup beauty cosmetics' }, { e: '🧴', k: 'toiletries lotion' },
  { e: '⌚', k: 'watch accessory' }, { e: '🕶️', k: 'sunglasses accessory' },
  { e: '💇', k: 'haircut hair beauty' }, { e: '🎁', k: 'gift present' },
  // Health
  { e: '🏥', k: 'hospital health medical' }, { e: '💊', k: 'pills medicine pharmacy' },
  { e: '💉', k: 'injection vaccine medical' }, { e: '🩺', k: 'doctor health medical' },
  { e: '🦷', k: 'dentist teeth dental' }, { e: '👓', k: 'glasses optical' },
  { e: '🧘', k: 'yoga wellness health' }, { e: '🏋️', k: 'gym fitness workout' },
  { e: '🩹', k: 'health first aid' }, { e: '⚕️', k: 'medical health insurance' },
  // Fun & leisure
  { e: '🎮', k: 'games gaming fun' }, { e: '🎬', k: 'movies cinema film' },
  { e: '🎵', k: 'music' }, { e: '🎸', k: 'guitar music hobby' },
  { e: '🎨', k: 'art hobby craft' }, { e: '📚', k: 'books reading education' },
  { e: '🎟️', k: 'tickets events entertainment' }, { e: '🍿', k: 'movies popcorn cinema' },
  { e: '📺', k: 'tv streaming subscription' }, { e: '🎧', k: 'headphones music audio' },
  { e: '⚽', k: 'sport soccer football' }, { e: '🏀', k: 'sport basketball' },
  { e: '🎾', k: 'sport tennis' }, { e: '⛳', k: 'golf sport' },
  { e: '🎣', k: 'fishing hobby' }, { e: '🏕️', k: 'camping outdoors' },
  { e: '🎢', k: 'theme park fun' }, { e: '🎲', k: 'games board dice' },
  // Travel & holidays
  { e: '🏖️', k: 'beach holiday vacation' }, { e: '🧳', k: 'luggage travel holiday' },
  { e: '🏨', k: 'hotel accommodation travel' }, { e: '🗺️', k: 'map travel trip' },
  { e: '⛰️', k: 'mountain travel outdoors' }, { e: '🗽', k: 'travel landmark' },
  // Kids, pets & people
  { e: '👶', k: 'baby kids children' }, { e: '🧒', k: 'child kids children' },
  { e: '🍼', k: 'baby bottle kids' }, { e: '🧸', k: 'toys kids children' },
  { e: '🎓', k: 'education school tuition graduation' }, { e: '🐶', k: 'dog pet' },
  { e: '🐱', k: 'cat pet' }, { e: '🐾', k: 'pet paw animal vet' },
  // Tech & subscriptions
  { e: '📱', k: 'phone mobile' }, { e: '💻', k: 'laptop computer tech' },
  { e: '🖥️', k: 'computer desktop tech' }, { e: '📶', k: 'internet signal mobile' },
  { e: '☁️', k: 'cloud storage subscription' }, { e: '🖨️', k: 'printer office' },
  { e: '🔋', k: 'battery power' }, { e: '🛜', k: 'wifi internet' },
  // Savings, goals & symbols
  { e: '🏆', k: 'trophy goal achievement' }, { e: '⭐', k: 'star favourite goal' },
  { e: '🌟', k: 'star goal' }, { e: '💍', k: 'ring wedding jewelry' },
  { e: '🚨', k: 'emergency alert fund' }, { e: '❤️', k: 'love heart favourite' },
  { e: '🔔', k: 'reminder notification bell' }, { e: '⏰', k: 'time clock alarm' },
  { e: '📅', k: 'calendar date recurring' }, { e: '✅', k: 'done check complete' },
  { e: '🎉', k: 'celebration party fun' }, { e: '🌍', k: 'world global charity' },
  { e: '♻️', k: 'recycle waste bins' }, { e: '📌', k: 'pin misc' },
]

const open = ref(false)
const search = ref('')
const custom = ref('')
const triggerEl = ref<HTMLButtonElement | null>(null)
const panelEl = ref<HTMLElement | null>(null)
const panelStyle = ref<Record<string, string>>({})

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return EMOJIS
  return EMOJIS.filter(item => item.e === q || item.k.includes(q))
})

function toggle() {
  open.value ? close() : show()
}

async function show() {
  open.value = true
  search.value = ''
  custom.value = props.modelValue ?? ''
  await nextTick()
  position()
}

function position() {
  const rect = triggerEl.value?.getBoundingClientRect()
  if (!rect) return
  const panelW = 288
  const panelH = 340
  const gap = 4
  // Prefer below; flip up if it would overflow the viewport.
  const below = rect.bottom + gap
  const top = below + panelH > window.innerHeight ? rect.top - panelH - gap : below
  const left = Math.min(rect.left, window.innerWidth - panelW - 8)
  panelStyle.value = {
    top: `${Math.max(8, top)}px`,
    left: `${Math.max(8, left)}px`,
    width: `${panelW}px`,
  }
}

function close() {
  open.value = false
}

function choose(value: string) {
  emit('update:modelValue', value)
  close()
}

function applyCustom() {
  const value = custom.value.trim()
  choose(value)
}

function onDocMouseDown(event: MouseEvent) {
  const target = event.target as Node
  if (triggerEl.value?.contains(target) || panelEl.value?.contains(target)) return
  close()
}

onMounted(() => {
  document.addEventListener('mousedown', onDocMouseDown)
  window.addEventListener('resize', position)
  window.addEventListener('scroll', position, true)
})
onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocMouseDown)
  window.removeEventListener('resize', position)
  window.removeEventListener('scroll', position, true)
})
</script>

<template>
  <button
    ref="triggerEl"
    type="button"
    title="Pick an icon"
    :class="[
      'flex items-center justify-center border border-paper-400 bg-paper-50 text-ink-800',
      'hover:border-accent-400 focus:border-accent-400 focus:outline-none',
      size === 'sm' ? 'h-8 w-9 text-base' : 'h-10 w-11 text-lg',
    ]"
    @click="toggle"
  >
    <span v-if="modelValue">{{ modelValue }}</span>
    <span v-else class="text-mist-500">🙂</span>
  </button>

  <Teleport to="body">
    <div
      v-if="open"
      ref="panelEl"
      class="fixed z-50 flex flex-col overflow-hidden border border-paper-400 bg-paper-50 text-ink-800 shadow-xl"
      :style="panelStyle"
    >
      <div class="border-b border-paper-300 p-2">
        <input
          v-model="search"
          placeholder="Search icons…"
          autofocus
          class="w-full border border-paper-400 bg-white px-2 py-1 text-sm focus:border-accent-400 focus:outline-none"
        >
      </div>

      <div class="grid max-h-56 grid-cols-8 gap-0.5 overflow-y-auto p-2">
        <button
          v-for="item in filtered"
          :key="item.e"
          type="button"
          :title="item.k"
          :class="[
            'flex h-8 w-8 items-center justify-center text-lg hover:bg-accent-100',
            item.e === modelValue ? 'bg-accent-100 ring-1 ring-accent-400' : '',
          ]"
          @click="choose(item.e)"
        >
          {{ item.e }}
        </button>
        <p v-if="filtered.length === 0" class="col-span-8 px-2 py-4 text-center text-sm text-mist-700">
          No matches — type your own below.
        </p>
      </div>

      <div class="flex items-center gap-2 border-t border-paper-300 p-2">
        <input
          v-model="custom"
          placeholder="Or paste any emoji"
          maxlength="8"
          class="w-full border border-paper-400 bg-white px-2 py-1 text-sm focus:border-accent-400 focus:outline-none"
          @keydown.enter.prevent="applyCustom"
        >
        <button
          type="button"
          class="shrink-0 bg-accent-400 px-2.5 py-1 text-xs font-medium text-ink-900 hover:bg-accent-500"
          @click="applyCustom"
        >
          Set
        </button>
        <button
          type="button"
          title="Remove icon"
          class="shrink-0 border border-paper-400 px-2 py-1 text-xs text-mist-700 hover:bg-paper-200"
          @click="choose('')"
        >
          Clear
        </button>
      </div>
    </div>
  </Teleport>
</template>
