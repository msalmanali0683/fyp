<template>
  <div :class="containerClass">
    <Doughnut :data="chartData" :options="mergedOptions" />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js'

ChartJS.register(ArcElement, Tooltip, Legend)

const props = defineProps({
  labels: { type: Array, default: () => [] },
  datasets: { type: Array, default: () => [] },
  height: { type: String, default: 'sm' },
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
  cutout: '68%',
  plugins: {
    legend: {
      position: 'bottom',
      labels: { usePointStyle: true, boxWidth: 8, padding: 16 },
    },
  },
}))
</script>
