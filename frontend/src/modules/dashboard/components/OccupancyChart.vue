<script setup lang="ts">
import { computed } from 'vue'
import {
  Chart as ChartJS,
  Title, Tooltip, Legend,
  LineElement, PointElement,
  CategoryScale, LinearScale, Filler,
} from 'chart.js'
import { Line } from 'vue-chartjs'
import type { DailyDataPoint } from '@/types/entities'

ChartJS.register(Title, Tooltip, Legend, LineElement, PointElement, CategoryScale, LinearScale, Filler)

const props = defineProps<{ dailySeries: DailyDataPoint[] }>()

function formatDateLabel(iso: string): string {
  const d = new Date(iso + 'T00:00:00')
  return d.toLocaleDateString('fr-SN', { day: '2-digit', month: '2-digit' })
}

const chartData = computed(() => ({
  labels: props.dailySeries.map(p => formatDateLabel(p.date)),
  datasets: [{
    label: 'Occupation (%)',
    data: props.dailySeries.map(p => Number(p.occupancyRate)),
    borderColor: '#1D6E6E',
    backgroundColor: 'rgba(29, 110, 110, 0.08)',
    fill: true,
    tension: 0.3,
    pointRadius: props.dailySeries.length > 31 ? 0 : 3,
    pointBackgroundColor: '#1D6E6E',
  }],
}))

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (ctx: { parsed: { y: number | null } }) => `${(ctx.parsed.y ?? 0).toFixed(1)} %`,
      },
    },
  },
  scales: {
    y: {
      min: 0,
      max: 100,
      ticks: { callback: (v: string | number) => `${v}%`, font: { size: 11 } },
      grid: { color: 'rgba(0,0,0,0.04)' },
    },
    x: {
      ticks: { font: { size: 11 }, maxRotation: 45 },
      grid: { display: false },
    },
  },
}))
</script>

<template>
  <div style="height:260px;">
    <Line :data="chartData" :options="chartOptions" />
  </div>
</template>
