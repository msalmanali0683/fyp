<template>
  <div>
    <PageHeader
      title="FYP Project"
      subtitle="Proposal workflow review"
      :breadcrumb-trail="breadcrumbTrail"
    >
      <template #actions>
        <router-link :to="projectsListRoute" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-arrow-left me-1"></i> Back
        </router-link>
      </template>
    </PageHeader>
    <ProposalWorkflow v-if="projectId" :project-id="projectId" />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import PageHeader from '@/components/ui/PageHeader.vue'
import ProposalWorkflow from '@/views/projects/ProposalWorkflow.vue'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const authStore = useAuthStore()
const projectId = computed(() => route.params.id)

const projectsListRoute = computed(() => {
  if (authStore.canViewAllProjects) return '/projects'
  if (authStore.hasRole('evaluator')) return '/evaluator/assigned-projects'
  if (authStore.hasRole('supervisor')) return '/supervisor/projects'
  return '/projects'
})

const breadcrumbTrail = computed(() => [
  { label: 'Projects', to: projectsListRoute.value },
  { label: `Project #${projectId.value}` },
])
</script>
