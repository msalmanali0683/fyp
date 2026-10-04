<template>
  <div :class="containerClass">
    <Bar :data="chartData" :options="mergedOptions" />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Bar } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend,
} from 'chart.js'

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend)

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
    legend: { display: false },
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
