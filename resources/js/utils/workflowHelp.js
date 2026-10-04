const STAGE_HELP = {
  draft: {
    label: 'Draft',
    summary: 'Your proposal is not submitted yet.',
    next: 'Complete team details and submit when ready.',
  },
  proposal_submitted: {
    label: 'Proposal Submitted',
    summary: 'Waiting for all invited members to accept.',
    next: 'Members must accept invitations to continue.',
  },
  group_confirmed: {
    label: 'Group Confirmed',
    summary: 'Team is formed and waiting for supervisor response.',
    next: 'Supervisor will accept or decline supervision.',
  },
  supervisor_pending: {
    label: 'Awaiting Supervisor',
    summary: 'Supervisor has not responded yet.',
    next: 'Wait for supervisor acceptance or choose another supervisor if declined.',
  },
  supervisor_review: {
    label: 'Supervisor Review',
    summary: 'Supervisor is reviewing the proposal.',
    next: 'Supervisor may accept, request revision, or reject.',
  },
  committee_review: {
    label: 'Committee Review',
    summary: 'FYP committee is reviewing the proposal.',
    next: 'Evaluators may be assigned during this stage.',
  },
  evaluator_review: {
    label: 'Evaluator Review',
    summary: 'Assigned evaluators are reviewing the project.',
    next: 'All evaluators must submit their decision.',
  },
  revision_required: {
    label: 'Revision Required',
    summary: 'Changes are required before approval.',
    next: 'Update the proposal and resubmit.',
  },
  committee_final: {
    label: 'Final Committee Review',
    summary: 'Committee is performing final review.',
    next: 'Await committee head decision.',
  },
  approved: {
    label: 'Approved',
    summary: 'This phase has been approved.',
    next: 'Continue to the next phase when it opens.',
  },
  rejected: {
    label: 'Rejected',
    summary: 'This submission was rejected.',
    next: 'Contact your supervisor or committee for guidance.',
  },
}

export const workflowStageHelp = (stage) =>
  STAGE_HELP[stage] || {
    label: stage ? stage.replace(/_/g, ' ') : 'Unknown',
    summary: 'Workflow in progress.',
    next: 'Check project details for the latest update.',
  }

export const workflowStageLabel = (stage, fallbackLabel = '') =>
  fallbackLabel || workflowStageHelp(stage).label

export const sensitiveActionLabels = {
  assign_evaluators: 'Evaluators assigned',
  evaluator_review: 'Evaluator review submitted',
  committee_final: 'Committee final review recorded',
  committee_head_approve: 'Committee head approval',
  return_to_team_formation: 'Returned to team formation',
  supervisor_change: 'Supervisor changed',
  delete_project: 'Project deleted',
  accept_on_behalf: 'Invitation accepted on behalf',
}

export const isSensitiveAction = (action) =>
  [
    'assign_evaluators',
    'evaluator_review',
    'committee_final',
    'committee_head_approve',
    'return_to_team_formation',
    'supervisor_change',
    'delete_project',
    'accept_on_behalf',
    'assign_evaluators_on_behalf',
  ].includes(action)

export const formatActionLabel = (action) =>
  sensitiveActionLabels[action]
  || (action ? action.replace(/_/g, ' ') : 'Updated')
