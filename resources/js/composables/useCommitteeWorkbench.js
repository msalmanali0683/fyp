import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

const COMMITTEE_TABS = {
  review: { needs_action: 'committee_review', label: 'Committee Review' },
  final: { needs_action: 'committee_final', label: 'Committee Final' },
  head: { needs_action: 'committee_head', label: 'Head Approval' },
}

export function useCommitteeWorkbench(route) {
  const authStore = useAuthStore()
  const isWorkbench = computed(() => !!route.meta?.committeeWorkbench)
  const activeTab = computed(() => {
    const tab = String(route.query.tab || 'final')
    return COMMITTEE_TABS[tab] ? tab : 'final'
  })

  const workbenchTabs = computed(() =>
    Object.entries(COMMITTEE_TABS)
      .filter(([key]) => key !== 'head' || authStore.roles.includes('fyp-committee-head'))
      .map(([key, value]) => ({ key, ...value }))
  )

  const applyWorkbenchFilters = (filters) => {
    if (!isWorkbench.value) return
    const tab = COMMITTEE_TABS[activeTab.value]
    if (tab) {
      filters.needs_action = tab.needs_action
      filters.workflow_stage = ''
    }
  }

  return {
    isWorkbench,
    activeTab,
    workbenchTabs,
    applyWorkbenchFilters,
  }
}
