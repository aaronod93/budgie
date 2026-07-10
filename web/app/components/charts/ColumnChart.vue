<script setup lang="ts">
// Grouped column chart (1-2 series), hand-rolled SVG per the dataviz method:
// thin marks, 4px rounded data-ends at the top only, 2px surface gap between
// adjacent columns, recessive gridlines, ink-colored text, hover tooltip.
export interface ColumnSeries {
  name: string
  color: string
  values: number[]
}

const props = defineProps<{
  labels: string[]
  series: ColumnSeries[]
  currency?: string
}>()

const W = 560
const H = 220
const PAD = { top: 12, right: 8, bottom: 24, left: 46 }
const hovered = ref<number | null>(null)

const maxValue = computed(() => {
  const all = props.series.flatMap(s => s.values)
  return Math.max(...all, 1)
})

const ticks = computed(() => {
  const step = niceStep(maxValue.value / 3)
  const out: number[] = []
  for (let v = 0; v <= maxValue.value + step * 0.001; v += step) out.push(v)
  return out
})

const top = computed(() => ticks.value[ticks.value.length - 1] ?? maxValue.value)

function niceStep(raw: number): number {
  const pow = 10 ** Math.floor(Math.log10(raw))
  const unit = raw / pow
  return (unit <= 1 ? 1 : unit <= 2 ? 2 : unit <= 5 ? 5 : 10) * pow
}

const plotW = W - PAD.left - PAD.right
const plotH = H - PAD.top - PAD.bottom

function y(value: number): number {
  return PAD.top + plotH - (value / top.value) * plotH
}

function slotX(index: number): number {
  return PAD.left + (plotW / props.labels.length) * index
}

const slotWidth = computed(() => plotW / props.labels.length)
const barWidth = computed(() =>
  Math.min(22, Math.max(4, (slotWidth.value - 8) / props.series.length - 2)))

/** Column path with 4px rounding on the top (data end) only. */
function columnPath(x: number, value: number, width: number): string {
  const yTop = y(value)
  const yBase = PAD.top + plotH
  const r = Math.min(4, width / 2, Math.max(0, yBase - yTop))
  if (yBase - yTop < 1) return ''
  return `M ${x} ${yBase} V ${yTop + r} Q ${x} ${yTop} ${x + r} ${yTop} H ${x + width - r} Q ${x + width} ${yTop} ${x + width} ${yTop + r} V ${yBase} Z`
}

function barX(index: number, seriesIndex: number): number {
  const group = props.series.length * barWidth.value + (props.series.length - 1) * 2
  return slotX(index) + (slotWidth.value - group) / 2 + seriesIndex * (barWidth.value + 2)
}

function compact(cents: number): string {
  const dollars = cents / 100
  if (Math.abs(dollars) >= 1000) return `$${(dollars / 1000).toFixed(dollars >= 10000 ? 0 : 1)}k`
  return `$${Math.round(dollars)}`
}

const labelEvery = computed(() => Math.ceil(props.labels.length / 6))
</script>

<template>
  <div class="relative">
    <div v-if="series.length > 1" class="mb-2 flex gap-4">
      <span v-for="s in series" :key="s.name" class="flex items-center gap-1.5 text-xs text-slate-600">
        <span class="h-2.5 w-2.5 rounded-sm" :style="{ background: s.color }" />
        {{ s.name }}
      </span>
    </div>

    <svg :viewBox="`0 0 ${W} ${H}`" class="w-full" role="img">
      <g v-for="tick in ticks" :key="tick">
        <line :x1="PAD.left" :x2="W - PAD.right" :y1="y(tick)" :y2="y(tick)" stroke="#e2e8f0" stroke-width="1" />
        <text :x="PAD.left - 6" :y="y(tick) + 3" text-anchor="end" class="fill-slate-400" font-size="10">
          {{ compact(tick) }}
        </text>
      </g>

      <g v-for="(label, i) in labels" :key="label">
        <rect
          :x="slotX(i)" :y="PAD.top" :width="slotWidth" :height="plotH"
          fill="transparent"
          @mouseenter="hovered = i"
          @mouseleave="hovered = null"
        />
        <path
          v-for="(s, si) in series"
          :key="s.name"
          :d="columnPath(barX(i, si), s.values[i] ?? 0, barWidth)"
          :fill="s.color"
          :opacity="hovered === null || hovered === i ? 1 : 0.35"
          pointer-events="none"
        />
        <text
          v-if="i % labelEvery === 0"
          :x="slotX(i) + slotWidth / 2"
          :y="H - 8"
          text-anchor="middle"
          class="fill-slate-400"
          font-size="10"
        >
          {{ label }}
        </text>
      </g>
    </svg>

    <div
      v-if="hovered !== null"
      class="pointer-events-none absolute rounded-md border border-slate-200 bg-white px-3 py-2 text-xs shadow-md"
      :style="{
        left: `${((slotX(hovered) + slotWidth / 2) / W) * 100}%`,
        top: '0px',
        transform: slotX(hovered) > W / 2 ? 'translateX(-100%)' : undefined,
      }"
    >
      <p class="font-semibold text-slate-700">{{ labels[hovered] }}</p>
      <p v-for="s in series" :key="s.name" class="mt-0.5 flex items-center gap-1.5 text-slate-600">
        <span class="h-2 w-2 rounded-sm" :style="{ background: s.color }" />
        {{ s.name }}: {{ formatMoney(s.values[hovered] ?? 0, currency) }}
      </p>
    </div>
  </div>
</template>
