<template>
  <div>
    <PageHeader
      title="Send Notification"
      subtitle="Compose a notification and choose who should receive it"
      breadcrumb="Send Notification"
    />

    <AppCard title="Compose">
      <form @submit.prevent="submit">
        <div v-if="formError" class="alert alert-danger py-2">{{ formError }}</div>

        <div class="mb-3">
          <label class="form-label">Title</label>
          <input v-model="form.title" type="text" class="form-control" maxlength="255" required />
        </div>

        <div class="mb-3">
          <label class="form-label">Message</label>
          <textarea v-model="form.message" class="form-control" rows="4" maxlength="2000" required></textarea>
        </div>

        <div class="mb-3" style="max-width: 260px">
          <label class="form-label">Type</label>
          <select v-model="form.type" class="form-select">
            <option value="info">Info</option>
            <option value="success">Success</option>
            <option value="warning">Warning</option>
            <option value="danger">Danger</option>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label d-block">Recipients</label>
          <div class="btn-group" role="group">
            <input id="mode-users" v-model="form.recipient_mode" type="radio" class="btn-check" value="users" @change="onModeChange" />
            <label class="btn btn-outline-primary btn-sm" for="mode-users">Specific Users</label>

            <input id="mode-role" v-model="form.recipient_mode" type="radio" class="btn-check" value="role" @change="onModeChange" />
            <label class="btn btn-outline-primary btn-sm" for="mode-role">By Role</label>

            <input id="mode-all" v-model="form.recipient_mode" type="radio" class="btn-check" value="all" @change="onModeChange" />
            <label class="btn btn-outline-primary btn-sm" for="mode-all">All Users</label>
          </div>
        </div>

        <div v-if="form.recipient_mode === 'role'" class="mb-3" style="max-width: 320px">
          <label class="form-label">Role</label>
          <select v-model="form.role" class="form-select">
            <option :value="null" disabled>Select a role…</option>
            <option v-for="(label, key) in roles" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>

        <div v-if="form.recipient_mode === 'users'" class="mb-3">
          <div class="row g-2 mb-2">
            <div class="col-md-6">
              <input v-model="userSearch" type="search" class="form-control" placeholder="Search by name or email…" @input="loadUsers" />
            </div>
          </div>

          <div v-if="selectedUsers.length" class="selected-chips mb-2">
            <span v-for="user in selectedUsers" :key="user.id" class="chip">
              {{ user.name }}
              <button type="button" class="chip__remove" @click="toggleUser(user)"><i class="bi bi-x-lg"></i></button>
            </span>
          </div>

          <div class="user-list">
            <label v-for="user in users" :key="user.id" class="user-list__item">
              <input type="checkbox" :checked="isSelected(user)" @change="toggleUser(user)" />
              <span>{{ user.name }} <span class="text-muted small">({{ user.email }})</span></span>
            </label>
            <div v-if="!users.length" class="text-muted small py-2">No users found.</div>
          </div>
        </div>

        <div v-else-if="form.recipient_mode === 'all'" class="alert alert-warning py-2 small">
          This will send the notification to every active user you have access to.
        </div>

        <button type="submit" class="btn btn-primary" :disabled="sending || !canSubmit">
          Send Notification
        </button>
      </form>
    </AppCard>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import { fetchNotificationRecipients, broadcastNotification } from '@/api/notifications'
import { toast } from '@/composables/useToast'
import { formatApiError } from '@/utils/apiErrors'

const router = useRouter()

const form = reactive({
  title: '',
  message: '',
  type: 'info',
  recipient_mode: 'users',
  role: null,
})

const roles = ref({})
const users = ref([])
const selectedUserIds = ref([])
const userSearch = ref('')
const sending = ref(false)
const formError = ref('')

const selectedUsersCache = ref([])

const selectedUsers = computed(() =>
  [...users.value, ...selectedUsersCache.value].filter((user, index, arr) => (
    selectedUserIds.value.includes(user.id) && arr.findIndex((u) => u.id === user.id) === index
  ))
)

const isSelected = (user) => selectedUserIds.value.includes(user.id)

const toggleUser = (user) => {
  if (isSelected(user)) {
    selectedUserIds.value = selectedUserIds.value.filter((id) => id !== user.id)
  } else {
    selectedUserIds.value = [...selectedUserIds.value, user.id]
    if (!selectedUsersCache.value.some((u) => u.id === user.id)) {
      selectedUsersCache.value = [...selectedUsersCache.value, user]
    }
  }
}

const canSubmit = computed(() => {
  if (!form.title.trim() || !form.message.trim()) return false
  if (form.recipient_mode === 'role') return !!form.role
  if (form.recipient_mode === 'users') return selectedUserIds.value.length > 0
  return form.recipient_mode === 'all'
})

let searchTimeout = null
const loadUsers = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(async () => {
    try {
      const res = await fetchNotificationRecipients({ search: userSearch.value || undefined })
      users.value = res.data.users || []
    } catch {
      users.value = []
    }
  }, 250)
}

const onModeChange = () => {
  formError.value = ''
}

const loadRoles = async () => {
  const res = await fetchNotificationRecipients()
  roles.value = res.data.roles || {}
  users.value = res.data.users || []
}

const submit = async () => {
  formError.value = ''
  sending.value = true
  try {
    const payload = {
      title: form.title,
      message: form.message,
      type: form.type,
      recipient_mode: form.recipient_mode,
    }
    if (form.recipient_mode === 'role') payload.role = form.role
    if (form.recipient_mode === 'users') payload.user_ids = selectedUserIds.value

    const res = await broadcastNotification(payload)
    toast.success(res.message || 'Notification sent.')
    router.push({ name: 'notifications' })
  } catch (err) {
    formError.value = formatApiError(err, 'Failed to send notification.')
  } finally {
    sending.value = false
  }
}

onMounted(loadRoles)
</script>

<style scoped>
.selected-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.3rem 0.5rem 0.3rem 0.7rem;
  background: rgba(115, 103, 240, 0.12);
  border: 1px solid rgba(115, 103, 240, 0.25);
  border-radius: 999px;
  font-size: 0.82rem;
}

.chip__remove {
  border: 0;
  background: transparent;
  color: #6e6b7b;
  padding: 0 0.2rem;
  line-height: 1;
}

.user-list {
  max-height: 260px;
  overflow-y: auto;
  border: 1px solid #ebe9f1;
  border-radius: 0.428rem;
  padding: 0.5rem;
}

.user-list__item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.25rem;
  margin-bottom: 0;
}
</style>
