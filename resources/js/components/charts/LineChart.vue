<template>
  <div :class="containerClass">
    <Line :data="chartData" :options="mergedOptions" />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js'

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
)

const props = defineProps({
  labels: { type: Array, default: () => [] },
  datasets: { type: Array, default: () => [] },
  height: { type: String, default: 'default' },
})

const containerClass = computed(() =>
  props.height === 'sm' ? 'chart-container-sm' : 'chart-container'
)

const chartData = computed(() => ({
  labels: props.labels,
  datasets: props.datasets,
}))

const mergedOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'top',
      labels: { usePointStyle: true, boxWidth: 8 },
    },
  },
  scales: {
    x: {
      grid: { display: false },
      ticks: { color: '#a8aaae' },
    },
    y: {
      grid: { color: 'rgba(34, 41, 47, 0.06)' },
      ticks: { color: '#a8aaae' },
    },
  },
}))
</script>
