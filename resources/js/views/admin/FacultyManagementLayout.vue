<template>
  <div>
    <PageHeader
      title="Manage Supervisors & Evaluators"
      subtitle="Faculty members, supervisor assignments, and evaluator assignments"
      breadcrumb="Faculty Management"
    />

    <ul class="nav nav-tabs faculty-mgmt-tabs mb-4">
      <li v-if="authStore.isAdmin" class="nav-item">
        <router-link
          class="nav-link"
          :class="{ active: route.name === 'admin-faculty-management-supervisors' }"
          :to="{ name: 'admin-faculty-management-supervisors' }"
        >
          Supervisors
        </router-link>
      </li>
      <li v-if="authStore.canManageEvaluators" class="nav-item">
        <router-link
          class="nav-link"
          :class="{ active: route.name === 'admin-faculty-management-evaluators' }"
          :to="{ name: 'admin-faculty-management-evaluators' }"
        >
          Evaluators
        </router-link>
      </li>
      <li v-if="authStore.isAdmin" class="nav-item">
        <router-link
          class="nav-link"
          :class="{ active: route.name === 'admin-faculty-management-faculty' }"
          :to="{ name: 'admin-faculty-management-faculty' }"
        >
          Faculty
        </router-link>
      </li>
    </ul>

    <router-view />
  </div>
</template>

<script setup>
import { useRoute } from 'vue-router'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const authStore = useAuthStore()
</script>

<style scoped>
.faculty-mgmt-tabs .nav-link {
  color: #6e6b7b;
  border: 0;
  border-bottom: 2px solid transparent;
  padding: 0.65rem 1rem;
}

.faculty-mgmt-tabs .nav-link.active {
  color: #7367f0;
  border-bottom-color: #7367f0;
  background: transparent;
}
</style>
