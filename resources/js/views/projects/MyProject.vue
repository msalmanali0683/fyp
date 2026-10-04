<template>
  <div>
    <PageHeader title="My Project" subtitle="Proposal phase workflow" breadcrumb="My Project" />
    <PendingInvitations @updated="reloadWorkflow" />
    <ProposalWorkflow ref="workflowRef" can-register />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ProposalWorkflow from '@/views/projects/ProposalWorkflow.vue'
import PendingInvitations from '@/components/proposal/PendingInvitations.vue'

const workflowRef = ref(null)

const reloadWorkflow = async () => {
  await workflowRef.value?.loadProject?.()
  await workflowRef.value?.loadFormData?.()
}
</script>
