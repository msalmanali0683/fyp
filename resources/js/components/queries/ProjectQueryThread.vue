<template>
  <div class="query-thread">
    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
      <div>
        <h6 class="mb-1">{{ query.subject }}</h6>
        <div class="text-muted small">
          <a v-if="query.project" :href="projectUrl" target="_blank" rel="noopener">{{ query.project.title }}</a>
          <span v-else>{{ query.project?.title }}</span>
          · Raised by {{ query.raiser?.name || 'Unknown' }} ({{ roleLabel(query.raised_by_role) }})
          <template v-if="query.project?.supervisor">
            · Supervisor:
            <a v-if="canViewSupervisorOverview" :href="supervisorUrl" target="_blank" rel="noopener">
              {{ query.project.supervisor.name }}
            </a>
            <span v-else>{{ query.project.supervisor.name }}</span>
          </template>
        </div>
      </div>
      <AppBadge :variant="statusVariant">{{ query.status_label }}</AppBadge>
    </div>

    <div class="query-thread__messages mb-3">
      <div v-for="message in query.messages" :key="message.id" class="query-message">
        <div class="query-message__meta">
          <strong>{{ message.author?.name || 'Unknown' }}</strong>
          <span class="text-muted"> ({{ roleLabel(message.author_role) }}) · {{ message.created_at }}</span>
        </div>
        <div class="query-message__text">{{ message.message }}</div>
      </div>
      <div v-if="!query.messages?.length" class="text-muted small">No messages yet.</div>
    </div>

    <div v-if="query.can_reply" class="mb-3">
      <textarea v-model="replyText" class="form-control mb-2" rows="3" placeholder="Write a reply..."></textarea>
      <button type="button" class="btn btn-primary btn-sm" :disabled="saving || !replyText.trim()" @click="submitReply">
        Send Reply
      </button>
    </div>
    <div v-else-if="query.status === 'closed'" class="alert alert-secondary py-2 mb-3 small">
      This query is closed and no longer accepts new messages. Only an admin, committee head, or user with permission can reopen it.
    </div>

    <div v-if="actionError" class="alert alert-danger py-2">{{ actionError }}</div>

    <div class="d-flex flex-wrap gap-2">
      <button v-if="query.can_close" type="button" class="btn btn-outline-warning btn-sm" :disabled="saving" @click="doClose">
        Close Query
      </button>
      <button v-if="query.can_reopen" type="button" class="btn btn-outline-secondary btn-sm" :disabled="saving" @click="doReopen">
        Reopen Query
      </button>
      <button v-if="query.can_download" type="button" class="btn btn-outline-primary btn-sm" :disabled="saving" @click="downloadPdf">
        <i class="bi bi-download me-1"></i> Download PDF
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import {
  replyToProjectQuery,
  closeProjectQuery,
  reopenProjectQuery,
  downloadProjectQueryPdf,
} from '@/api/projectQueries'
import { useAuthStore } from '@/stores/auth'
import { getBaseUrl } from '@/utils/baseUrl'
import { formatApiError } from '@/utils/apiErrors'

const props = defineProps({
  query: { type: Object, required: true },
})

const emit = defineEmits(['updated'])

const authStore = useAuthStore()

const replyText = ref('')
const saving = ref(false)
const actionError = ref('')

const canViewSupervisorOverview = computed(() => authStore.canViewSupervisorOverview)

const projectUrl = computed(() =>
  props.query.project ? `${getBaseUrl()}/projects/${props.query.project.id}` : null
)

const supervisorUrl = computed(() =>
  props.query.project?.supervisor ? `${getBaseUrl()}/admin/supervisors/${props.query.project.supervisor.id}/projects` : null
)

const statusVariant = computed(() => ({
  open: 'warning',
  answered: 'info',
  closed: 'secondary',
}[props.query.status] || 'secondary'))

const roleLabels = {
  student: 'Student',
  supervisor: 'Supervisor',
  evaluator: 'Evaluator',
  staff: 'FYP Office',
  admin: 'Admin',
  'fyp-committee-head': 'FYP Committee Head',
  'fyp-committee-member': 'FYP Committee Member',
  responder: 'FYP Office',
}

const roleLabel = (role) => roleLabels[role] || role

const submitReply = async () => {
  if (!replyText.value.trim()) return
  saving.value = true
  actionError.value = ''
  try {
    const res = await replyToProjectQuery(props.query.id, { message: replyText.value.trim() })
    replyText.value = ''
    emit('updated', res.data)
  } catch (err) {
    actionError.value = formatApiError(err, 'Failed to send reply.')
  } finally {
    saving.value = false
  }
}

const doClose = async () => {
  saving.value = true
  actionError.value = ''
  try {
    const res = await closeProjectQuery(props.query.id)
    emit('updated', res.data)
  } catch (err) {
    actionError.value = formatApiError(err, 'Failed to close query.')
  } finally {
    saving.value = false
  }
}

const doReopen = async () => {
  saving.value = true
  actionError.value = ''
  try {
    const res = await reopenProjectQuery(props.query.id)
    emit('updated', res.data)
  } catch (err) {
    actionError.value = formatApiError(err, 'Failed to reopen query.')
  } finally {
    saving.value = false
  }
}

const downloadPdf = async () => {
  saving.value = true
  actionError.value = ''
  try {
    await downloadProjectQueryPdf(props.query.id, `query-${props.query.id}-thread.pdf`)
  } catch (err) {
    actionError.value = formatApiError(err, 'Failed to download report.')
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.query-thread__messages {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  max-height: 360px;
  overflow-y: auto;
}

.query-message {
  border: 1px solid #ebe9f1;
  border-radius: 0.5rem;
  padding: 0.6rem 0.75rem;
  background: #fff;
}

.query-message__meta {
  font-size: 0.78rem;
  margin-bottom: 0.25rem;
}

.query-message__text {
  white-space: pre-wrap;
  font-size: 0.88rem;
}
</style>
