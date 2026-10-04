<template>
  <div>
    <PageHeader
      title="Supervisor Change Requests"
      subtitle="Pending requests from students to change their project supervisor"
      breadcrumb="Supervisor Change Requests"
    />

    <div v-if="loadError" class="alert alert-danger py-2">{{ loadError }}</div>

    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary"></div>
    </div>

    <EmptyState
      v-else-if="!requests.length"
      title="No pending requests"
      description="Every supervisor change request has been resolved."
      icon="bi bi-check2-circle"
    />

    <div v-else class="d-flex flex-column gap-3">
      <AppCard v-for="req in requests" :key="req.id">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
          <div>
            <router-link :to="`/projects/${req.project_id}`" class="fw-semibold text-decoration-none">
              {{ req.project?.title }}
            </router-link>
            <div class="text-muted small">Requested by {{ req.requested_by?.name }} · {{ req.created_at }}</div>
          </div>
          <AppBadge :variant="scOverallVariant(req.overall_status)">{{ scOverallLabel(req.overall_status) }}</AppBadge>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <div class="sc-info">
              <div class="sc-info__label">Current Supervisor</div>
              <div class="sc-info__value">{{ req.current_supervisor?.name || '—' }}</div>
              <AppBadge :variant="scStatusVariant(req.current_supervisor_status)">{{ scStatusLabel(req.current_supervisor_status) }}</AppBadge>
            </div>
          </div>
          <div class="col-md-6">
            <div class="sc-info">
              <div class="sc-info__label">Requested New Supervisor</div>
              <div class="sc-info__value">{{ req.new_supervisor?.name || '—' }}</div>
              <AppBadge :variant="scStatusVariant(req.new_supervisor_status)">{{ scStatusLabel(req.new_supervisor_status) }}</AppBadge>
            </div>
          </div>
        </div>

        <div class="alert alert-light border py-2 mb-3">
          <strong>Reason:</strong> {{ req.reason }}
        </div>

        <div class="d-flex flex-wrap gap-2">
          <template v-if="req.viewer_can_manage && req.overall_status === 'pending'">
            <button
              v-if="req.current_supervisor_status === 'pending'"
              type="button"
              class="btn btn-success btn-sm"
              :disabled="saving"
              @click="respondOnBehalf(req, 'current', true)"
            >
              Accept on Behalf of Current Supervisor
            </button>
            <button
              v-if="req.current_supervisor_status === 'pending'"
              type="button"
              class="btn btn-outline-danger btn-sm"
              :disabled="saving"
              @click="respondOnBehalf(req, 'current', false)"
            >
              Reject on Behalf of Current Supervisor
            </button>
            <button
              v-if="req.new_supervisor_status === 'pending'"
              type="button"
              class="btn btn-success btn-sm"
              :disabled="saving"
              @click="respondOnBehalf(req, 'new', true)"
            >
              Accept on Behalf of New Supervisor
            </button>
            <button
              v-if="req.new_supervisor_status === 'pending'"
              type="button"
              class="btn btn-outline-danger btn-sm"
              :disabled="saving"
              @click="respondOnBehalf(req, 'new', false)"
            >
              Reject on Behalf of New Supervisor
            </button>
          </template>

          <template v-if="req.viewer_can_decide && req.overall_status === 'awaiting_authority'">
            <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="decide(req, true)">
              Approve &amp; Change Supervisor
            </button>
            <button type="button" class="btn btn-danger btn-sm" :disabled="saving" @click="decide(req, false)">
              Decline
            </button>
          </template>

          <router-link :to="`/projects/${req.project_id}`" class="btn btn-outline-secondary btn-sm ms-auto">
            Open Project
          </router-link>
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
import { fetchSupervisorChangeRequests } from '@/api/supervisors'
import { respondSupervisorChangeRequest, decideSupervisorChangeRequest } from '@/api/proposals'
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
    const res = await fetchSupervisorChangeRequests()
    requests.value = res.data || []
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to load supervisor change requests.')
  } finally {
    loading.value = false
  }
}

const scStatusLabel = (status) => ({
  pending: 'Pending',
  accepted: 'Accepted',
  rejected: 'Rejected',
}[status] || status)

const scStatusVariant = (status) => ({
  pending: 'warning',
  accepted: 'success',
  rejected: 'danger',
}[status] || 'secondary')

const scOverallLabel = (status) => ({
  pending: 'Awaiting Supervisors',
  awaiting_authority: 'Awaiting Decision',
  approved: 'Approved',
  declined: 'Declined',
  cancelled: 'Cancelled',
}[status] || status)

const scOverallVariant = (status) => ({
  pending: 'warning',
  awaiting_authority: 'info',
  approved: 'success',
  declined: 'danger',
  cancelled: 'secondary',
}[status] || 'secondary')

const respondOnBehalf = async (req, which, accept) => {
  const label = which === 'current' ? 'current supervisor' : 'new supervisor'
  const comments = await confirmDialog.confirm({
    title: accept ? 'Accept on Behalf' : 'Reject on Behalf',
    message: `${accept ? 'Accept' : 'Reject'} this request on behalf of the ${label}?`,
    confirmText: accept ? 'Accept' : 'Reject',
    variant: accept ? 'primary' : 'danger',
    prompt: true,
    promptLabel: accept ? 'Optional comments' : 'Reason (required)',
    promptRequired: !accept,
  })

  if (comments === false) return

  saving.value = true
  try {
    await respondSupervisorChangeRequest(req.project_id, req.id, {
      accept,
      which,
      comments: comments === true ? null : comments,
    })
    toast.success('Response recorded on behalf of the supervisor.')
    await load()
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to record response.'))
  } finally {
    saving.value = false
  }
}

const decide = async (req, approve) => {
  const comments = await confirmDialog.confirm({
    title: approve ? 'Approve Supervisor Change' : 'Decline Supervisor Change',
    message: approve
      ? `Approve changing the supervisor to ${req.new_supervisor?.name}?`
      : 'Decline this supervisor change request?',
    confirmText: approve ? 'Approve' : 'Decline',
    variant: approve ? 'primary' : 'danger',
    prompt: true,
    promptLabel: approve ? 'Optional comments' : 'Reason (required)',
    promptRequired: !approve,
  })

  if (comments === false) return

  saving.value = true
  try {
    await decideSupervisorChangeRequest(req.project_id, req.id, {
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
  margin-bottom: 0.25rem;
}
</style>
