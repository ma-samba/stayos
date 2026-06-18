<script setup lang="ts">
import { computed } from 'vue'
import {
  Chart as ChartJS,
  Title, Tooltip, Legend,
  BarElement,
  CategoryScale, LinearScale,
} from 'chart.js'
import { Bar } from 'vue-chartjs'
import type { DailyDataPoint } from '@/types/entities'

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale)

const props = defineProps<{ dailySeries: DailyDataPoint[] }>()

function formatDateLabel(iso: string): string {
  const d = new Date(iso + 'T00:00:00')
  return d.toLocaleDateString('fr-SN', { day: '2-digit', month: '2-digit' })
}

function formatXof(v: number): string {
  return new Intl.NumberFormat('fr-SN', { style: 'decimal', maximumFractionDigits: 0 }).format(v)
}

const chartData = computed(() => ({
  labels: props.dailySeries.map(p => formatDateLabel(p.date)),
  datasets: [{
    label: 'CA HT (XOF)',
    data: props.dailySeries.map(p => Number(p.roomRevenueHt)),
    backgroundColor: '#C4922A',
    borderRadius: 4,
    maxBarThickness: 32,
  }],
}))

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (ctx: { parsed: { y: number | null } }) => `${formatXof(ctx.parsed.y ?? 0)} XOF`,
      },
    },
  },
  scales: {
    y: {
      beginAtZero: true,
      ticks: {
        callback: (v: string | number) => formatXof(typeof v === 'number' ? v : Number(v)),
        font: { size: 11 },
      },
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
    <Bar :data="chartData" :options="chartOptions" />
  </div>
</template>
