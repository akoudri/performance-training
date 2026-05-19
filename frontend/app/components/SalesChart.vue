<script setup lang="ts">
/**
 * Composant ventes Chart.js — encapsule l'init Chart, le `register` des
 * composants utilisés et le binding au canvas. Importé via
 * `defineAsyncComponent` depuis pages/organizer/dashboard.vue, ce qui
 * évacue Chart.js du bundle initial des pages organizer (cf. layout
 * `layouts/organizer.vue` qui n'importe plus Chart.js statiquement).
 *
 * Économie attendue : ~160 Ko raw / ~56 Ko gzipped sur First Load JS de
 * /organizer/events, /organizer/events/{id}/edit, .../participants. Seul
 * le dashboard tire la lib, et seulement après mount.
 *
 * @perf-fix: code splitting Chart.js — solution/j2-bundle.
 */
import {
  Chart,
  LineController,
  LineElement,
  PointElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js'

Chart.register(
  LineController,
  LineElement,
  PointElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  Legend,
  Filler,
)

interface SalesPoint {
  day: string
  orders: number
  revenue_cents: number
}

const props = defineProps<{ points: SalesPoint[] }>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chartInstance: Chart | null = null

function render() {
  if (!canvas.value) return
  if (chartInstance) {
    chartInstance.data.labels = props.points.map(p => p.day.slice(0, 10))
    chartInstance.data.datasets[0]!.data = props.points.map(p => p.revenue_cents / 100)
    chartInstance.update('none')
    return
  }
  chartInstance = new Chart(canvas.value, {
    type: 'line',
    data: {
      labels: props.points.map(p => p.day.slice(0, 10)),
      datasets: [{
        label: 'Revenus (€)',
        data: props.points.map(p => p.revenue_cents / 100),
        borderColor: '#4655e8',
        backgroundColor: 'rgba(70, 85, 232, 0.15)',
        fill: true,
        tension: 0.25,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } },
    },
  })
}

watch(() => props.points, render, { immediate: false })
onMounted(render)
onBeforeUnmount(() => chartInstance?.destroy())
</script>

<template>
  <div class="h-64">
    <canvas ref="canvas" />
  </div>
</template>
