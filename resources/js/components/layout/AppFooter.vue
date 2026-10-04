<template>
  <footer class="app-footer">
    <span class="app-footer__copyright">© {{ year }} FYP Admin Dashboard · Built with Vue.js & Bootstrap</span>
    <DeveloperCredit />
  </footer>
</template>

<script setup>
import DeveloperCredit from '@/components/layout/DeveloperCredit.vue'

const year = new Date().getFullYear()
</script>
