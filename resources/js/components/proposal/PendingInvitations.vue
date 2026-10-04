<template>
  <div v-if="invitations.length" class="mb-4">
    <AppCard title="Group Invitations" subtitle="Accept or reject proposal group invitations">
      <div v-for="inv in invitations" :key="inv.id" class="invitation-row">
        <div>
          <strong>{{ inv.project?.title }}</strong>
          <div class="text-muted small">Invited by {{ inv.inviter?.name || 'Leader' }}</div>
        </div>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-success btn-sm" :disabled="savingId === inv.id" @click="openRespondModal(inv, 'accepted')">
            Accept
          </button>
          <button type="button" class="btn btn-outline-danger btn-sm" :disabled="savingId === inv.id" @click="openRespondModal(inv, 'rejected')">
            Reject
          </button>
        </div>
      </div>
    </AppCard>
  </div>

  <div v-if="respondModal.show" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.45)">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow">
        <div class="modal-header">
          <h5 class="modal-title">
            {{ respondModal.response === 'accepted' ? 'Accept Invitation' : 'Reject Invitation' }}
          </h5>
          <button type="button" class="btn-close" @click="closeRespondModal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">
            <strong>{{ respondModal.invitation?.project?.title }}</strong>
          </p>
          <div v-if="formError" class="alert alert-danger py-2">{{ formError }}</div>
          <label class="form-label">
            Comments
            <span v-if="respondModal.response === 'rejected'" class="text-danger">*</span>
          </label>
          <textarea
            v-model="respondModal.comments"
            class="form-control"
            rows="4"
            :placeholder="respondModal.response === 'accepted'
              ? 'Optional comment for the group leader...'
              : 'Please explain why you are rejecting this invitation...'"
          ></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" @click="closeRespondModal">Cancel</button>
          <button
            type="button"
            class="btn"
            :class="respondModal.response === 'accepted' ? 'btn-success' : 'btn-danger'"
            :disabled="savingId === respondModal.invitation?.id"
            @click="confirmRespond"
          >
            {{ respondModal.response === 'accepted' ? 'Confirm Accept' : 'Confirm Reject' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import AppCard from '@/components/ui/AppCard.vue'
import { formatApiError } from '@/utils/apiErrors'
import { fetchPendingInvitations, respondInvitation } from '@/api/proposals'

const emit = defineEmits(['updated'])

const invitations = ref([])
const savingId = ref(null)
const formError = ref('')

const respondModal = reactive({
  show: false,
  invitation: null,
  response: 'accepted',
  comments: '',
})

const load = async () => {
  const res = await fetchPendingInvitations()
  invitations.value = res.data
  emit('updated')
}

const openRespondModal = (inv, response) => {
  respondModal.invitation = inv
  respondModal.response = response
  respondModal.comments = ''
  formError.value = ''
  respondModal.show = true
}

const closeRespondModal = () => {
  respondModal.show = false
  respondModal.invitation = null
  respondModal.comments = ''
  formError.value = ''
}

const confirmRespond = async () => {
  const inv = respondModal.invitation
  if (!inv) return

  if (respondModal.response === 'rejected' && !respondModal.comments.trim()) {
    formError.value = 'Please provide a reason for rejecting the invitation.'
    return
  }

  savingId.value = inv.id
  formError.value = ''
  try {
    await respondInvitation(inv.id, {
      response: respondModal.response,
      comments: respondModal.comments.trim() || null,
    })
    const wasAccepted = respondModal.response === 'accepted'
    closeRespondModal()
    await load()
    if (wasAccepted) window.location.reload()
  } catch (err) {
    formError.value = formatApiError(err, 'Failed to save your response.')
  } finally {
    savingId.value = null
  }
}

onMounted(load)
</script>

<style scoped>
.invitation-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  padding: 0.75rem 0;
  border-bottom: 1px solid #ebe9f1;
}

.invitation-row:last-child {
  border-bottom: 0;
}
</style>
