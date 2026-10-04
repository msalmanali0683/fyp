<template>
  <div>
    <PageHeader
      title="Student Transfer Requests"
      subtitle="Pending requests from students to transfer to a different project team"
      breadcrumb="Student Transfer Requests"
    />

    <div v-if="loadError" class="alert alert-danger py-2">{{ loadError }}</div>

    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary"></div>
    </div>

    <EmptyState
      v-else-if="!requests.length"
      title="No pending requests"
      description="Every student transfer request has been resolved."
      icon="bi bi-check2-circle"
    />

    <div v-else class="d-flex flex-column gap-3">
      <AppCard v-for="req in requests" :key="req.id">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
          <div>
            <div class="fw-semibold">{{ req.student?.name }}</div>
            <div class="text-muted small">Requested {{ req.created_at }}</div>
          </div>
          <AppBadge :variant="statusVariant(req.status)">{{ req.status_label }}</AppBadge>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <div class="sc-info">
              <div class="sc-info__label">From Project</div>
              <router-link :to="`/projects/${req.from_project_id}`" class="sc-info__value d-block text-decoration-none">
                {{ req.from_project?.title }}
              </router-link>
            </div>
          </div>
          <div class="col-md-6">
            <div class="sc-info">
              <div class="sc-info__label">To Project</div>
              <router-link v-if="req.to_project_id" :to="`/projects/${req.to_project_id}`" class="sc-info__value d-block text-decoration-none">
                {{ req.to_project?.title }}
              </router-link>
              <span v-else class="text-muted">Not yet selected</span>
            </div>
          </div>
        </div>

        <div class="alert alert-light border py-2 mb-3">
          <strong>Reason:</strong> {{ req.reason }}
        </div>

        <div v-if="req.status === 'eligible'" class="alert alert-info py-2 mb-3 small">
          Approved — waiting for the student to choose a group to request joining.
        </div>

        <div class="d-flex flex-wrap gap-2">
          <template v-if="req.viewer_can_decide">
            <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="decide(req, true)">
              {{ req.status === 'pending_leader' ? 'Accept Into Group' : 'Approve Request' }}
            </button>
            <button type="button" class="btn btn-danger btn-sm" :disabled="saving" @click="decide(req, false)">
              Decline
            </button>
          </template>
        </div>
      </AppCard>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { fetchTransferRequests, decideTransferRequest } from '@/api/transfers'
import { confirmDialog } from '@/composables/useConfirm'
import { toast } from '@/composables/useToast'
import { formatApiError } from '@/utils/apiErrors'

const requests = ref([])
const loading = ref(true)
const loadError = ref('')
const saving = ref(false)

const load = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const res = await fetchTransferRequests()
    requests.value = res.data || []
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to load transfer requests.')
  } finally {
    loading.value = false
  }
}

const statusVariant = (status) => ({
  pending: 'warning',
  eligible: 'info',
  pending_leader: 'warning',
  approved: 'success',
  rejected: 'danger',
  cancelled: 'secondary',
}[status] || 'secondary')

const decide = async (req, approve) => {
  const isLeaderStage = req.status === 'pending_leader'

  const comments = await confirmDialog.confirm({
    title: approve
      ? (isLeaderStage ? 'Accept Into Group' : 'Approve Transfer Request')
      : (isLeaderStage ? 'Decline Join Request' : 'Decline Transfer Request'),
    message: approve
      ? (isLeaderStage
          ? `Accept ${req.student?.name} into "${req.to_project?.title}"?`
          : `Approve ${req.student?.name}'s request to look for a new group?`)
      : (isLeaderStage
          ? `Decline ${req.student?.name}'s request to join "${req.to_project?.title}"?`
          : 'Decline this transfer request?'),
    confirmText: approve ? 'Approve' : 'Decline',
    variant: approve ? 'primary' : 'danger',
    prompt: true,
    promptLabel: approve ? 'Optional comments' : 'Reason (required)',
    promptRequired: !approve && !isLeaderStage,
  })

  if (comments === false) return

  saving.value = true
  try {
    await decideTransferRequest(req.id, {
      approve,
      comments: comments === true ? null : comments,
    })
    toast.success('Decision recorded.')
    await load()
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to record decision.'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.sc-info__label {
  font-size: 0.75rem;
  text-transform: uppercase;
  color: #6e6b7b;
}

.sc-info__value {
  font-weight: 600;
}
</style>
