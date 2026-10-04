<template>
  <header class="app-navbar">
    <div class="navbar-left">
      <button type="button" class="icon-btn d-lg-none" aria-label="Open menu" @click="appStore.toggleSidebar()">
        <i class="bi bi-menu-button-wide"></i>
      </button>
      <button type="button" class="icon-btn d-none d-lg-inline-flex" aria-label="Toggle sidebar" @click="appStore.toggleSidebarCollapse()">
        <i class="bi bi-list"></i>
      </button>

      <div class="navbar-search d-none d-md-block">
        <i class="bi bi-search"></i>
        <input type="search" placeholder="Search (Ctrl+/)" aria-label="Search" />
      </div>
    </div>

    <div class="navbar-right">
      <div class="dropdown">
        <button
          type="button"
          class="icon-btn position-relative"
          aria-label="Notifications"
          @click="notificationStore.toggleOpen()"
        >
          <i class="bi bi-bell"></i>
          <span v-if="notificationStore.unreadCount" class="notification-dot"></span>
        </button>

        <div v-if="notificationStore.open" class="notification-panel shadow border">
          <div class="notification-panel__header">
            <strong>Notifications</strong>
            <button
              v-if="notificationStore.unreadCount"
              type="button"
              class="btn btn-link btn-sm p-0"
              @click="notificationStore.markAllRead()"
            >
              Mark all read
            </button>
          </div>

          <div v-if="notificationStore.loading" class="text-center py-3">
            <div class="spinner-border spinner-border-sm text-primary"></div>
          </div>

          <div v-else-if="!notificationStore.items.length" class="text-muted small p-3">
            No notifications yet.
          </div>

          <div v-else class="notification-panel__list">
            <button
              v-for="item in notificationStore.items"
              :key="item.id"
              type="button"
              class="notification-item"
              :class="{ 'notification-item--unread': !item.is_read }"
              @click="openNotification(item)"
            >
              <div class="notification-item__title">{{ item.title }}</div>
              <div class="notification-item__message">{{ item.message }}</div>
              <div class="notification-item__time text-muted small">{{ item.created_at }}</div>
            </button>
          </div>

          <div class="notification-panel__footer border-top p-2 text-center">
            <router-link to="/notifications" class="small" @click="notificationStore.open = false">
              View all notifications
            </router-link>
          </div>
        </div>
      </div>

      <div class="dropdown">
        <button
          type="button"
          class="user-dropdown-toggle dropdown-toggle"
          data-bs-toggle="dropdown"
          aria-expanded="false"
        >
          <img :src="avatarUrl" alt="User avatar" class="user-avatar" />
          <div class="user-info">
            <span class="user-name">{{ authStore.user?.name || 'User' }}</span>
            <span class="user-role">{{ roleLabel }}</span>
            <span v-if="authStore.primaryProgramName" class="user-program">{{ authStore.primaryProgramName }}</span>
          </div>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
          <li>
            <router-link class="dropdown-item" to="/profile">
              <i class="bi bi-person me-2"></i>My Profile
            </router-link>
          </li>
          <li><hr class="dropdown-divider" /></li>
          <li>
            <button type="button" class="dropdown-item text-danger" @click="authStore.logout()">
              <i class="bi bi-box-arrow-right me-2"></i>Logout
            </button>
          </li>
        </ul>
      </div>
    </div>
  </header>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAppStore } from '@/stores/app'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import { roleLabel as formatRoleLabel } from '@/utils/roles'

defineProps({
  title: { type: String, default: 'Dashboard' },
  breadcrumb: { type: String, default: 'Dashboard' },
})

const router = useRouter()
const appStore = useAppStore()
const authStore = useAuthStore()
const notificationStore = useNotificationStore()

const avatarUrl = computed(() =>
  `https://ui-avatars.com/api/?name=${encodeURIComponent(authStore.user?.name || 'User')}&background=7367f0&color=fff`
)

const roleLabel = computed(() => {
  const roles = authStore.user?.roles || []
  if (!roles.length) return 'User'
  return roles.map((role) => formatRoleLabel(role)).join(', ')
})

const openNotification = async (item) => {
  await notificationStore.markRead(item)

  const projectId = item.meta?.project_id
  if (projectId) {
    notificationStore.open = false
    router.push(`/projects/${projectId}`)
  }
}

const closePanelOnOutsideClick = (event) => {
  if (!event.target.closest('.dropdown')) {
    notificationStore.open = false
  }
}

onMounted(() => {
  document.addEventListener('click', closePanelOnOutsideClick)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', closePanelOnOutsideClick)
})
</script>

<style scoped>
.dropdown-toggle::after {
  display: none;
}

.user-program {
  display: block;
  font-size: 0.7rem;
  color: var(--bs-secondary-color, #6c757d);
  line-height: 1.2;
}

.notification-panel {
  position: absolute;
  right: 0;
  top: calc(100% + 0.5rem);
  width: min(360px, calc(100vw - 1.5rem));
  background: #fff;
  border-radius: 0.5rem;
  z-index: 1050;
}

.notification-panel__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.85rem 1rem;
  border-bottom: 1px solid #ebe9f1;
}

.notification-panel__list {
  max-height: 360px;
  overflow-y: auto;
}

.notification-item {
  width: 100%;
  text-align: left;
  border: 0;
  background: transparent;
  padding: 0.85rem 1rem;
  border-bottom: 1px solid #f1f1f1;
}

.notification-item--unread {
  background: rgba(115, 103, 240, 0.06);
}

.notification-item__title {
  font-weight: 600;
  font-size: 0.9rem;
}

.notification-item__message {
  color: #6e6b7b;
  font-size: 0.85rem;
}
</style>
