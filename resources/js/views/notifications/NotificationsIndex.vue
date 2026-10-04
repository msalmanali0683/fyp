<template>
  <div>
    <PageHeader
      title="Notifications"
      subtitle="Your workflow alerts and updates"
      breadcrumb="Notifications"
    >
      <template #actions>
        <router-link v-if="authStore.canSendNotifications" to="/notifications/send" class="btn btn-primary btn-sm me-2">
          <i class="bi bi-send me-1"></i> Send Notification
        </router-link>
        <button
          v-if="notificationStore.unreadCount"
          type="button"
          class="btn btn-outline-primary btn-sm"
          :disabled="loading"
          @click="markAllRead"
        >
          Mark all read
        </button>
      </template>
    </PageHeader>

    <div v-if="loadError" class="alert alert-danger py-2">{{ loadError }}</div>

    <AppCard title="Inbox">
      <div v-if="loading" class="text-center py-4">
        <div class="spinner-border spinner-border-sm text-primary"></div>
      </div>

      <EmptyState
        v-else-if="!items.length"
        title="No notifications yet"
        description="Workflow updates will appear here when invitations, reviews, or committee actions affect you."
        icon="bi bi-bell"
      />

      <div v-else class="notification-page-list">
        <div
          v-for="item in items"
          :key="item.id"
          class="notification-page-item"
          :class="{ 'notification-page-item--unread': !item.is_read }"
        >
          <button type="button" class="notification-page-item__content" @click="openNotification(item)">
            <div class="d-flex justify-content-between gap-2">
              <strong>{{ item.title }}</strong>
              <span class="text-muted small">{{ item.created_at }}</span>
            </div>
            <div class="text-muted mt-1">{{ item.message }}</div>
          </button>
          <button
            type="button"
            class="notification-page-item__delete"
            title="Delete notification"
            :disabled="deletingId === item.id"
            @click.stop="removeNotification(item)"
          >
            <i class="bi bi-trash"></i>
          </button>
        </div>
      </div>

      <div v-if="meta.last_page > 1" class="d-flex justify-content-between align-items-center mt-3">
        <div class="text-muted small">Page {{ meta.current_page }} of {{ meta.last_page }}</div>
        <div class="btn-group btn-group-sm">
          <button type="button" class="btn btn-outline-secondary" :disabled="loading || meta.current_page <= 1" @click="goToPage(meta.current_page - 1)">
            Previous
          </button>
          <button type="button" class="btn btn-outline-secondary" :disabled="loading || meta.current_page >= meta.last_page" @click="goToPage(meta.current_page + 1)">
            Next
          </button>
        </div>
      </div>
    </AppCard>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { fetchNotifications, markNotificationRead, deleteNotification } from '@/api/notifications'
import { useNotificationStore } from '@/stores/notifications'
import { useAuthStore } from '@/stores/auth'
import { formatApiError } from '@/utils/apiErrors'
import { toast } from '@/composables/useToast'

const router = useRouter()
const notificationStore = useNotificationStore()
const authStore = useAuthStore()
const items = ref([])
const loading = ref(false)
const loadError = ref('')
const deletingId = ref(null)
const meta = reactive({
  current_page: 1,
  last_page: 1,
  total: 0,
})

const loadNotifications = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const res = await fetchNotifications({ page: meta.current_page, per_page: 20 })
    items.value = res.data?.notifications || []
    Object.assign(meta, res.data?.meta || {})
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to load notifications.')
  } finally {
    loading.value = false
  }
}

const markAllRead = async () => {
  await notificationStore.markAllRead()
  items.value = items.value.map((item) => ({ ...item, is_read: true }))
}

const openNotification = async (item) => {
  if (!item.is_read) {
    await markNotificationRead(item.id)
    item.is_read = true
    notificationStore.unreadCount = Math.max(0, notificationStore.unreadCount - 1)
  }

  if (item.meta?.project_id) {
    router.push(`/projects/${item.meta.project_id}`)
  }
}

const removeNotification = async (item) => {
  deletingId.value = item.id
  try {
    await deleteNotification(item.id)
    items.value = items.value.filter((row) => row.id !== item.id)
    if (!item.is_read) {
      notificationStore.unreadCount = Math.max(0, notificationStore.unreadCount - 1)
    }
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to delete notification.'))
  } finally {
    deletingId.value = null
  }
}

const goToPage = async (page) => {
  meta.current_page = page
  await loadNotifications()
}

onMounted(loadNotifications)
</script>

<style scoped>
.notification-page-list {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.notification-page-item {
  display: flex;
  align-items: stretch;
  gap: 0.5rem;
  width: 100%;
  border: 1px solid #ebe9f1;
  border-radius: 0.5rem;
  background: #fff;
  padding: 0.85rem 1rem;
}

.notification-page-item--unread {
  border-color: #7367f0;
  background: rgba(115, 103, 240, 0.04);
}

.notification-page-item__content {
  flex: 1;
  min-width: 0;
  text-align: left;
  border: 0;
  background: transparent;
  padding: 0;
}

.notification-page-item__delete {
  flex-shrink: 0;
  align-self: center;
  border: 0;
  background: transparent;
  color: #ea5455;
  padding: 0.25rem 0.5rem;
}

.notification-page-item__delete:hover {
  color: #b91c1c;
}
</style>
