<template>
  <div>
    <PageHeader
      title="Dashboard"
      :subtitle="`Welcome back, ${authStore.user?.name || 'User'}!`"
      breadcrumb="Dashboard"
    />

    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary"></div>
    </div>

    <template v-else>
      <NextActionsCard :actions="nextActions" />
      <RoleSummaryWidgets :widgets="roleWidgets" />

      <PendingInvitations v-if="authStore.isStudent" class="mb-3" />

      <AppCard
        v-if="authStore.isStudent && myProject"
        title="My Group"
        :subtitle="myProject.title"
        class="mb-3"
      >
        <div class="group-members">
          <div v-for="member in groupMembers" :key="member.id" class="group-member">
            <img :src="member.avatar" :alt="member.name" class="group-member__avatar" />
            <div class="group-member__info">
              <div class="group-member__name">{{ member.name }}</div>
              <div v-if="member.sap_id" class="group-member__sap text-muted small">SAP: {{ member.sap_id }}</div>
            </div>
            <AppBadge :variant="member.role === 'leader' ? 'primary' : 'secondary'" class="group-member__badge">
              {{ member.roleLabel }}
            </AppBadge>
          </div>
        </div>
        <div v-if="!groupMembers.length" class="text-muted small">No group members yet.</div>
      </AppCard>

      <div class="row g-3 g-lg-4 mb-1">
        <div v-for="stat in stats" :key="stat.label" class="col-12 col-sm-6 col-xl-3">
          <StatCard v-bind="stat" />
        </div>
      </div>

      <div class="row g-3 g-lg-4">
        <div class="col-12 col-lg-8">
          <AppCard title="Projects by Phase" subtitle="Current phase distribution">
            <BarChart :labels="phaseLabels" :datasets="phaseDatasets" height="sm" />
          </AppCard>
        </div>
        <div class="col-12 col-lg-4">
          <AppCard title="Submission Status" subtitle="Phase submission breakdown">
            <DonutChart :labels="donutLabels" :datasets="donutDatasets" />
          </AppCard>
        </div>
      </div>

      <div class="row g-3 g-lg-4">
        <div class="col-12 col-lg-5">
          <AppCard title="Project Status" subtitle="Active, completed, and suspended">
            <BarChart :labels="projectStatusLabels" :datasets="projectStatusDatasets" height="sm" />
          </AppCard>
        </div>
        <div class="col-12 col-lg-7">
          <DataTable
            title="Recent Projects"
            subtitle="Latest FYP projects"
            :columns="tableColumns"
            :rows="tableRows"
          >
            <template #cell-student="{ row }">
              <div class="avatar-cell">
                <img :src="row.avatar" :alt="row.student" />
                <div>
                  <div class="name">{{ row.student }}</div>
                  <div class="email">{{ row.email }}</div>
                </div>
              </div>
            </template>

            <template #cell-current_phase="{ row }">
              <AppBadge variant="primary">{{ row.current_phase_label }}</AppBadge>
            </template>

            <template #cell-status="{ row }">
              <AppBadge :variant="statusVariant(row.status)">{{ row.status }}</AppBadge>
            </template>
          </DataTable>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import StatCard from '@/components/ui/StatCard.vue'
import AppCard from '@/components/ui/AppCard.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import DataTable from '@/components/table/DataTable.vue'
import BarChart from '@/components/charts/BarChart.vue'
import DonutChart from '@/components/charts/DonutChart.vue'
import { useAuthStore } from '@/stores/auth'
import PendingInvitations from '@/components/proposal/PendingInvitations.vue'
import NextActionsCard from '@/components/dashboard/NextActionsCard.vue'
import RoleSummaryWidgets from '@/components/dashboard/RoleSummaryWidgets.vue'
import { fetchDashboardStats, fetchDashboardCharts, fetchRecentProjects, fetchDashboardNextActions, fetchDashboardRoleWidgets } from '@/api/dashboard'
import { phaseLabel } from '@/utils/phases'

const authStore = useAuthStore()
const loading = ref(true)
const statsData = ref({})
const chartsData = ref({})
const recentProjects = ref([])
const nextActions = ref([])
const roleWidgets = ref([])

const isAdminView = computed(() =>
  authStore.roles.some((r) => ['admin', 'fyp-committee-head', 'fyp-committee-member'].includes(r))
)

const myProject = computed(() =>
  authStore.isStudent && recentProjects.value.length ? recentProjects.value[0] : null
)

const groupMembers = computed(() => {
  const project = myProject.value
  if (!project) return []

  const avatarFor = (name) =>
    `https://ui-avatars.com/api/?name=${encodeURIComponent(name || 'U')}&background=7367f0&color=fff`

  const activeMembers = (project.members || [])
    .filter((member) => member.status === 'active' && member.user)
    .map((member) => ({
      id: member.user.id,
      name: member.user.name,
      sap_id: member.user.sap_id || member.user.registration_no || '',
      role: member.role,
      roleLabel: member.role === 'leader' ? 'Leader' : 'Member',
      avatar: avatarFor(member.user.name),
    }))

  if (activeMembers.length) {
    return activeMembers
  }

  if (project.student) {
    return [{
      id: project.student.id,
      name: project.student.name,
      sap_id: project.student.sap_id || project.student.registration_no || '',
      role: 'leader',
      roleLabel: 'Leader',
      avatar: avatarFor(project.student.name),
    }]
  }

  return []
})

const stats = computed(() => {
  const cards = [
    {
      label: 'Total Projects',
      value: statsData.value.total_projects?.toLocaleString() || '0',
      icon: 'bi bi-folder-fill',
      variant: 'primary',
      change: null,
    },
    {
      label: 'Active Projects',
      value: statsData.value.active_projects?.toLocaleString() || '0',
      icon: 'bi bi-play-circle-fill',
      variant: 'success',
      change: null,
      to: authStore.canViewAllProjects ? { name: 'projects', query: { status: 'active' } } : null,
    },
    {
      label: 'Pending Reviews',
      value: statsData.value.pending_reviews?.toLocaleString() || '0',
      icon: 'bi bi-clock-fill',
      variant: 'warning',
      change: null,
      to: authStore.canViewAllProjects ? { name: 'committee-workbench', query: { tab: 'review' } } : null,
    },
    {
      label: 'Completed',
      value: statsData.value.completed_projects?.toLocaleString() || '0',
      icon: 'bi bi-check-circle-fill',
      variant: 'info',
      change: null,
      to: authStore.canViewAllProjects ? { name: 'projects', query: { status: 'completed' } } : null,
    },
  ]

  if (isAdminView.value) {
    cards[0] = {
      label: 'Total Users',
      value: statsData.value.total_users?.toLocaleString() || '0',
      icon: 'bi bi-people-fill',
      variant: 'primary',
      change: null,
      to: { name: 'admin-users' },
    }
  } else if (authStore.canViewAllProjects) {
    cards[0].to = { name: 'projects' }
  }

  return cards
})

const phaseLabels = computed(() =>
  Object.keys(chartsData.value.phase_breakdown || {}).map((key) => phaseLabel(key))
)

const phaseDatasets = computed(() => [{
  label: 'Projects',
  data: Object.values(chartsData.value.phase_breakdown || {}),
  backgroundColor: '#7367f0',
  borderRadius: 6,
  barThickness: 28,
}])

const donutLabels = computed(() => Object.keys(chartsData.value.submission_status_breakdown || {}))
const donutDatasets = computed(() => [{
  data: Object.values(chartsData.value.submission_status_breakdown || {}),
  backgroundColor: ['#7367f0', '#ff9f43', '#00cfe8', '#28c76f', '#ea5455', '#82868b'],
  borderWidth: 0,
}])

const projectStatusLabels = computed(() => Object.keys(chartsData.value.project_status_breakdown || {}))
const projectStatusDatasets = computed(() => [{
  label: 'Projects',
  data: Object.values(chartsData.value.project_status_breakdown || {}),
  backgroundColor: '#28c76f',
  borderRadius: 6,
  barThickness: 28,
}])

const tableColumns = [
  { key: 'title', label: 'Project' },
  { key: 'student', label: 'Student' },
  { key: 'current_phase', label: 'Phase' },
  { key: 'status', label: 'Status' },
]

const tableRows = computed(() =>
  recentProjects.value.map((project) => ({
    id: project.id,
    title: project.title,
    student: project.student?.name || 'Unknown',
    email: project.student?.email || '',
    avatar: `https://ui-avatars.com/api/?name=${encodeURIComponent(project.student?.name || 'U')}&background=7367f0&color=fff`,
    current_phase_label: project.current_phase_label,
    status: project.status,
  }))
)

const statusVariant = (status) => ({
  active: 'success',
  completed: 'primary',
  suspended: 'danger',
}[status] || 'secondary')

onMounted(async () => {
  try {
    const requests = [
      fetchDashboardStats(),
      fetchDashboardCharts(),
      fetchRecentProjects(),
      fetchDashboardNextActions(),
      fetchDashboardRoleWidgets(),
    ]

    const [statsRes, chartsRes, projectsRes, actionsRes, widgetsRes] = await Promise.all(requests)
    statsData.value = statsRes.data
    chartsData.value = chartsRes.data
    recentProjects.value = projectsRes.data
    nextActions.value = actionsRes.data?.actions || []
    roleWidgets.value = widgetsRes.data?.widgets || []
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.text-heading {
  color: #5e5873;
}

.group-members {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.group-member {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.group-member__avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  object-fit: cover;
}

.group-member__info {
  flex: 1;
  min-width: 0;
}

.group-member__name {
  font-weight: 600;
  color: #5e5873;
}

.group-member__badge {
  flex-shrink: 0;
}
</style>
