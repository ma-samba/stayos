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

const chartData = computed(() => ({
  labels: props.dailySeries.map(p => formatDateLabel(p.date)),
  datasets: [{
    label: 'Nuits vendues',
    data: props.dailySeries.map(p => p.soldNights),
    backgroundColor: '#2B5BA8',
    borderRadius: 4,
    maxBarThickness: 32,
  }],
}))

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
  },
  scales: {
    y: {
      beginAtZero: true,
      ticks: {
        stepSize: 1,
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
