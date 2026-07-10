<script setup lang="ts">
// Single-series line/area with a zero baseline (net worth can go negative):
// 2px line, subtle area fill, crosshair hover with >=8px marker + tooltip.
const props = defineProps<{
  labels: string[]
  values: number[]
  color: string
  currency?: string
}>()

const W = 560
const H = 220
const PAD = { top: 12, right: 8, bottom: 24, left: 52 }
const hovered = ref<number | null>(null)

const min = computed(() => Math.min(0, ...props.values))
const max = computed(() => Math.max(0, ...props.values, 1))

const plotW = W - PAD.left - PAD.right
const plotH = H - PAD.top - PAD.bottom

function x(index: number): number {
  const n = Math.max(props.values.length - 1, 1)
  return PAD.left + (plotW / n) * index
}

function y(value: number): number {
  return PAD.top + plotH - ((value - min.value) / (max.value - min.value)) * plotH
}

const linePath = computed(() =>
  props.values.map((v, i) => `${i === 0 ? 'M' : 'L'} ${x(i).toFixed(1)} ${y(v).toFixed(1)}`).join(' '))

const areaPath = computed(() => {
  if (props.values.length === 0) return ''
  return `${linePath.value} L ${x(props.values.length - 1)} ${y(0)} L ${x(0)} ${y(0)} Z`
})

const ticks = computed(() => {
  const range = max.value - min.value
  const step = niceStep(range / 3)
  const start = Math.ceil(min.value / step) * step
  const out: number[] = []
  for (let v = start; v <= max.value + step * 0.001; v += step) out.push(v)
  return out
})

function niceStep(raw: number): number {
  const pow = 10 ** Math.floor(Math.log10(Math.max(raw, 1)))
  const unit = raw / pow
  return (unit <= 1 ? 1 : unit <= 2 ? 2 : unit <= 5 ? 5 : 10) * pow
}

function compact(cents: number): string {
  const dollars = cents / 100
  if (Math.abs(dollars) >= 1000) return `$${(dollars / 1000).toFixed(Math.abs(dollars) >= 10000 ? 0 : 1)}k`
  return `$${Math.round(dollars)}`
}

function onMove(event: MouseEvent) {
  const svg = event.currentTarget as SVGSVGElement
  const rect = svg.getBoundingClientRect()
  const px = ((event.clientX - rect.left) / rect.width) * W
  const n = Math.max(props.values.length - 1, 1)
  const index = Math.round(((px - PAD.left) / plotW) * n)
  hovered.value = Math.max(0, Math.min(props.values.length - 1, index))
}

const labelEvery = computed(() => Math.ceil(props.labels.length / 6))
</script>

<template>
  <div class="relative">
    <svg :viewBox="`0 0 ${W} ${H}`" class="w-full" role="img" @mousemove="onMove" @mouseleave="hovered = null">
      <g v-for="tick in ticks" :key="tick">
        <line
          :x1="PAD.left" :x2="W - PAD.right" :y1="y(tick)" :y2="y(tick)"
          :stroke="tick === 0 ? '#8f96a3' : '#d8d6cf'"
          stroke-width="1"
        />
        <text :x="PAD.left - 6" :y="y(tick) + 3" text-anchor="end" class="fill-mist-700" font-size="10">
          {{ compact(tick) }}
        </text>
      </g>

      <path :d="areaPath" :fill="color" opacity="0.08" />
      <path :d="linePath" :stroke="color" stroke-width="2" fill="none" stroke-linejoin="round" />

      <g v-for="(label, i) in labels" :key="label">
        <text
          v-if="i % labelEvery === 0"
          :x="x(i)" :y="H - 8" text-anchor="middle" class="fill-mist-700" font-size="10"
        >
          {{ label }}
        </text>
      </g>

      <g v-if="hovered !== null">
        <line :x1="x(hovered)" :x2="x(hovered)" :y1="PAD.top" :y2="PAD.top + plotH" stroke="#b9b6ac" stroke-width="1" />
        <circle :cx="x(hovered)" :cy="y(values[hovered] ?? 0)" r="4.5" :fill="color" stroke="#f4f3f0" stroke-width="2" />
      </g>
    </svg>

    <div
      v-if="hovered !== null"
      class="pointer-events-none absolute rounded-md border border-paper-400 bg-paper-50 px-3 py-2 text-xs shadow-md"
      :style="{
        left: `${(x(hovered) / W) * 100}%`,
        top: '0px',
        transform: x(hovered) > W / 2 ? 'translateX(-100%)' : undefined,
      }"
    >
      <p class="font-semibold text-ink-700">{{ labels[hovered] }}</p>
      <p class="mt-0.5 text-ink-600">{{ formatMoney(values[hovered] ?? 0, currency) }}</p>
    </div>
  </div>
</template>
