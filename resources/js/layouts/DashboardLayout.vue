<template>
  <div class="layout-wrapper">
    <div
      class="sidebar-overlay"
      :class="{ show: appStore.sidebarOpen }"
      @click="appStore.closeSidebar()"
    />

    <AppSidebar />

    <div
      class="layout-page"
      :class="{ 'sidebar-collapsed': appStore.sidebarCollapsed }"
    >
      <AppNavbar :title="pageTitle" :breadcrumb="breadcrumb" />

      <div class="content-wrapper">
        <div class="container-xxl container-p-y">
          <router-view />
        </div>
        <AppFooter />
      </div>
    </div>

    <AppToastContainer />
    <AppConfirmModal />
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAppStore } from '@/stores/app'
import { useNotificationStore } from '@/stores/notifications'
import AppSidebar from '@/components/layout/AppSidebar.vue'
import AppNavbar from '@/components/layout/AppNavbar.vue'
import AppFooter from '@/components/layout/AppFooter.vue'
import AppToastContainer from '@/components/ui/AppToastContainer.vue'
import AppConfirmModal from '@/components/ui/AppConfirmModal.vue'

const route = useRoute()
const appStore = useAppStore()
const notificationStore = useNotificationStore()

const pageTitle = computed(() => route.meta.title || 'Dashboard')
const breadcrumb = computed(() => route.meta.breadcrumb || 'Dashboard')

onMounted(() => {
  notificationStore.init()
})
</script>
