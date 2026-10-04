export const FYP_PHASES = [
  { value: 'proposal', label: 'Proposal', order: 1 },
  { value: 'phase_1', label: 'Phase-1', order: 2 },
  { value: 'phase_2', label: 'Phase-2', order: 3 },
]

export const PHASE_STATUSES = [
  { value: 'draft', label: 'Draft', variant: 'secondary' },
  { value: 'submitted', label: 'Submitted', variant: 'warning' },
  { value: 'under_review', label: 'Under Review', variant: 'info' },
  { value: 'approved', label: 'Approved', variant: 'success' },
  { value: 'revision_required', label: 'Revision Required', variant: 'danger' },
  { value: 'rejected', label: 'Rejected', variant: 'danger' },
]

export const phaseLabel = (slug) =>
  FYP_PHASES.find((p) => p.value === slug)?.label ||
  slug.replace(/_/g, '-').replace(/\b\w/g, (c) => c.toUpperCase())

export const phaseStatusLabel = (slug) =>
  PHASE_STATUSES.find((s) => s.value === slug)?.label ||
  slug.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())

export const phaseStatusVariant = (slug) =>
  PHASE_STATUSES.find((s) => s.value === slug)?.variant || 'secondary'

export const isPhaseUnlocked = (phases, phaseSlug) => {
  if (phaseSlug === 'proposal') return true

  const order = FYP_PHASES.map((p) => p.value)
  const index = order.indexOf(phaseSlug)
  if (index <= 0) return false

  const previous = phases?.find((p) => p.phase === order[index - 1])
  return previous?.status === 'approved'
}

export const canReviewPhase = (phase) =>
  ['submitted', 'under_review'].includes(phase?.status)
