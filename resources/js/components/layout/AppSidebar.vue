<template>

  <aside

    class="app-sidebar layout-menu menu-vertical menu-bordered"

    :class="{

      'is-open': appStore.sidebarOpen,

      collapsed: appStore.sidebarCollapsed,

    }"

  >

    <div class="app-brand demo">

      <div class="brand-logo">F</div>

      <span class="brand-text app-brand-text">FYP Admin</span>

    </div>



    <nav class="sidebar-nav menu-inner py-1">

      <div class="menu-section-title">Main Menu</div>



      <router-link

        v-if="authStore.canViewDashboard"

        to="/"

        class="nav-item-link nav-item-link--dashboard"

        :class="{ active: route.name === 'dashboard' }"

        @click="appStore.closeSidebar()"

      >

        <i class="bi bi-grid-1x2-fill"></i>

        <span class="nav-label">Dashboard</span>

      </router-link>



      <router-link

        v-if="authStore.isStudent"

        to="/my-project"

        class="nav-item-link nav-item-link--student"

        :class="{ active: route.name === 'my-project' }"

        @click="appStore.closeSidebar()"

      >

        <i class="bi bi-journal-bookmark-fill"></i>

        <span class="nav-label">My Project</span>

      </router-link>



      <router-link

        v-if="authStore.canViewAllProjects"

        to="/projects"

        class="nav-item-link nav-item-link--projects"

        :class="{ active: route.name === 'projects' }"

        @click="appStore.closeSidebar()"

      >

        <i class="bi bi-folder-fill"></i>

        <span class="nav-label">FYP Projects</span>

      </router-link>



      <router-link

        v-if="authStore.canViewAllProjects"

        to="/admin/committee-workbench"

        class="nav-item-link"

        :class="{ active: route.name === 'committee-workbench' }"

        @click="appStore.closeSidebar()"

      >

        <i class="bi bi-clipboard-check-fill"></i>

        <span class="nav-label">Committee Workbench</span>

      </router-link>



      <router-link

        to="/notifications"

        class="nav-item-link"

        :class="{ active: route.name === 'notifications' }"

        @click="appStore.closeSidebar()"

      >

        <i class="bi bi-bell-fill"></i>

        <span class="nav-label">Notifications</span>

      </router-link>

      <router-link
        v-if="authStore.canAccessProjectQueries"
        to="/queries"
        class="nav-item-link"
        :class="{ active: route.name === 'project-queries' }"
        @click="appStore.closeSidebar()"
      >
        <i class="bi bi-chat-left-text-fill"></i>
        <span class="nav-label">Queries</span>
      </router-link>

      <router-link
        to="/admin/phase-templates"
        class="nav-item-link"
        :class="{ active: route.name === 'admin-phase-templates' }"
        @click="appStore.closeSidebar()"
      >
        <i class="bi bi-file-earmark-text-fill"></i>
        <span class="nav-label">Templates</span>
      </router-link>

      <router-link
        to="/guidelines"
        class="nav-item-link"
        :class="{ active: route.name === 'guidelines' }"
        @click="appStore.closeSidebar()"
      >
        <i class="bi bi-signpost-2-fill"></i>
        <span class="nav-label">Guidelines</span>
      </router-link>



      <div v-if="showSupervisorNav" class="menu-section-title">Supervisors</div>



      <div v-if="showSupervisorNav" class="nav-group nav-group--supervisors">

        <button

          type="button"

          class="nav-group__toggle"

          :class="{ open: supervisorOpen, active: isSupervisorSectionActive }"

          @click="supervisorOpen = !supervisorOpen"

        >

          <i class="bi bi-person-badge-fill"></i>

          <span class="nav-label">Supervisors</span>

          <i class="bi bi-chevron-up nav-group__chevron"></i>

        </button>



        <div v-show="supervisorOpen" class="nav-sub-menu">

          <router-link

            v-if="authStore.hasRole('supervisor')"

            to="/supervisor/projects"

            class="nav-sub-item"

            :class="{ active: route.name === 'supervisor-projects' }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-folder-check"></i>

            <span class="nav-label">Supervised Projects</span>

          </router-link>



          <router-link

            v-if="authStore.canViewSupervisorOverview"

            to="/admin/supervisors"

            class="nav-sub-item"

            :class="{ active: ['admin-supervisors', 'admin-supervisor-projects'].includes(route.name) }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-bar-chart"></i>

            <span class="nav-label">Workload Overview</span>

          </router-link>



          <router-link

            v-if="authStore.canManageSupervisorChangeRequests"

            to="/admin/supervisor-change-requests"

            class="nav-sub-item"

            :class="{ active: route.name === 'admin-supervisor-change-requests' }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-arrow-left-right"></i>

            <span class="nav-label">Change Requests</span>

          </router-link>

        </div>

      </div>



      <div v-if="showEvaluatorNav" class="menu-section-title">Evaluators</div>



      <div v-if="showEvaluatorNav" class="nav-group nav-group--evaluators">

        <button

          type="button"

          class="nav-group__toggle"

          :class="{ open: evaluatorOpen, active: isEvaluatorSectionActive }"

          @click="evaluatorOpen = !evaluatorOpen"

        >

          <i class="bi bi-clipboard-check-fill"></i>

          <span class="nav-label">Evaluators</span>

          <i class="bi bi-chevron-up nav-group__chevron"></i>

        </button>



        <div v-show="evaluatorOpen" class="nav-sub-menu">

          <router-link

            v-if="authStore.hasRole('evaluator')"

            to="/evaluator/assigned-projects"

            class="nav-sub-item"

            :class="{ active: route.name === 'evaluator-assigned-projects' }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-journal-check"></i>

            <span class="nav-label">Assigned Projects</span>

          </router-link>



          <router-link

            v-if="authStore.hasRole('evaluator')"

            to="/evaluator/completed-projects"

            class="nav-sub-item"

            :class="{ active: route.name === 'evaluator-completed-projects' }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-check2-circle"></i>

            <span class="nav-label">Completed</span>

          </router-link>



          <router-link

            v-if="authStore.canManageEvaluators"

            to="/admin/evaluators"

            class="nav-sub-item"

            :class="{ active: ['admin-evaluators', 'admin-evaluator-projects'].includes(route.name) }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-bar-chart"></i>

            <span class="nav-label">Workload Overview</span>

          </router-link>

          <router-link

            v-if="authStore.canManageEvaluators"

            to="/admin/evaluator-assignment"

            class="nav-sub-item"

            :class="{ active: route.name === 'admin-evaluator-assignment' }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-diagram-3"></i>

            <span class="nav-label">Auto Assign</span>

          </router-link>

        </div>

      </div>



      <div v-if="showFacultyManagementNav" class="menu-section-title">Faculty Management</div>



      <div v-if="showFacultyManagementNav" class="nav-group nav-group--faculty-mgmt">

        <button

          type="button"

          class="nav-group__toggle"

          :class="{ open: facultyMgmtOpen, active: isFacultyManagementActive }"

          @click="facultyMgmtOpen = !facultyMgmtOpen"

        >

          <i class="bi bi-person-workspace"></i>

          <span class="nav-label">Manage Supervisors & Evaluators</span>

          <i class="bi bi-chevron-up nav-group__chevron"></i>

        </button>



        <div v-show="facultyMgmtOpen" class="nav-sub-menu">

          <router-link

            v-if="authStore.isAdmin"

            to="/admin/faculty-management/supervisors"

            class="nav-sub-item"

            :class="{ active: route.name === 'admin-faculty-management-supervisors' }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-person-badge"></i>

            <span class="nav-label">Supervisors</span>

          </router-link>



          <router-link

            v-if="authStore.canManageEvaluators"

            to="/admin/faculty-management/evaluators"

            class="nav-sub-item"

            :class="{ active: route.name === 'admin-faculty-management-evaluators' }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-person-check"></i>

            <span class="nav-label">Evaluators</span>

          </router-link>



          <router-link

            v-if="authStore.isAdmin"

            to="/admin/faculty-management/faculty"

            class="nav-sub-item"

            :class="{ active: route.name === 'admin-faculty-management-faculty' }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-people"></i>

            <span class="nav-label">Faculty</span>

          </router-link>

        </div>

      </div>



      <div v-if="showAdminNav" class="menu-section-title">System</div>



      <div v-if="showAdminNav" class="nav-group nav-group--admin">

        <button

          type="button"

          class="nav-group__toggle"

          :class="{ open: adminOpen, active: isAdminSectionActive }"

          @click="adminOpen = !adminOpen"

        >

          <i class="bi bi-gear-fill"></i>

          <span class="nav-label">Administration</span>

          <i class="bi bi-chevron-up nav-group__chevron"></i>

        </button>



        <div v-show="adminOpen" class="nav-sub-menu">

          <router-link

            v-if="authStore.isAdmin"

            to="/admin/users"

            class="nav-sub-item"

            :class="{ active: route.name === 'admin-users' }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-people-fill"></i>

            <span class="nav-label">Users</span>

          </router-link>



          <router-link

            v-if="authStore.isAdmin"

            to="/admin/students"

            class="nav-sub-item"

            :class="{ active: route.name === 'admin-students' }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-mortarboard-fill"></i>

            <span class="nav-label">Students</span>

          </router-link>



          <router-link

            v-if="authStore.isCommitteeHead"

            to="/admin/roles"

            class="nav-sub-item"

            :class="{ active: route.name === 'admin-roles' }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-shield-check"></i>

            <span class="nav-label">Roles</span>

          </router-link>



          <router-link

            v-if="authStore.isCommitteeHead"

            to="/admin/permissions"

            class="nav-sub-item"

            :class="{ active: route.name === 'admin-permissions' }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-key-fill"></i>

            <span class="nav-label">Permissions</span>

          </router-link>

          <router-link
            v-if="authStore.canViewActivityLogs"
            to="/admin/activity-log"
            class="nav-sub-item"
            :class="{ active: route.name === 'admin-activity-log' }"
            @click="appStore.closeSidebar()"
          >
            <i class="bi bi-journal-text"></i>
            <span class="nav-label">Activity Log</span>
          </router-link>

          <router-link
            v-if="authStore.canDecideStudentTransfer"
            to="/admin/transfer-requests"
            class="nav-sub-item"
            :class="{ active: route.name === 'admin-transfer-requests' }"
            @click="appStore.closeSidebar()"
          >
            <i class="bi bi-people"></i>
            <span class="nav-label">Transfer Requests</span>
          </router-link>



          <router-link

            v-if="authStore.canManageProposalSettings"

            to="/admin/proposal-settings"

            class="nav-sub-item"

            :class="{ active: route.name === 'admin-proposal-settings' }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-sliders"></i>

            <span class="nav-label">Proposal Settings</span>

          </router-link>

          <router-link

            v-if="authStore.canManageQuestionBank"

            to="/admin/question-bank"

            class="nav-sub-item"

            :class="{ active: route.name === 'admin-question-bank' }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-patch-question"></i>

            <span class="nav-label">Question Bank</span>

          </router-link>



          <router-link

            v-if="authStore.canManageProposalSessions || authStore.canGrantProposalSessionExtensions"

            to="/admin/proposal-sessions"

            class="nav-sub-item"

            :class="{ active: ['admin-proposal-sessions', 'admin-proposal-session-detail'].includes(route.name) }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-calendar-event"></i>

            <span class="nav-label">Proposal Sessions</span>

          </router-link>



          <router-link

            v-if="authStore.isAdmin || authStore.isCommitteeHead"

            to="/admin/programs"

            class="nav-sub-item"

            :class="{ active: route.name === 'admin-programs' }"

            @click="appStore.closeSidebar()"

          >

            <i class="bi bi-diagram-3"></i>

            <span class="nav-label">Programs</span>

          </router-link>

        </div>

      </div>

    </nav>

  </aside>

</template>



<script setup>

import { computed, ref, watch } from 'vue'

import { useRoute } from 'vue-router'

import { useAppStore } from '@/stores/app'

import { useAuthStore } from '@/stores/auth'



const route = useRoute()

const appStore = useAppStore()

const authStore = useAuthStore()

const adminOpen = ref(true)

const supervisorOpen = ref(true)

const evaluatorOpen = ref(true)

const facultyMgmtOpen = ref(true)



const showSupervisorNav = computed(

  () => authStore.hasRole('supervisor') || authStore.canViewSupervisorOverview

)

const showEvaluatorNav = computed(

  () => authStore.hasRole('evaluator') || authStore.canManageEvaluators

)

const showFacultyManagementNav = computed(

  () => authStore.isAdmin || authStore.canManageEvaluators

)

const showAdminNav = computed(() => authStore.isAdmin || authStore.isCommitteeHead)



const isAdminSectionActive = computed(() =>

  ['admin-users', 'admin-students', 'admin-roles', 'admin-permissions', 'admin-activity-log', 'admin-transfer-requests', 'admin-proposal-settings', 'admin-question-bank', 'admin-proposal-sessions', 'admin-proposal-session-detail', 'admin-programs'].includes(route.name)

)

const isSupervisorSectionActive = computed(() =>

  ['supervisor-projects', 'admin-supervisors', 'admin-supervisor-projects', 'admin-supervisor-change-requests'].includes(route.name)

)

const isEvaluatorSectionActive = computed(() =>

  ['evaluator-assigned-projects', 'evaluator-completed-projects', 'admin-evaluators', 'admin-evaluator-projects', 'admin-evaluator-assignment'].includes(route.name)

)

const isFacultyManagementActive = computed(() =>

  [
    'admin-faculty-management-supervisors',
    'admin-faculty-management-evaluators',
    'admin-faculty-management-faculty',
  ].includes(route.name)

)



watch(

  () => route.name,

  (name) => {

    if (['admin-users', 'admin-students', 'admin-roles', 'admin-permissions', 'admin-activity-log', 'admin-transfer-requests'].includes(name)) {

      adminOpen.value = true

    }

    if (['admin-supervisors', 'supervisor-projects', 'admin-supervisor-projects', 'admin-supervisor-change-requests'].includes(name)) {

      supervisorOpen.value = true

    }

    if (['evaluator-assigned-projects', 'evaluator-completed-projects', 'admin-evaluators', 'admin-evaluator-projects', 'admin-evaluator-assignment'].includes(name)) {

      evaluatorOpen.value = true

    }

    if ([
      'admin-faculty-management-supervisors',
      'admin-faculty-management-evaluators',
      'admin-faculty-management-faculty',
    ].includes(name)) {

      facultyMgmtOpen.value = true

    }

  },

  { immediate: true }

)

</script>


