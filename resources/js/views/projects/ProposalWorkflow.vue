<template>
  <div class="proposal-workflow">
    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary"></div>
    </div>

    <div v-else>
      <ProposalSessionCountdown
        v-if="authStore.isStudent && sessionContext?.session"
        :session-context="sessionContext"
        class="mb-4"
        @expired="refreshSessionContext"
      />

    <div v-if="!project && canRegister && !registrationStatus.is_proposal_enrolled" class="row justify-content-center">
      <div class="col-12 col-lg-8">
        <AppCard title="Proposal Registration" subtitle="Enrollment required">
          <div class="alert alert-warning mb-0">
            You are not enrolled for the proposal phase yet. Please contact the FYP office or admin to enable proposal enrollment on your account.
          </div>
        </AppCard>
      </div>
    </div>

    <div v-else-if="!project && canRegister && canRegisterProposal" class="row justify-content-center">
      <div class="col-12 col-xl-10">
        <AppCard title="Submit Proposal" subtitle="Invite group members and select a supervisor" overflow-visible>
          <form @submit.prevent="registerProposal">
            <div v-if="formError" class="alert alert-danger py-2">{{ formError }}</div>
            <div v-if="registrationStatus.has_pending_invitations" class="alert alert-warning py-2">
              You have pending group invitations. Submitting your own proposal will automatically decline them.
            </div>
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label">Proposal Title</label>
                <input v-model="form.title" type="text" class="form-control" required />
              </div>
              <div class="col-md-4">
                <label class="form-label">Area of Specialization</label>
                <input v-model="form.area_of_specialization" type="text" class="form-control" />
              </div>
              <div class="col-12">
                <label class="form-label">Description</label>
                <textarea v-model="form.description" class="form-control" rows="2"></textarea>
              </div>
              <div class="col-12">
                <label class="form-label">Proposal Document (PDF only)</label>
                <input
                  ref="proposalFileInput"
                  type="file"
                  class="form-control"
                  accept="application/pdf,.pdf"
                  required
                  @change="onProposalFileChange"
                />
                <small v-if="proposalFileName" class="text-muted d-block mt-1">Selected: {{ proposalFileName }}</small>
              </div>
              <div class="col-md-6">
                <SupervisorPicker
                  v-model="form.supervisor_id"
                  :options="supervisors"
                  label="Supervisor"
                  placeholder="Search supervisor by name or email..."
                  hint="Search and select a supervisor for your proposal."
                  required
                />
              </div>
              <div class="col-md-6">
                <StudentMemberPicker
                  v-model="form.invitee_ids"
                  :options="eligibleMembers"
                  :min="settings.min_members - 1"
                  :max="settings.max_members - 1"
                  label="Invite Team Members"
                  :hint="`Select ${settings.min_members - 1} to ${settings.max_members - 1} students from your department who have not joined any group. Search by name or SAP ID.`"
                />
              </div>
            </div>
            <button type="submit" class="btn btn-primary mt-3" :disabled="saving">Submit Proposal & Send Invitations</button>
          </form>
        </AppCard>
      </div>
    </div>

    <div v-else-if="!project && canRegister && !canRegisterProposal" class="row justify-content-center">
      <div class="col-12 col-lg-8">
        <AppCard title="My Project" subtitle="Proposal registration unavailable">
          <div class="alert alert-info mb-0">
            {{ registrationBlockedMessage }}
          </div>
        </AppCard>
      </div>
    </div>

    <template v-else-if="project">
      <div v-if="authStore.isStudent && sessionContext?.is_fully_locked" class="alert alert-danger mb-4">
        All proposal changes are locked for your session. You can view your project but cannot make further edits until the FYP office grants an extension.
      </div>

      <div v-if="authStore.isStudent && approvedCertificates.length" class="workflow-card mb-4">
        <div class="workflow-card__header">
          <i class="bi bi-award me-2"></i>
          Phase Certificates
        </div>
        <div class="workflow-card__body">
          <p class="text-muted small mb-3">
            Download your approval certificate for each completed phase. Each certificate includes evaluator marks and that phase's workflow history only.
          </p>
          <div class="d-flex flex-wrap gap-2">
            <button
              v-for="certificate in approvedCertificates"
              :key="certificate.phase"
              type="button"
              class="btn btn-outline-primary btn-sm"
              :disabled="downloadingCertificatePhase === certificate.phase"
              @click="downloadCertificate(certificate.phase)"
            >
              <span v-if="downloadingCertificatePhase === certificate.phase" class="spinner-border spinner-border-sm me-1"></span>
              <i v-else class="bi bi-download me-1"></i>
              {{ certificate.phase_label }} Certificate
            </button>
          </div>
        </div>
      </div>

      <PhaseDeliverablePanel
        v-if="showPhaseDeliverable"
        :project="project"
        :session-context="sessionContext"
        @updated="reloadAfterPhaseUpdate"
        @error="(message) => { formError = message }"
      />

      <template v-if="showPhaseDeliverable">
        <div class="workflow-card mb-4">
          <div class="workflow-card__header">
            <i class="bi bi-clock-history me-2"></i>
            {{ project.current_phase_label }} Workflow
          </div>
          <div class="workflow-card__body">
            <div class="workflow-stepper">
              <div
                v-for="stage in phaseWorkflowStages"
                :key="stage.key"
                class="workflow-step"
                :class="stepClass(stage.key)"
              >
                <div class="workflow-step__circle">{{ stage.order }}</div>
                <div class="workflow-step__label">{{ stage.label }}</div>
              </div>
            </div>
          </div>
        </div>

        <div class="workflow-card mb-4">
          <div class="workflow-card__header">
            <i class="bi bi-diagram-3 me-2"></i> Phase Review Actions
          </div>
          <div class="workflow-card__body">
            <div v-if="authStore.isStudent && !canStudentResubmit && !canEditPhaseDeliverable && !canSupervisorReviewDeliverable" class="alert alert-info mb-3">
              Your {{ project.current_phase_label }} deliverable is at <strong>{{ project.workflow_stage_label }}</strong>.
              No action is required from you right now.
            </div>

            <div v-if="authStore.isStudent && project.awaiting_evaluator_reviews" class="alert alert-info mb-3">
              An evaluator has submitted feedback. Waiting for the remaining evaluator(s) to complete their review.
            </div>
            <div v-else-if="authStore.isStudent && project.workflow_stage === 'evaluator_review'" class="alert alert-info mb-3">
              Your deliverable is under evaluator review. The FYP committee will decide the next step after all evaluators complete their reviews.
            </div>

            <div v-if="canStudentResubmit && isLeader" class="mb-3">
              <div v-if="latestRevisionFeedback" class="alert alert-warning">{{ latestRevisionFeedback }}</div>
              <p class="text-muted small">Upload a revised file in the deliverable panel above, then resubmit for supervisor review.</p>
              <button type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="resubmitDeliverableAction">
                Resubmit Deliverable
              </button>
            </div>

            <div v-if="canSupervisorReviewDeliverable" class="mb-3">
              <div class="alert alert-info mb-2">The phase deliverable is waiting for your review.</div>
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="phaseSupervisorDecision(true)">
                  Approve Deliverable
                </button>
                <button type="button" class="btn btn-warning btn-sm" :disabled="saving" @click="openFeedbackModal('phase_supervisor_return')">
                  Return for Revision
                </button>
              </div>
            </div>

            <div v-if="canSupervisorReviewRevision" class="mb-3">
              <div class="alert alert-info mb-2">A revised deliverable is waiting for your review.</div>
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="phaseSupervisorRevisionProceed">
                  Proceed
                </button>
                <button type="button" class="btn btn-warning btn-sm" :disabled="saving" @click="openFeedbackModal('phase_supervisor_return_revision')">
                  Return to Students
                </button>
              </div>
            </div>

            <div v-if="canManageSupervisorRevisionReview" class="mb-3">
              <div class="alert alert-info small mb-2">
                A revised deliverable is waiting for review by
                <strong>{{ project.supervisor?.name || 'the assigned supervisor' }}</strong>.
                As FYP office, you can approve or return it on their behalf.
              </div>
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="phaseSupervisorRevisionProceed">
                  Proceed
                </button>
                <button type="button" class="btn btn-warning btn-sm" :disabled="saving" @click="openFeedbackModal('phase_supervisor_return_revision')">
                  Return to Students
                </button>
              </div>
            </div>

            <div v-if="canAssignEvaluators" class="mb-3">
              <EvaluatorPicker
                v-model="selectedEvaluators"
                :options="availableEvaluatorsForProject"
                :min="settings.min_evaluators"
                :max="settings.max_evaluators"
                :label="`Assign Evaluators (${settings.min_evaluators}-${settings.max_evaluators})`"
                :hint="`Select ${settings.min_evaluators} to ${settings.max_evaluators} evaluators. The project supervisor cannot be selected as an evaluator.`"
              />
              <div v-if="project.workflow_stage === 'revision_required'" class="mt-3">
                <label class="form-label">After student resubmits, require review from</label>
                <p class="text-muted small mb-2">
                  Revised deliverables are sent to the supervisor first. After the supervisor proceeds, the selected evaluators will review again.
                </p>
                <div class="form-check">
                  <input
                    id="phase-resubmit-negative-only"
                    v-model="evaluatorResubmitMode"
                    class="form-check-input"
                    type="radio"
                    value="negative_only"
                  />
                  <label class="form-check-label" for="phase-resubmit-negative-only">
                    Only evaluators who rejected or requested revision
                  </label>
                </div>
                <div class="form-check">
                  <input
                    id="phase-resubmit-all"
                    v-model="evaluatorResubmitMode"
                    class="form-check-input"
                    type="radio"
                    value="all"
                  />
                  <label class="form-check-label" for="phase-resubmit-all">
                    All assigned evaluators
                  </label>
                </div>
              </div>
              <div class="d-flex flex-wrap gap-2 mt-2">
                <button
                  v-if="project.can_keep_existing_evaluators"
                  type="button"
                  class="btn btn-outline-primary btn-sm"
                  :disabled="saving"
                  @click="keepPhaseEvaluatorsAction"
                >
                  Keep Existing Evaluators
                </button>
                <button type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="assignPhaseEvaluatorsAction">
                  {{ hasAssignedEvaluators ? 'Update Evaluators' : 'Assign Evaluators' }}
                </button>
                <button
                  v-if="project.can_return_deliverable_from_committee"
                  type="button"
                  class="btn btn-warning btn-sm"
                  :disabled="saving"
                  @click="openFeedbackModal('phase_committee_return')"
                >
                  Send Back to Students
                </button>
              </div>
            </div>

            <div v-if="canReevaluateCurrentPhase" class="workflow-card workflow-card--dropdown mb-3">
              <div class="workflow-card__header">
                <i class="bi bi-arrow-repeat me-2"></i> Reevaluate
              </div>
              <div class="workflow-card__body">
                <div v-if="!reevaluateOpen">
                  <p class="text-muted small mb-2">
                    This deliverable has already been approved. You can reopen it for a fresh evaluation —
                    even if the phase has since been locked or a report generated. This only redoes this
                    phase's review; it doesn't move the project backward.
                  </p>
                  <button type="button" class="btn btn-outline-warning btn-sm" @click="openReevaluatePanel">
                    Reevaluate
                  </button>
                </div>
                <div v-else>
                  <div v-if="reevaluateError" class="alert alert-danger py-2">{{ reevaluateError }}</div>
                  <div class="mb-3">
                    <label class="form-label d-block">Evaluators</label>
                    <div class="form-check form-check-inline">
                      <input id="reeval-same" v-model="reevaluateForm.keepSame" class="form-check-input" type="radio" :value="true" />
                      <label class="form-check-label" for="reeval-same">Keep same evaluators</label>
                    </div>
                    <div class="form-check form-check-inline">
                      <input id="reeval-new" v-model="reevaluateForm.keepSame" class="form-check-input" type="radio" :value="false" />
                      <label class="form-check-label" for="reeval-new">Assign new evaluators</label>
                    </div>
                  </div>
                  <div v-if="!reevaluateForm.keepSame" class="mb-3">
                    <EvaluatorPicker
                      v-model="reevaluateForm.evaluatorIds"
                      :options="availableEvaluatorsForProject"
                      :min="settings.min_evaluators"
                      :max="settings.max_evaluators"
                      :label="`New Evaluators (${settings.min_evaluators}-${settings.max_evaluators})`"
                    />
                  </div>
                  <div class="mb-3" style="max-width: 260px">
                    <label class="form-label">Extension Deadline</label>
                    <input v-model="reevaluateForm.deadline" type="date" class="form-control" />
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Notes (optional)</label>
                    <textarea v-model="reevaluateForm.notes" class="form-control" rows="2"></textarea>
                  </div>
                  <div class="d-flex gap-2">
                    <button type="button" class="btn btn-warning btn-sm" :disabled="saving" @click="submitReevaluate">
                      Start Reevaluation
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="saving" @click="reevaluateOpen = false">
                      Cancel
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="canEvaluatorAct" class="evaluator-actions mb-3">
              <label class="form-label">Evaluation Questions</label>
              <EvaluatorAnswerForm v-model="evaluatorAnswers" :questions="evaluationQuestions" :disabled="saving" />
              <label class="form-label mt-2">Final Comment (required)</label>
              <textarea v-model="evaluatorComments" class="form-control mb-3" rows="3" placeholder="Overall evaluation comment"></textarea>
              <small v-if="evaluatorCommentError" class="text-danger d-block mb-2">{{ evaluatorCommentError }}</small>
              <small v-else class="text-muted d-block mb-3">
                Every question must be answered. A final comment is required for every decision.
              </small>
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="submitPhaseEvaluatorDecision('accepted')">Accept</button>
                <button type="button" class="btn btn-warning btn-sm" :disabled="saving" @click="submitPhaseEvaluatorDecision('revision_required')">Request Revision</button>
                <button type="button" class="btn btn-danger btn-sm" :disabled="saving" @click="submitPhaseEvaluatorDecision('rejected')">Reject</button>
              </div>
            </div>

            <div v-if="canManageEvaluatorReviews && showPhaseDeliverable" class="evaluator-actions mb-3">
              <div class="alert alert-info small mb-2">
                Submit a {{ project.current_phase_label }} evaluation on behalf of an assigned evaluator who has not completed their review yet.
              </div>
              <label class="form-label">Evaluator</label>
              <select v-model="officeEvaluatorId" class="form-select mb-3" style="max-width: 360px">
                <option :value="null">Select evaluator</option>
                <option v-for="evaluator in officePendingEvaluators" :key="evaluator.id" :value="evaluator.id">
                  {{ evaluator.name }}
                </option>
              </select>
              <label class="form-label">Evaluation Questions</label>
              <EvaluatorAnswerForm v-model="officeEvaluatorAnswers" :questions="evaluationQuestions" :disabled="saving" />
              <label class="form-label mt-2">Final Comment (required)</label>
              <textarea
                v-model="officeEvaluatorComments"
                class="form-control mb-3"
                rows="3"
                placeholder="Overall evaluation comment on behalf of the selected evaluator..."
                :disabled="saving"
              ></textarea>
              <small v-if="officeEvaluatorCommentError" class="text-danger d-block mb-2">{{ officeEvaluatorCommentError }}</small>
              <small v-else class="text-muted d-block mb-3">
                Every question must be answered. A final comment is required for every decision.
              </small>
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success btn-sm" :disabled="saving || !officeEvaluatorId" @click="submitPhaseOfficeEvaluatorDecision('accepted')">
                  Accept
                </button>
                <button type="button" class="btn btn-warning btn-sm" :disabled="saving || !officeEvaluatorId" @click="submitPhaseOfficeEvaluatorDecision('revision_required')">
                  Request Revision
                </button>
                <button type="button" class="btn btn-danger btn-sm" :disabled="saving || !officeEvaluatorId" @click="submitPhaseOfficeEvaluatorDecision('rejected')">
                  Reject
                </button>
              </div>
            </div>

            <div v-if="committeeHeadWaitingBanner" class="alert alert-info mb-3">
              This project is at committee final review. A committee member must forward it before you can approve it as Committee Head.
            </div>

            <div v-if="canCommitteeFinal && showPhaseDeliverable" class="committee-actions mb-3">
              <div v-if="currentPhaseEvaluatorReviews.length" class="mb-3">
                <label class="form-label">Evaluator Decisions</label>
                <ul class="list-group mb-0">
                  <li
                    v-for="review in currentPhaseEvaluatorReviews"
                    :key="review.id"
                    class="list-group-item"
                  >
                    <div class="d-flex justify-content-between align-items-start gap-2">
                      <strong>{{ review.evaluator?.name || 'Evaluator' }}</strong>
                      <AppBadge :variant="reviewVariant(review.decision)">{{ reviewLabel(review.decision) }}</AppBadge>
                    </div>
                    <div v-if="review.marks !== null" class="text-muted small mt-1">Marks: {{ review.marks }}/{{ review.max_marks ?? '?' }}</div>
                    <ul v-if="review.answers?.length" class="list-unstyled small text-muted mt-1 mb-0 ps-2">
                      <li v-for="answer in review.answers" :key="answer.id">
                        {{ answer.question_text }}: {{ answer.marks_awarded }}/{{ answer.max_marks }}
                        <span v-if="answer.comment"> — {{ answer.comment }}</span>
                      </li>
                    </ul>
                    <div v-if="review.comments" class="text-muted small mt-2">{{ review.comments }}</div>
                  </li>
                </ul>
              </div>
              <label class="form-label">Review Comments</label>
              <textarea
                v-model="committeeComments"
                class="form-control mb-3"
                rows="4"
                placeholder="Enter your review comments..."
                :disabled="saving"
              ></textarea>
              <small v-if="committeeCommentError" class="text-danger d-block mb-2">{{ committeeCommentError }}</small>
              <small v-else class="text-muted d-block mb-3">
                Comments are required when returning for revision. Optional when forwarding to Committee Head.
              </small>
              <div class="mb-3">
                <label class="form-label">After student resubmits, require review from</label>
                <div class="form-check">
                  <input
                    id="phase-committee-resubmit-negative-only"
                    v-model="evaluatorResubmitMode"
                    class="form-check-input"
                    type="radio"
                    value="negative_only"
                  />
                  <label class="form-check-label" for="phase-committee-resubmit-negative-only">
                    Only evaluators who rejected or requested revision
                  </label>
                </div>
                <div class="form-check">
                  <input
                    id="phase-committee-resubmit-all"
                    v-model="evaluatorResubmitMode"
                    class="form-check-input"
                    type="radio"
                    value="all"
                  />
                  <label class="form-check-label" for="phase-committee-resubmit-all">
                    All assigned evaluators
                  </label>
                </div>
              </div>
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="phaseCommitteeFinalForward">
                  Forward to Committee Head
                </button>
                <button type="button" class="btn btn-warning btn-sm" :disabled="saving" @click="phaseCommitteeFinalReturn">
                  Return for Revision
                </button>
              </div>
            </div>

            <div v-if="canCommitteeHeadAct && showPhaseDeliverable" class="committee-actions mb-3">
              <label class="form-label">Review Comments</label>
              <textarea
                v-model="headComments"
                class="form-control mb-3"
                rows="4"
                placeholder="Enter your review comments..."
                :disabled="saving"
              ></textarea>
              <small v-if="headCommentError" class="text-danger d-block mb-2">{{ headCommentError }}</small>
              <div class="mb-3">
                <label class="form-label">After student resubmits, require review from</label>
                <div class="form-check">
                  <input
                    id="phase-head-resubmit-negative-only"
                    v-model="evaluatorResubmitMode"
                    class="form-check-input"
                    type="radio"
                    value="negative_only"
                  />
                  <label class="form-check-label" for="phase-head-resubmit-negative-only">
                    Only evaluators who rejected or requested revision
                  </label>
                </div>
                <div class="form-check">
                  <input
                    id="phase-head-resubmit-all"
                    v-model="evaluatorResubmitMode"
                    class="form-check-input"
                    type="radio"
                    value="all"
                  />
                  <label class="form-check-label" for="phase-head-resubmit-all">
                    All assigned evaluators
                  </label>
                </div>
              </div>
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="phaseCommitteeHeadApproveAction">
                  Approve Phase
                </button>
                <button type="button" class="btn btn-warning btn-sm" :disabled="saving" @click="phaseCommitteeHeadReturnAction">
                  Return for Revision
                </button>
              </div>
            </div>
          </div>
        </div>

        <div v-if="canDecidePhaseRepeat" class="workflow-card mb-4">
          <div class="workflow-card__header">
            <i class="bi bi-arrow-repeat me-2"></i> Phase Repeat Decision
          </div>
          <div class="workflow-card__body">
            <p class="text-muted small mb-3">
              This deliverable has not been approved. Decide whether the student continues into the next
              available session without a new proposal, or must submit a brand new proposal.
            </p>
            <div v-if="project.repeat_decision" class="alert alert-info py-2 mb-3">
              Decision recorded:
              <strong>{{ project.repeat_decision === 'carry_forward' ? 'Carry forward to next session' : 'Resubmission required' }}</strong>
              <span v-if="project.repeat_notes"> — {{ project.repeat_notes }}</span>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="allowPhaseRepeatCarryForward">
                Allow / Enroll in Next Session
              </button>
              <button type="button" class="btn btn-danger btn-sm" :disabled="saving" @click="requirePhaseRepeatResubmission">
                Require New Proposal
              </button>
            </div>
          </div>
        </div>
      </template>

      <div v-if="!showPhaseDeliverable">
      <div v-if="project" class="workflow-card mb-4">
        <div class="workflow-card__header d-flex flex-wrap justify-content-between align-items-center gap-2">
          <span><i class="bi bi-people me-2"></i> Team Status</span>
          <div class="d-flex flex-wrap gap-2">
            <WorkflowStatusBadge
              :stage="project.workflow_stage"
              :fallback-label="project.workflow_stage_label"
            />
            <DeadlineBadge
              v-if="sessionContext?.session?.submission_deadline"
              :deadline="sessionContext.session.submission_deadline"
              :countdown="sessionContext?.countdown"
            />
          </div>
        </div>
        <div class="workflow-card__body">
          <div v-if="teamStatusMembers.length" class="team-status-list">
            <div v-for="member in teamStatusMembers" :key="member.id" class="team-status-item">
              <span class="fw-semibold">{{ member.name }}</span>
              <AppBadge :variant="member.role === 'leader' ? 'primary' : 'info'" class="ms-2">
                {{ member.roleLabel }}
              </AppBadge>
              <span v-if="member.sap_id" class="text-muted small ms-2">SAP: {{ member.sap_id }}</span>
            </div>
          </div>
          <div v-else class="text-muted small">No active team members yet.</div>
        </div>
      </div>

      <div class="workflow-card mb-4">
        <div class="workflow-card__header">
          <i class="bi bi-clock-history me-2"></i> Proposal Workflow
        </div>
        <div class="workflow-card__body">
          <div class="workflow-stepper">
            <div
              v-for="stage in workflowStages"
              :key="stage.key"
              class="workflow-step"
              :class="stepClass(stage.key)"
            >
              <div class="workflow-step__circle">{{ stage.order }}</div>
              <div class="workflow-step__label">{{ stage.label }}</div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="pendingInvites.length" class="alert alert-info">
        <strong>Pending invitations:</strong>
        <div
          v-for="inv in pendingInvites"
          :key="inv.id"
          class="pending-invite-row mt-2"
        >
          <span class="invite-badge badge bg-warning text-dark">
            <span>{{ inv.invitee?.name || 'Student' }} — {{ inv.status }}</span>
            <span v-if="canViewInviteeContact && inv.invitee?.email" class="invite-badge__email">
              {{ inv.invitee.email }}
            </span>
            <button
              v-if="canAcceptInvitationsOnBehalf"
              type="button"
              class="invite-badge__accept"
              title="Accept on behalf of student"
              :disabled="saving"
              @click="confirmAcceptInvitationOnBehalf(inv)"
            >
              <i class="bi bi-check-lg"></i>
            </button>
            <button
              v-if="canCancelInvitations"
              type="button"
              class="invite-badge__cancel"
              title="Cancel invitation"
              :disabled="saving"
              @click="confirmCancelInvitation(inv)"
            >
              <i class="bi bi-x-lg"></i>
            </button>
          </span>
        </div>
      </div>

      <div v-if="rejectedInvites.length" class="alert alert-warning">
        <strong>Rejected invitations:</strong>
        <div v-for="inv in rejectedInvites" :key="inv.id" class="small mt-1">
          {{ inv.invitee?.name || 'Student' }}
          <span v-if="canViewInviteeContact && inv.invitee?.email" class="text-muted"> · {{ inv.invitee.email }}</span>
          <span v-if="inv.response_comments"> — {{ inv.response_comments }}</span>
        </div>
      </div>

      <div v-if="showTeamFormationTools" class="workflow-card workflow-card--dropdown mb-4">
        <div class="workflow-card__header">
          <i class="bi bi-person-plus me-2"></i>
          {{ canAdminManageTeam && !canInviteMembers ? 'Manage Team Members' : 'Invite Team Members' }}
        </div>
        <div class="workflow-card__body">
          <p class="text-muted small mb-3">
            Group size: {{ teamInvite.member_count }}/{{ settings.max_members }} members.
            <span v-if="teamInvite.members_needed > 0">
              Need {{ teamInvite.members_needed }} more member(s) to reach the minimum of {{ settings.min_members }}.
            </span>
            <span v-if="teamInvite.open_slots > 0">
              You can invite up to {{ teamInvite.open_slots }} more student(s).
            </span>
          </p>
          <StudentMemberPicker
            v-model="reinviteMemberIds"
            :options="eligibleMembers"
            :min="1"
            :max="Math.max(1, teamInvite.open_slots)"
            :label="canDirectAddMembers ? 'Select Students' : 'Select Students to Invite'"
            :hint="teamFormationHint"
          />
          <div class="d-flex flex-wrap gap-2 mt-2">
            <button
              type="button"
              class="btn btn-primary btn-sm"
              :disabled="saving || !reinviteMemberIds.length || !teamInvite.open_slots"
              @click="sendInvitations"
            >
              Send Invitations
            </button>
            <button
              v-if="canDirectAddMembers"
              type="button"
              class="btn btn-outline-primary btn-sm"
              :disabled="saving || !reinviteMemberIds.length || !teamInvite.open_slots"
              @click="confirmDirectAddMembers"
            >
              Add to Team Directly
            </button>
          </div>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-12 col-lg-5">
          <div class="workflow-card h-100">
            <div class="workflow-card__header">
              <i class="bi bi-file-earmark-text me-2"></i> {{ isAssignedEvaluatorView ? 'Proposal Details' : 'My Proposal Details' }}
            </div>
            <div class="workflow-card__body p-0">
              <table class="table details-table mb-0">
                <tbody>
                  <tr v-for="row in detailRows" :key="row.label">
                    <th>{{ row.label }}</th>
                    <td>
                      <AppBadge v-if="row.badge" :variant="row.variant">{{ row.value }}</AppBadge>
                      <a v-else-if="row.link" :href="row.link" target="_blank" rel="noopener" class="proposal-doc-link">
                        <i class="bi bi-file-earmark-pdf me-1"></i>{{ row.value }}
                      </a>
                      <span v-else>{{ row.value }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-7">
          <div v-if="isAssignedEvaluatorView" class="workflow-card h-100">
            <div class="workflow-card__header">
              <i class="bi bi-file-earmark-pdf me-2"></i> Proposal Document
            </div>
            <div class="workflow-card__body proposal-pdf-panel">
              <div v-if="proposalDocumentUrl" class="proposal-pdf-viewer">
                <ProposalPdfPreview :url="proposalDocumentUrl" />
                <a
                  :href="proposalDocumentUrl"
                  target="_blank"
                  rel="noopener"
                  class="proposal-doc-link mt-2 d-inline-block"
                >
                  <i class="bi bi-box-arrow-up-right me-1"></i>
                  Open {{ proposalPhase?.content || 'PDF' }} in new tab
                </a>
              </div>
              <div v-else class="text-muted">No proposal PDF uploaded.</div>
            </div>
          </div>
          <div v-else class="workflow-card h-100">
            <div class="workflow-card__header">
              <i class="bi bi-chat-left-text me-2"></i> Review & Approval Remarks
            </div>
            <div class="workflow-card__body remarks-list">
              <WorkflowTimeline v-if="project.workflow_logs?.length" :items="project.workflow_logs" />
              <div v-else class="text-muted">No remarks yet.</div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="canManageProjectTeam && project" class="workflow-card mt-4">
        <div class="workflow-card__header workflow-card__header--danger">
          <i class="bi bi-exclamation-triangle-fill me-2"></i> Team Actions
        </div>
        <div class="workflow-card__body">
          <p class="text-muted small mb-3">
            Return the group to team formation or delete the project to dissolve the entire team.
            To remove or add individual members, use the Team Members card above.
          </p>

          <div class="team-delete-section">
            <button
              v-if="canReturnToTeamFormation"
              type="button"
              class="btn btn-warning btn-sm me-2"
              :disabled="saving"
              @click="openTeamActionModal('return-to-team-formation')"
            >
              <i class="bi bi-arrow-counterclockwise me-1"></i> Return to Team Formation
            </button>
            <button
              type="button"
              class="btn btn-danger btn-sm"
              :disabled="saving"
              @click="openTeamActionModal('delete-project')"
            >
              <i class="bi bi-trash me-1"></i> Delete Project & Break Team
            </button>
          </div>
        </div>
      </div>

      <div class="workflow-card workflow-card--dropdown mt-4">
        <div class="workflow-card__header">Actions</div>
        <div class="workflow-card__body">
          <div v-if="canReturnToTeamFormation && !canManageProjectTeam" class="mb-3">
            <p class="text-muted small mb-2">
              Send this proposal back to the team formation stage so the group leader can invite more members.
            </p>
            <button
              type="button"
              class="btn btn-warning btn-sm"
              :disabled="saving"
              @click="openTeamActionModal('return-to-team-formation')"
            >
              <i class="bi bi-arrow-counterclockwise me-1"></i> Return to Team Formation
            </button>
          </div>

          <div v-if="canStudentResubmit" class="mb-3">
            <div v-if="latestRevisionFeedback" class="alert alert-warning">
              <strong>Revision feedback:</strong> {{ latestRevisionFeedback }}
            </div>
            <label class="form-label">Upload Revised Proposal (PDF only)</label>
            <input
              ref="revisionFileInput"
              type="file"
              class="form-control mb-2"
              accept="application/pdf,.pdf"
              @change="onRevisionFileChange"
            />
            <small v-if="revisionFileName" class="text-muted d-block mb-2">Selected: {{ revisionFileName }}</small>
            <a
              v-if="proposalDocumentUrl"
              :href="proposalDocumentUrl"
              target="_blank"
              rel="noopener"
              class="d-inline-block mb-2 proposal-doc-link"
            >
              <i class="bi bi-file-earmark-pdf me-1"></i>View current proposal
            </a>
            <button type="button" class="btn btn-primary btn-sm d-block" :disabled="saving" @click="resubmit">
              Resubmit Proposal
            </button>
          </div>

          <div v-else-if="authStore.isStudent && project.workflow_stage === 'supervisor_revision_pending'" class="alert alert-info mb-0">
            Your revised proposal has been submitted and is awaiting supervisor review before it can proceed.
          </div>

          <div v-else-if="authStore.isStudent && project.workflow_stage === 'revision_required'" class="alert alert-info mb-0">
            Revision is required. Please contact your group leader to upload the revised proposal PDF.
          </div>

          <div v-else-if="project.awaiting_evaluator_reviews" class="alert alert-info mb-0">
            An evaluator has submitted feedback. Waiting for the remaining evaluator(s) to complete their review. The FYP committee will decide the next step after all reviews are in.
          </div>

          <div v-else-if="authStore.isStudent && project.workflow_stage === 'evaluator_review'" class="alert alert-info mb-0">
            Your proposal is under evaluator review. The FYP committee will decide whether to forward it or return it for revision after all evaluators complete their reviews.
          </div>

          <div v-if="isLeader && project.workflow_stage === 'supervisor_rejected'" class="mb-3">
            <div class="alert alert-warning">{{ project.supervisor_rejection_feedback }}</div>
            <div class="d-flex flex-wrap align-items-end gap-2">
              <div class="flex-grow-1" style="min-width: 280px; max-width: 420px">
                <SupervisorPicker
                  v-model="newSupervisorId"
                  :options="availableSupervisorsForProject"
                  label="Select New Supervisor"
                  placeholder="Search supervisor by name or email..."
                  required
                />
              </div>
              <button type="button" class="btn btn-primary btn-sm mb-1" :disabled="saving || !newSupervisorId" @click="updateSupervisor">
                Update Supervisor
              </button>
            </div>
          </div>

          <div v-if="canSupervisorReviewRevision" class="mb-3">
            <div class="alert alert-info mb-2">
              A revised proposal is waiting for your review. Proceed to send it forward, or return it to the students with comments.
            </div>
            <a
              v-if="proposalDocumentUrl"
              :href="proposalDocumentUrl"
              target="_blank"
              rel="noopener"
              class="d-inline-block mb-2 proposal-doc-link"
            >
              <i class="bi bi-file-earmark-pdf me-1"></i>View revised proposal
            </a>
            <div class="d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="supervisorRevisionProceed">
                Proceed
              </button>
              <button type="button" class="btn btn-warning btn-sm" :disabled="saving" @click="openFeedbackModal('supervisor_return_revision')">
                Return to Students
              </button>
            </div>
          </div>

          <div v-if="canManageSupervisorRevisionReview" class="mb-3">
            <div class="alert alert-info small mb-2">
              A revised proposal is waiting for review by
              <strong>{{ project.supervisor?.name || 'the assigned supervisor' }}</strong>.
              As FYP office, you can approve or return it on their behalf.
            </div>
            <a
              v-if="proposalDocumentUrl"
              :href="proposalDocumentUrl"
              target="_blank"
              rel="noopener"
              class="d-inline-block mb-2 proposal-doc-link"
            >
              <i class="bi bi-file-earmark-pdf me-1"></i>View revised proposal
            </a>
            <div class="d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="supervisorRevisionProceed">
                Proceed
              </button>
              <button type="button" class="btn btn-warning btn-sm" :disabled="saving" @click="openFeedbackModal('supervisor_return_revision')">
                Return to Students
              </button>
            </div>
          </div>

          <div v-if="canManageSupervisorInvitation" class="mb-3">
            <div class="alert alert-info small mb-2">
              Supervision request for
              <strong>{{ project.supervisor?.name || 'assigned supervisor' }}</strong>
              <span v-if="project.supervisor?.email"> ({{ project.supervisor.email }})</span>
              is pending.
              As FYP office, you can accept or decline this request on their behalf.
            </div>
            <div class="d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="supervisorDecision(true)">
                Accept Supervision
              </button>
              <button type="button" class="btn btn-warning btn-sm" :disabled="saving" @click="openFeedbackModal('supervisor_request_revision')">
                Request Revision
              </button>
              <button type="button" class="btn btn-danger btn-sm" :disabled="saving" @click="openFeedbackModal('supervisor_reject')">
                Decline Supervision
              </button>
            </div>
          </div>

          <div v-if="canSupervisorAct" class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="supervisorDecision(true)">Accept Supervision</button>
            <button type="button" class="btn btn-warning btn-sm" :disabled="saving" @click="openFeedbackModal('supervisor_request_revision')">Request Revision</button>
            <button type="button" class="btn btn-danger btn-sm" :disabled="saving" @click="openFeedbackModal('supervisor_reject')">Decline Supervision</button>
          </div>

          <div v-if="canAssignEvaluators" class="mb-3">
            <EvaluatorPicker
              v-model="selectedEvaluators"
              :options="availableEvaluatorsForProject"
              :min="settings.min_evaluators"
              :max="settings.max_evaluators"
              :label="`Assign Evaluators (${settings.min_evaluators}-${settings.max_evaluators})`"
              :hint="`Select ${settings.min_evaluators} to ${settings.max_evaluators} evaluators. The project supervisor cannot be selected as an evaluator.`"
            />
            <div v-if="project.workflow_stage === 'revision_required'" class="mt-3">
              <label class="form-label">After student resubmits, require review from</label>
              <p class="text-muted small mb-2">Revised proposals are sent to the supervisor first. After the supervisor proceeds, the selected evaluators will review again.</p>
              <div class="form-check">
                <input
                  id="resubmit-negative-only"
                  v-model="evaluatorResubmitMode"
                  class="form-check-input"
                  type="radio"
                  value="negative_only"
                />
                <label class="form-check-label" for="resubmit-negative-only">
                  Only evaluators who rejected or requested revision
                </label>
              </div>
              <div class="form-check">
                <input
                  id="resubmit-all"
                  v-model="evaluatorResubmitMode"
                  class="form-check-input"
                  type="radio"
                  value="all"
                />
                <label class="form-check-label" for="resubmit-all">
                  All assigned evaluators
                </label>
              </div>
            </div>
            <button type="button" class="btn btn-primary btn-sm mt-2" :disabled="saving" @click="assignEvaluatorsAction">
              {{ hasAssignedEvaluators ? 'Update Evaluators' : 'Assign Evaluators' }}
            </button>
          </div>

          <div v-if="canEvaluatorAct" class="evaluator-actions">
            <label class="form-label">Evaluation Questions</label>
            <EvaluatorAnswerForm v-model="evaluatorAnswers" :questions="evaluationQuestions" :disabled="saving" />
            <label class="form-label mt-2">Final Comment (required)</label>
            <textarea
              v-model="evaluatorComments"
              class="form-control mb-3"
              rows="3"
              placeholder="Enter your overall evaluation comment..."
              :disabled="saving"
            ></textarea>
            <small v-if="evaluatorCommentError" class="text-danger d-block mb-2">{{ evaluatorCommentError }}</small>
            <small v-else class="text-muted d-block mb-3">
              Every question must be answered. A final comment is required for every decision.
            </small>
            <div class="d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="submitEvaluatorDecision('accepted')">
                Accept
              </button>
              <button type="button" class="btn btn-warning btn-sm" :disabled="saving" @click="submitEvaluatorDecision('revision_required')">
                Request Revision
              </button>
              <button type="button" class="btn btn-danger btn-sm" :disabled="saving" @click="submitEvaluatorDecision('rejected')">
                Reject
              </button>
            </div>
          </div>

          <div v-if="canManageEvaluatorReviews" class="evaluator-actions mb-3">
            <div class="alert alert-info small mb-2">
              Submit an evaluation on behalf of an assigned evaluator who has not completed their review yet.
            </div>
            <label class="form-label">Evaluator</label>
            <select v-model="officeEvaluatorId" class="form-select mb-3" style="max-width: 360px">
              <option :value="null">Select evaluator</option>
              <option v-for="evaluator in officePendingEvaluators" :key="evaluator.id" :value="evaluator.id">
                {{ evaluator.name }}
              </option>
            </select>
            <label class="form-label">Evaluation Questions</label>
            <EvaluatorAnswerForm v-model="officeEvaluatorAnswers" :questions="evaluationQuestions" :disabled="saving" />
            <label class="form-label mt-2">Final Comment (required)</label>
            <textarea
              v-model="officeEvaluatorComments"
              class="form-control mb-3"
              rows="3"
              placeholder="Enter evaluation comments on behalf of the selected evaluator..."
              :disabled="saving"
            ></textarea>
            <small v-if="officeEvaluatorCommentError" class="text-danger d-block mb-2">{{ officeEvaluatorCommentError }}</small>
            <small v-else class="text-muted d-block mb-3">
              Every question must be answered. A final comment is required for every decision.
            </small>
            <div class="d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-success btn-sm" :disabled="saving || !officeEvaluatorId" @click="submitOfficeEvaluatorDecision('accepted')">
                Accept
              </button>
              <button type="button" class="btn btn-warning btn-sm" :disabled="saving || !officeEvaluatorId" @click="submitOfficeEvaluatorDecision('revision_required')">
                Request Revision
              </button>
              <button type="button" class="btn btn-danger btn-sm" :disabled="saving || !officeEvaluatorId" @click="submitOfficeEvaluatorDecision('rejected')">
                Reject
              </button>
            </div>
          </div>

          <div v-if="committeeHeadWaitingBanner" class="alert alert-info mb-3">
            This project is at committee final review. A committee member must forward it before you can approve it as Committee Head.
          </div>

          <div v-if="canCommitteeFinal" class="committee-actions">
            <div v-if="project.evaluator_reviews?.length" class="mb-3">
              <label class="form-label">Evaluator Decisions</label>
              <ul class="list-group mb-0">
                <li
                  v-for="review in project.evaluator_reviews"
                  :key="review.id"
                  class="list-group-item"
                >
                  <div class="d-flex justify-content-between align-items-start gap-2">
                    <strong>{{ review.evaluator?.name || 'Evaluator' }}</strong>
                    <AppBadge :variant="reviewVariant(review.decision)">{{ reviewLabel(review.decision) }}</AppBadge>
                  </div>
                  <div v-if="review.marks !== null" class="text-muted small mt-1">Marks: {{ review.marks }}/{{ review.max_marks ?? '?' }}</div>
                  <ul v-if="review.answers?.length" class="list-unstyled small text-muted mt-1 mb-0 ps-2">
                    <li v-for="answer in review.answers" :key="answer.id">
                      {{ answer.question_text }}: {{ answer.marks_awarded }}/{{ answer.max_marks }}
                      <span v-if="answer.comment"> — {{ answer.comment }}</span>
                    </li>
                  </ul>
                  <div v-if="review.comments" class="text-muted small mt-2">{{ review.comments }}</div>
                </li>
              </ul>
            </div>
            <label class="form-label">Review Comments</label>
            <textarea
              v-model="committeeComments"
              class="form-control mb-3"
              rows="4"
              placeholder="Enter your review comments..."
              :disabled="saving"
            ></textarea>
            <small v-if="committeeCommentError" class="text-danger d-block mb-2">{{ committeeCommentError }}</small>
            <small v-else class="text-muted d-block mb-3">
              Comments are required when returning for revision. Optional when forwarding to Committee Head.
            </small>
            <div class="mb-3">
              <label class="form-label">After student resubmits, require review from</label>
              <div class="form-check">
                <input
                  id="committee-resubmit-negative-only"
                  v-model="evaluatorResubmitMode"
                  class="form-check-input"
                  type="radio"
                  value="negative_only"
                />
                <label class="form-check-label" for="committee-resubmit-negative-only">
                  Only evaluators who rejected or requested revision
                </label>
              </div>
              <div class="form-check">
                <input
                  id="committee-resubmit-all"
                  v-model="evaluatorResubmitMode"
                  class="form-check-input"
                  type="radio"
                  value="all"
                />
                <label class="form-check-label" for="committee-resubmit-all">
                  All assigned evaluators
                </label>
              </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="committeeDecision(true)">
                Forward to Committee Head
              </button>
              <button type="button" class="btn btn-warning btn-sm" :disabled="saving" @click="committeeDecision(false)">
                Return for Revision
              </button>
            </div>
          </div>

          <div v-if="canCommitteeHeadAct" class="committee-actions">
            <label class="form-label">Review Comments</label>
            <textarea
              v-model="headComments"
              class="form-control mb-3"
              rows="4"
              placeholder="Enter your review comments..."
              :disabled="saving"
            ></textarea>
            <small v-if="headCommentError" class="text-danger d-block mb-2">{{ headCommentError }}</small>
            <small v-else class="text-muted d-block mb-3">
              Comments are required when returning for revision. Optional when approving.
            </small>
            <div class="mb-3">
              <label class="form-label">After student resubmits, require review from</label>
              <div class="form-check">
                <input
                  id="head-resubmit-negative-only"
                  v-model="evaluatorResubmitMode"
                  class="form-check-input"
                  type="radio"
                  value="negative_only"
                />
                <label class="form-check-label" for="head-resubmit-negative-only">
                  Only evaluators who rejected or requested revision
                </label>
              </div>
              <div class="form-check">
                <input
                  id="head-resubmit-all"
                  v-model="evaluatorResubmitMode"
                  class="form-check-input"
                  type="radio"
                  value="all"
                />
                <label class="form-check-label" for="head-resubmit-all">
                  All assigned evaluators
                </label>
              </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="headDecision(true)">
                Approve Proposal
              </button>
              <button type="button" class="btn btn-danger btn-sm" :disabled="saving" @click="headDecision(false)">
                Return for Revision
              </button>
            </div>
          </div>
        </div>
      </div>
      </div>

      <div v-if="canManageTeamMembersHere" class="workflow-card mb-4">
        <div class="workflow-card__header">
          <i class="bi bi-people-fill me-2"></i> Team Members
        </div>
        <div class="workflow-card__body">
          <div class="team-member-list mb-3">
            <div
              v-for="member in teamMembers"
              :key="member.id"
              class="team-member-row"
            >
              <div class="team-member-row__info">
                <div class="team-member-row__name">
                  {{ member.user?.name || 'Student' }}
                  <AppBadge v-if="member.role === 'leader'" variant="primary" class="ms-2">Leader</AppBadge>
                  <AppBadge v-else variant="secondary" class="ms-2">Member</AppBadge>
                </div>
                <div class="team-member-row__meta">
                  {{ member.user?.sap_id || member.user?.registration_no || member.user?.email || '—' }}
                </div>
              </div>
              <button
                v-if="member.role !== 'leader'"
                type="button"
                class="btn btn-outline-danger btn-sm"
                :disabled="saving"
                @click="openTeamActionModal('remove-member', member)"
              >
                Remove
              </button>
            </div>
          </div>

          <hr />

          <p class="text-muted small mb-2">
            Add a student directly to this team — works in any phase. A student who is currently on another
            project's team in the <strong>same phase</strong> will be transferred here automatically.
          </p>
          <div class="row g-2 align-items-end">
            <div class="col-md-8">
              <StudentMemberPicker
                v-model="directAddUserIds"
                :options="transferableMembers"
                :min="1"
                :max="Math.max(1, (settings.max_members || 5) - teamMembers.length)"
                label="Select Student(s)"
                hint="Includes unassigned students and same-phase members eligible for transfer."
              />
            </div>
            <div class="col-md-4">
              <button
                type="button"
                class="btn btn-primary btn-sm w-100"
                :disabled="saving || !directAddUserIds.length"
                @click="confirmDirectTeamAdd"
              >
                Add Directly
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="canTransferLeadership && nonLeaderMembers.length" class="workflow-card mb-4">
        <div class="workflow-card__header">
          <i class="bi bi-person-badge me-2"></i> Team Leader
        </div>
        <div class="workflow-card__body">
          <p class="text-muted small mb-2">
            Current leader: <strong>{{ project.student?.name || 'Unknown' }}</strong>.
            Transfer leadership to another active team member.
          </p>
          <div class="row g-2 align-items-end">
            <div class="col-md-8">
              <label class="form-label">New Team Leader</label>
              <select v-model="newLeaderId" class="form-select form-select-sm">
                <option value="">Select a member...</option>
                <option v-for="m in nonLeaderMembers" :key="m.id" :value="m.user?.id">
                  {{ m.user?.name || 'Student' }}
                </option>
              </select>
            </div>
            <div class="col-md-4">
              <button
                type="button"
                class="btn btn-primary btn-sm w-100"
                :disabled="saving || !newLeaderId"
                @click="confirmTransferLeadership"
              >
                Make Leader
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="canRequestTransfer || myTransferRequest" class="workflow-card workflow-card--dropdown mb-4">
        <div class="workflow-card__header">
          <i class="bi bi-arrow-left-right me-2"></i> Transfer Request
        </div>
        <div class="workflow-card__body">
          <div v-if="myTransferRequest">
            <AppBadge :variant="transferStatusVariant(myTransferRequest.status)" class="mb-2">
              {{ myTransferRequest.status_label }}
            </AppBadge>

            <div v-if="myTransferRequest.status === 'pending'" class="alert alert-light border py-2 my-2">
              <strong>Reason:</strong> {{ myTransferRequest.reason }}
              <div class="text-muted small mt-1">Waiting for FYP office approval to look for a new group.</div>
            </div>

            <div v-else-if="myTransferRequest.status === 'eligible'">
              <p class="text-muted small mb-3">
                Approved — choose a group to request joining. FYP office and the group leader can act on this too.
              </p>
              <div v-if="transferError" class="alert alert-danger py-2">{{ transferError }}</div>
              <div class="mb-3" style="max-width: 480px">
                <label class="form-label">Target Group</label>
                <select v-model="transferTargetSelection" class="form-select" @focus="loadTransferTargets">
                  <option :value="null" disabled>Select a project…</option>
                  <option v-for="target in transferTargets" :key="target.id" :value="target.id">
                    {{ target.title }} ({{ target.active_member_count }}/{{ target.max_members }})
                  </option>
                </select>
                <small v-if="!transferTargets.length" class="text-muted d-block mt-1">
                  No other projects in your current phase are available right now.
                </small>
              </div>
              <button type="button" class="btn btn-primary btn-sm" :disabled="saving || !transferTargetSelection" @click="submitSelectTransferTarget">
                Request to Join
              </button>
            </div>

            <div v-else-if="myTransferRequest.status === 'pending_leader'" class="alert alert-light border py-2 my-2">
              Requested to join <strong>{{ myTransferRequest.to_project?.title }}</strong>.
              <div class="text-muted small mt-1">Waiting for the group leader's decision.</div>
            </div>

            <button
              v-if="myTransferRequest.viewer_can_cancel"
              type="button"
              class="btn btn-outline-secondary btn-sm mt-2"
              :disabled="saving"
              @click="cancelMyTransferRequest"
            >
              Cancel Request
            </button>
          </div>
          <div v-else>
            <p class="text-muted small mb-3">
              Ask permission to transfer to a different project team in the same phase. FYP office approval is
              required first; once approved, you'll be able to choose a group to request joining.
            </p>
            <div v-if="transferError" class="alert alert-danger py-2">{{ transferError }}</div>
            <div class="mb-3">
              <label class="form-label">Reason</label>
              <textarea v-model="transferForm.reason" class="form-control" rows="3" placeholder="Why do you want to transfer?"></textarea>
            </div>
            <button type="button" class="btn btn-primary btn-sm" :disabled="saving || !transferForm.reason" @click="submitTransferRequest">
              Submit Transfer Request
            </button>
          </div>
        </div>
      </div>

      <div v-if="incomingTransferRequests.length" class="workflow-card workflow-card--dropdown mb-4">
        <div class="workflow-card__header">
          <i class="bi bi-person-plus me-2"></i> Requests to Join Your Group
        </div>
        <div class="workflow-card__body">
          <div v-for="req in incomingTransferRequests" :key="req.id" class="alert alert-light border mb-2">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
              <strong>{{ req.student?.name }}</strong>
              <AppBadge :variant="transferStatusVariant(req.status)">{{ req.status_label }}</AppBadge>
            </div>
            <div v-if="req.reason" class="text-muted small mb-2">{{ req.reason }}</div>
            <div v-if="req.viewer_can_decide" class="d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="decideIncomingTransferRequest(req, true)">
                Accept
              </button>
              <button type="button" class="btn btn-danger btn-sm" :disabled="saving" @click="decideIncomingTransferRequest(req, false)">
                Decline
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="canRequestSupervisorChange || supervisorChangeRequest" class="workflow-card workflow-card--dropdown mb-4">
        <div class="workflow-card__header">
          <i class="bi bi-arrow-left-right me-2"></i> Supervisor Change Request
        </div>
        <div class="workflow-card__body">
          <div v-if="supervisorChangeRequest">
            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <div class="sc-info">
                  <div class="sc-info__label">Current Supervisor</div>
                  <div class="sc-info__value">{{ supervisorChangeRequest.current_supervisor?.name || '—' }}</div>
                  <AppBadge :variant="scStatusVariant(supervisorChangeRequest.current_supervisor_status)">
                    {{ scStatusLabel(supervisorChangeRequest.current_supervisor_status) }}
                  </AppBadge>
                  <div v-if="supervisorChangeRequest.current_supervisor_comments" class="sc-info__comment">
                    {{ supervisorChangeRequest.current_supervisor_comments }}
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="sc-info">
                  <div class="sc-info__label">Requested New Supervisor</div>
                  <div class="sc-info__value">{{ supervisorChangeRequest.new_supervisor?.name || '—' }}</div>
                  <AppBadge :variant="scStatusVariant(supervisorChangeRequest.new_supervisor_status)">
                    {{ scStatusLabel(supervisorChangeRequest.new_supervisor_status) }}
                  </AppBadge>
                  <div v-if="supervisorChangeRequest.new_supervisor_comments" class="sc-info__comment">
                    {{ supervisorChangeRequest.new_supervisor_comments }}
                  </div>
                </div>
              </div>
            </div>

            <div class="alert alert-light border mb-3">
              <strong>Reason:</strong> {{ supervisorChangeRequest.reason }}
            </div>

            <div class="d-flex align-items-center gap-2 mb-3">
              <span class="text-muted small">Overall status:</span>
              <AppBadge :variant="scOverallVariant(supervisorChangeRequest.overall_status)">
                {{ scOverallLabel(supervisorChangeRequest.overall_status) }}
              </AppBadge>
            </div>

            <div v-if="supervisorChangeRequest.authority_comments" class="alert alert-secondary py-2">
              <strong>Authority remarks:</strong> {{ supervisorChangeRequest.authority_comments }}
            </div>

            <div v-if="canRespondAsCurrentSupervisor" class="sc-action mb-3">
              <div class="alert alert-info small mb-2">
                Respond to this change request as the <strong>current supervisor</strong>.
              </div>
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="respondSupervisorChangeAction('current', true)">Accept</button>
                <button type="button" class="btn btn-danger btn-sm" :disabled="saving" @click="openFeedbackModal('sc_current_reject')">Reject</button>
              </div>
            </div>

            <div v-if="canRespondAsNewSupervisor" class="sc-action mb-3">
              <div class="alert alert-info small mb-2">
                Respond to this change request as the <strong>new supervisor</strong>.
              </div>
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="respondSupervisorChangeAction('new', true)">Accept</button>
                <button type="button" class="btn btn-danger btn-sm" :disabled="saving" @click="openFeedbackModal('sc_new_reject')">Reject</button>
              </div>
            </div>

            <div v-if="canManageSupervisorChangeForCurrent" class="sc-action mb-3">
              <div class="alert alert-info small mb-2">
                The current supervisor ({{ supervisorChangeRequest.current_supervisor?.name || 'assigned' }}) has not responded yet.
                As FYP office, you can respond on their behalf.
              </div>
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="respondSupervisorChangeAction('current', true)">Accept on Behalf</button>
                <button type="button" class="btn btn-danger btn-sm" :disabled="saving" @click="openFeedbackModal('sc_current_reject', { which: 'current' })">Reject on Behalf</button>
              </div>
            </div>

            <div v-if="canManageSupervisorChangeForNew" class="sc-action mb-3">
              <div class="alert alert-info small mb-2">
                The new supervisor ({{ supervisorChangeRequest.new_supervisor?.name || 'requested' }}) has not responded yet.
                As FYP office, you can respond on their behalf.
              </div>
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="respondSupervisorChangeAction('new', true)">Accept on Behalf</button>
                <button type="button" class="btn btn-danger btn-sm" :disabled="saving" @click="openFeedbackModal('sc_new_reject', { which: 'new' })">Reject on Behalf</button>
              </div>
            </div>

            <div v-if="canDecideSupervisorChange" class="sc-action mb-3">
              <div class="alert alert-warning small mb-2">
                Both supervisors have responded. As the FYP authority, make the final decision.
              </div>
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="decideSupervisorChangeAction(true)">Approve &amp; Change Supervisor</button>
                <button type="button" class="btn btn-danger btn-sm" :disabled="saving" @click="openFeedbackModal('sc_decline')">Decline</button>
              </div>
            </div>

            <div v-if="isLeader && supervisorChangeRequestActive" class="sc-action">
              <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="saving" @click="cancelSupervisorChangeAction">
                Cancel Request
              </button>
            </div>
          </div>

          <div v-else-if="canRequestSupervisorChange">
            <p class="text-muted small mb-3">
              Request to change your project's supervisor. Both the current and the new supervisor will review your request before the FYP authority makes the final decision.
            </p>
            <div v-if="supervisorChangeError" class="alert alert-danger py-2">{{ supervisorChangeError }}</div>
            <div class="mb-3" style="max-width: 480px">
              <SupervisorPicker
                v-model="supervisorChangeNewId"
                :options="supervisorChangeOptions"
                label="New Supervisor"
                placeholder="Search supervisor by name or email..."
                required
              />
            </div>
            <div class="mb-3">
              <label class="form-label">Reason for Change</label>
              <textarea
                v-model="supervisorChangeReason"
                class="form-control"
                rows="3"
                placeholder="Explain why you want to change your supervisor..."
              ></textarea>
            </div>
            <button type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="submitSupervisorChangeRequest">
              Submit Change Request
            </button>
          </div>
        </div>
      </div>

      <div v-if="project.can_view_evaluator_marks && project.evaluator_reviews?.length" class="workflow-card mb-4">
        <div class="workflow-card__header">
          <i class="bi bi-clipboard-check me-2"></i> Evaluator Feedback
        </div>
        <div class="workflow-card__body">
          <ul class="list-group mb-0">
            <li v-for="review in project.evaluator_reviews" :key="review.id" class="list-group-item">
              <div class="d-flex justify-content-between align-items-start gap-2">
                <strong>{{ review.evaluator?.name || 'Evaluator' }}</strong>
                <AppBadge :variant="reviewVariant(review.decision)">{{ reviewLabel(review.decision) }}</AppBadge>
              </div>
              <div class="text-muted small mt-1">
                {{ phaseLabelFor(review.fyp_phase) }}
                <span v-if="review.marks !== null"> · Marks: {{ review.marks }}/{{ review.max_marks ?? '?' }}</span>
              </div>
              <ul v-if="review.answers?.length" class="list-unstyled small text-muted mt-1 mb-0 ps-2">
                <li v-for="answer in review.answers" :key="answer.id">
                  {{ answer.question_text }}: {{ answer.marks_awarded }}/{{ answer.max_marks }}
                  <span v-if="answer.comment"> — {{ answer.comment }}</span>
                </li>
              </ul>
              <div v-if="review.comments" class="text-muted small mt-2">{{ review.comments }}</div>
            </li>
          </ul>
        </div>
      </div>

      <div v-if="showPhaseDeliverable && project.workflow_logs?.length" class="workflow-card mb-4">
        <div class="workflow-card__header">
          <i class="bi bi-shield-check me-2"></i>
          Activity & Audit Timeline
        </div>
        <div class="workflow-card__body">
          <div class="d-flex justify-content-end mb-2">
            <div class="form-check form-switch">
              <input id="audit-only-toggle" v-model="showAuditOnly" class="form-check-input" type="checkbox" />
              <label class="form-check-label" for="audit-only-toggle">Sensitive actions only</label>
            </div>
          </div>
          <WorkflowTimeline :items="project.workflow_logs" :audit-only="showAuditOnly" />
        </div>
      </div>

      <div v-if="project" class="workflow-card mb-4">
        <div class="workflow-card__header">
          <i class="bi bi-chat-left-text me-2"></i> Project Queries
        </div>
        <div class="workflow-card__body">
          <ProjectQueryPanel :project-id="project.id" :project-title="project.title" />
        </div>
      </div>
    </template>

    <div v-if="feedbackModal.show" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.45)">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
          <div class="modal-header"><h5 class="modal-title">Comments</h5><button type="button" class="btn-close" @click="closeFeedbackModal"></button></div>
          <div class="modal-body">
            <textarea v-model="feedbackModal.comments" class="form-control" rows="4" placeholder="Enter comments"></textarea>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" @click="closeFeedbackModal">Cancel</button>
            <button type="button" class="btn btn-primary" :disabled="saving" @click="confirmFeedback">Confirm</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="teamActionModal.show" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.45)">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
          <div class="modal-header">
            <h5 class="modal-title">{{ teamActionModal.title }}</h5>
            <button type="button" class="btn-close" @click="closeTeamActionModal"></button>
          </div>
          <div class="modal-body">
            <p class="mb-3">{{ teamActionModal.message }}</p>
            <label class="form-label">Reason (optional)</label>
            <textarea v-model="teamActionModal.comments" class="form-control" rows="4" placeholder="Enter reason"></textarea>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" @click="closeTeamActionModal">Cancel</button>
            <button
              type="button"
              :class="teamActionModal.type === 'return-to-team-formation' ? 'btn btn-warning' : 'btn btn-danger'"
              :disabled="saving"
              @click="confirmTeamAction"
            >
              Confirm
            </button>
          </div>
        </div>
      </div>
    </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppCard from '@/components/ui/AppCard.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import StudentMemberPicker from '@/components/proposal/StudentMemberPicker.vue'
import SupervisorPicker from '@/components/proposal/SupervisorPicker.vue'
import EvaluatorPicker from '@/components/proposal/EvaluatorPicker.vue'
import EvaluatorAnswerForm from '@/components/proposal/EvaluatorAnswerForm.vue'
import ProposalPdfPreview from '@/components/proposal/ProposalPdfPreview.vue'
import PhaseDeliverablePanel from '@/components/proposal/PhaseDeliverablePanel.vue'
import ProjectQueryPanel from '@/components/queries/ProjectQueryPanel.vue'
import ProposalSessionCountdown from '@/components/proposal/ProposalSessionCountdown.vue'
import WorkflowTimeline from '@/components/ui/WorkflowTimeline.vue'
import WorkflowStatusBadge from '@/components/ui/WorkflowStatusBadge.vue'
import DeadlineBadge from '@/components/ui/DeadlineBadge.vue'
import { useAuthStore } from '@/stores/auth'
import { confirmDialog } from '@/composables/useConfirm'
import { toast } from '@/composables/useToast'
import { useWorkflowNotifications } from '@/composables/useWorkflowNotifications'
import {
  fetchProjects,
  fetchProject,
  fetchSupervisors,
  deleteProject,
  removeProjectMember,
  transferProjectLeadership,
  phaseSupervisorRespond,
  phaseSupervisorRevisionReview,
  phaseKeepEvaluators,
  phaseAssignEvaluators,
  phaseReevaluate,
  phaseReturnFromCommittee,
  phaseEvaluatorReview,
  phaseResubmitDeliverable,
  phaseCommitteeFinalReview,
  phaseCommitteeHeadApprove,
  phaseAllowRepeatCarryForward,
  phaseRequireRepeatResubmission,
  downloadPhaseCertificate,
} from '@/api/projects'
import { formatApiError } from '@/utils/apiErrors'
import { storageUrl } from '@/utils/baseUrl'
import {
  fetchProposalSettings,
  fetchEligibleMembers,
  fetchMyProject,
  submitProposal,
  inviteProjectMembers,
  supervisorRespond,
  supervisorRequestRevision,
  supervisorRevisionReview,
  changeSupervisor,
  submitSupervisorChangeRequest as submitSupervisorChangeRequestApi,
  respondSupervisorChangeRequest,
  decideSupervisorChangeRequest,
  cancelSupervisorChangeRequest,
  assignEvaluators,
  submitEvaluatorReview,
  resubmitProposal,
  committeeFinalReview,
  committeeHeadApprove,
  returnToTeamFormation,
  cancelProjectInvitation,
  acceptProjectInvitationOnBehalf,
  addTeamMembersDirectly,
  fetchEvaluators,
} from '@/api/proposals'
import { fetchProposalSessionContext } from '@/api/proposalSessions'
import { fetchQuestionBank } from '@/api/questionBank'
import {
  fetchTransferableMembers,
  fetchTransferTargets,
  createTransferRequest,
  selectTransferTarget,
  decideTransferRequest,
  cancelTransferRequest,
} from '@/api/transfers'

const props = defineProps({
  projectId: { type: [String, Number], default: null },
  canRegister: { type: Boolean, default: false },
})

const emit = defineEmits(['loaded'])

const authStore = useAuthStore()
const { refreshNotifications } = useWorkflowNotifications()
const showAuditOnly = ref(false)
const router = useRouter()
const loading = ref(true)
const saving = ref(false)
const downloadingCertificatePhase = ref(null)
const formError = ref('')
const project = ref(null)
const canRegisterProposal = ref(false)
const sessionContext = ref(null)
const registrationStatus = ref({
  is_proposal_enrolled: false,
  in_group: false,
  has_pending_invitations: false,
  block_reason: null,
})
const settings = ref({ min_members: 3, max_members: 5, min_evaluators: 2, max_evaluators: 3, workflow_stages: [], phase_workflow_stages: [] })
const supervisors = ref([])
const eligibleMembers = ref([])
const reinviteMemberIds = ref([])
const transferableMembers = ref([])
const directAddUserIds = ref([])
const transferTargets = ref([])
const transferForm = reactive({ reason: '' })
const transferTargetSelection = ref(null)
const transferError = ref('')
const evaluators = ref([])
const newSupervisorId = ref(null)
const supervisorChangeNewId = ref(null)
const supervisorChangeReason = ref('')
const supervisorChangeError = ref('')
const selectedEvaluators = ref([])
const evaluatorComments = ref('')
const evaluationQuestions = ref([])
// These must be refs: EvaluatorAnswerForm emits a fresh object on every edit, and
// `v-model` on a `const reactive({})` rebinds a plain non-reactive variable, so the form
// kept spreading a stale object and dropped every answer except the last one edited.
const evaluatorAnswers = ref({})
const officeEvaluatorId = ref(null)
const officeEvaluatorComments = ref('')
const officeEvaluatorAnswers = ref({})
const committeeComments = ref('')
const committeeCommentError = ref('')
const headComments = ref('')
const headCommentError = ref('')
const evaluatorResubmitMode = ref('negative_only')
const proposalFile = ref(null)
const proposalFileName = ref('')
const proposalFileInput = ref(null)
const revisionFile = ref(null)
const revisionFileName = ref('')
const revisionFileInput = ref(null)

const form = reactive({
  title: '',
  description: '',
  area_of_specialization: '',
  supervisor_id: null,
  invitee_ids: [],
})

const feedbackModal = reactive({ show: false, type: null, comments: '', meta: null })
const teamActionModal = reactive({
  show: false,
  type: null,
  member: null,
  title: '',
  message: '',
  comments: '',
})

const isProposalEnrolled = computed(() => !!registrationStatus.value.is_proposal_enrolled)

const registrationBlockedMessage = computed(() => {
  const reason = registrationStatus.value.block_reason

  if (reason === 'in_group') {
    return 'You are already part of a proposal group. Open your project workflow below or contact the FYP office if this looks incorrect.'
  }

  if (reason === 'already_leader') {
    return 'You already have a registered proposal group. Refresh the page or contact the FYP office if you cannot see it.'
  }

  if (reason === 'submissions_closed') {
    return 'Proposal submissions are closed for your session. Contact the FYP office if you need access.'
  }

  if (reason === 'initial_deadline_passed') {
    return 'The initial draft submission deadline has passed. New proposal registrations are no longer accepted.'
  }

  if (reason === 'fully_locked') {
    return 'All proposal submissions are locked for your session.'
  }

  if (reason === 'session_mismatch') {
    return 'Your student session code does not match the active proposal session. Contact the FYP office.'
  }

  if (reason === 'no_active_session') {
    return 'No active proposal session is available for your program yet.'
  }

  if (registrationStatus.value.has_pending_invitations) {
    return 'Respond to your pending group invitations above, or submit your own proposal if you have not joined any group yet.'
  }

  return 'You cannot register a proposal right now. Please contact the FYP office for assistance.'
})

const workflowStages = computed(() => settings.value.workflow_stages || [])
const phaseWorkflowStages = computed(() => settings.value.phase_workflow_stages || [])

const activeDeliverablePhase = computed(() => project.value?.current_phase)

const currentPhaseEvaluatorReviews = computed(() => {
  const phase = activeDeliverablePhase.value
  if (!phase) return []

  return (project.value?.evaluator_reviews || []).filter(
    (review) => (review.fyp_phase || 'proposal') === phase
  )
})

const canSupervisorReviewDeliverable = computed(() => !!project.value?.viewer_can_supervisor_review_deliverable)

const isLeader = computed(() => {
  if (project.value?.viewer_is_project_leader != null) {
    return !!project.value.viewer_is_project_leader
  }

  return Number(project.value?.student?.id) === Number(authStore.user?.id)
})

const showPhaseDeliverable = computed(() => ['phase_1', 'phase_2'].includes(project.value?.current_phase))

const approvedCertificates = computed(() => project.value?.approved_certificates || [])

const reloadAfterPhaseUpdate = async () => {
  await loadProject()
  syncSelectedEvaluators()
}

const canStudentResubmit = computed(() => !!project.value?.viewer_can_resubmit_proposal)

const latestRevisionFeedback = computed(() => {
  if (project.value?.supervisor_revision_feedback) {
    return project.value.supervisor_revision_feedback
  }

  return (project.value?.workflow_logs || []).find(
    (log) =>
      ['evaluator_review', 'committee_final', 'committee_head_approval', 'supervisor_review'].includes(log.stage)
      && /rejected|revision|returned proposal for revision|returned revised proposal/i.test(`${log.action || ''} ${log.comments || ''}`)
      && log.comments
  )?.comments || null
})

const pendingInvites = computed(() => project.value?.invitations?.filter((i) => i.status === 'pending') || [])

const rejectedInvites = computed(() => project.value?.invitations?.filter((i) => i.status === 'rejected') || [])

const canInviteMembers = computed(() => !!project.value?.viewer_can_invite_members)

const canAdminManageTeam = computed(() => !!project.value?.can_admin_manage_team)

const canDirectAddMembers = computed(() => !!project.value?.can_direct_add_members)

const showTeamFormationTools = computed(() => canInviteMembers.value || canAdminManageTeam.value)

const teamFormationHint = computed(() => {
  if (canDirectAddMembers.value) {
    return 'Send invitations for students to accept, or add them directly to the team without waiting for a response.'
  }

  return 'Invite students from your department who have not joined any group. Rejected students can be invited again.'
})

const teamInvite = computed(() => project.value?.team_invite || {
  member_count: 0,
  pending_invites: 0,
  rejected_invites: 0,
  open_slots: 0,
  members_needed: 0,
})

const canEditPhaseDeliverable = computed(() => {
  const phase = project.value?.phases?.find((row) => row.phase === project.value?.current_phase)
  return !!phase?.is_editable && !!project.value?.viewer_is_project_leader && !!sessionContext.value?.can_edit_phase_deliverable
})

const currentPhaseRow = computed(() =>
  project.value?.phases?.find((row) => row.phase === project.value?.current_phase) || null
)
const canReevaluateCurrentPhase = computed(() => !!currentPhaseRow.value?.can_reevaluate)

const reevaluateOpen = ref(false)
const reevaluateError = ref('')
const reevaluateForm = reactive({
  keepSame: true,
  evaluatorIds: [],
  deadline: '',
  notes: '',
})

const defaultReevaluateDeadline = () => {
  const date = new Date()
  date.setDate(date.getDate() + 3)
  return date.toISOString().slice(0, 10)
}

const openReevaluatePanel = () => {
  reevaluateForm.keepSame = true
  reevaluateForm.evaluatorIds = []
  reevaluateForm.deadline = defaultReevaluateDeadline()
  reevaluateForm.notes = ''
  reevaluateError.value = ''
  reevaluateOpen.value = true
}

const submitReevaluate = async () => {
  reevaluateError.value = ''
  saving.value = true
  try {
    await phaseReevaluate(project.value.id, project.value.current_phase, {
      keep_same_evaluators: reevaluateForm.keepSame,
      evaluator_ids: reevaluateForm.keepSame ? undefined : reevaluateForm.evaluatorIds,
      extended_deadline: reevaluateForm.deadline,
      notes: reevaluateForm.notes || undefined,
    })
    toast.success('Reevaluation started.')
    reevaluateOpen.value = false
    await loadProject()
  } catch (err) {
    reevaluateError.value = formatApiError(err, 'Failed to start reevaluation.')
  } finally {
    saving.value = false
  }
}

const activeWorkflowStages = computed(() =>
  showPhaseDeliverable.value ? phaseWorkflowStages.value : workflowStages.value
)

const currentDisplayStage = computed(() => project.value?.display_stage || 'proposal_submitted')

const stepClass = (stageKey) => {
  const stages = activeWorkflowStages.value
  const currentOrder = stages.find((s) => s.key === currentDisplayStage.value)?.order || 1
  const stageOrder = stages.find((s) => s.key === stageKey)?.order || 0
  return {
    completed: stageOrder < currentOrder,
    active: stageKey === currentDisplayStage.value,
  }
}

const assignedEvaluatorNames = computed(() =>
  (project.value?.evaluators || [])
    .map((item) => item.evaluator?.name)
    .filter(Boolean)
    .join(', ') || 'Not assigned'
)

const evaluatorCountLabel = computed(() => {
  const count = project.value?.evaluator_count ?? (project.value?.evaluators || []).length
  if (!count) return 'Not assigned'
  return `${count} evaluator${count === 1 ? '' : 's'} assigned`
})

const hasAssignedEvaluators = computed(() => (project.value?.evaluators || []).length > 0)

const evaluatorAssignmentStages = ['committee_review', 'evaluator_review', 'revision_required']

const proposalPhase = computed(() => project.value?.phases?.find((p) => p.phase === 'proposal') || null)
const proposalDocumentUrl = computed(() => {
  const phase = proposalPhase.value
  if (!phase) return null
  return storageUrl(phase.attachment || phase.attachment_url)
})

const isAssignedEvaluatorView = computed(() =>
  authStore.hasRole('evaluator') && !!project.value?.viewer_is_assigned_evaluator
)

// A supervisor invitation that hasn't been accepted yet isn't a confirmed
// assignment — showing the invited supervisor's name to students as if it
// were settled is misleading, especially since it can still be rejected.
const supervisorDisplayValue = computed(() => {
  if (!project.value?.supervisor) return 'Not assigned'

  if (authStore.isStudent && project.value?.supervisor_status !== 'accepted') {
    return 'Pending confirmation'
  }

  return project.value.supervisor.name
})

const detailRows = computed(() => {
  const leader = project.value?.student
  const rows = [
    { label: 'Student Name', value: leader?.name || '-' },
    { label: 'SAP ID', value: leader?.sap_id || leader?.registration_no || '-' },
    { label: 'Program', value: leader?.program || '-' },
    { label: 'Department', value: leader?.department || '-' },
    { label: 'Session', value: leader?.session || '-' },
    { label: 'Proposal Title', value: project.value?.title || '-' },
    { label: 'Area of Specialization', value: project.value?.area_of_specialization || '-' },
  ]

  if (proposalDocumentUrl.value && !isAssignedEvaluatorView.value) {
    rows.push({
      label: 'Proposal Document',
      value: proposalPhase.value?.content || 'View PDF',
      link: proposalDocumentUrl.value,
    })
  }

  rows.push(
    { label: 'Supervisor', value: supervisorDisplayValue.value },
    { label: 'Team Members', value: project.value?.members?.map((m) => m.user?.name).filter(Boolean).join(', ') || '-' },
    ...(authStore.canManageEvaluators
      ? [{ label: 'Evaluators', value: assignedEvaluatorNames.value }]
      : [{ label: 'Evaluators', value: evaluatorCountLabel.value }]),
    { label: 'Submission Date', value: project.value?.proposal_submitted_at || '-' },
    { label: 'Status', value: project.value?.workflow_stage_label || '-', badge: true, variant: statusVariant(project.value?.workflow_stage) },
  )

  return rows
})

const statusVariant = (stage) => ({
  approved: 'success',
  revision_required: 'danger',
  supervisor_revision_pending: 'info',
  supervisor_rejected: 'warning',
}[stage] || 'primary')

const reviewLabel = (decision) => ({
  accepted: 'Accepted',
  rejected: 'Rejected',
  revision_required: 'Revision Required',
}[decision] || '—')

const reviewVariant = (decision) => ({
  accepted: 'success',
  rejected: 'danger',
  revision_required: 'warning',
}[decision] || 'secondary')

const phaseLabelFor = (phase) => ({
  proposal: 'Proposal',
  phase_1: 'Phase-1',
  phase_2: 'Phase-2',
}[phase] || phase)

const canSupervisorAct = computed(() =>
  authStore.roles.includes('supervisor') &&
  project.value?.workflow_stage === 'supervisor_pending' &&
  project.value?.supervisor?.id === authStore.user?.id
)

const canSupervisorReviewRevision = computed(() => !!project.value?.viewer_can_supervisor_review_revision)

const canManageSupervisorRevisionReview = computed(() => !!project.value?.can_manage_supervisor_revision_review)

const canManageSupervisorInvitation = computed(() => !!project.value?.can_manage_supervisor_invitation)

const canAssignEvaluators = computed(() => !!project.value?.can_assign_evaluators)

const canEvaluatorAct = computed(() => {
  if (project.value?.workflow_stage !== 'evaluator_review') return false
  if (!authStore.roles.includes('evaluator')) return false
  return project.value?.viewer_is_assigned_evaluator
    && !project.value?.viewer_has_submitted_review
})

const officePendingEvaluators = computed(() => {
  const pending = project.value?.pending_evaluators || []
  if (!canEvaluatorAct.value) return pending

  return pending.filter((evaluator) => Number(evaluator.id) !== Number(authStore.user?.id))
})

const canManageEvaluatorReviews = computed(() =>
  !!project.value?.can_manage_evaluator_reviews && officePendingEvaluators.value.length > 0
)

const availableEvaluatorsForProject = computed(() => {
  const supervisorId = Number(project.value?.supervisor?.id)
  if (!supervisorId) return evaluators.value

  return evaluators.value.filter((evaluator) => Number(evaluator.id) !== supervisorId)
})

const availableSupervisorsForProject = computed(() => {
  const evaluatorIds = new Set(
    (project.value?.evaluators || [])
      .map((item) => Number(item.evaluator?.id))
      .filter(Boolean)
  )

  return supervisors.value.filter((supervisor) => !evaluatorIds.has(Number(supervisor.id)))
})

const availableSupervisorsForChange = computed(() => {
  const currentSupervisorId = Number(project.value?.supervisor?.id)
  return availableSupervisorsForProject.value.filter(
    (supervisor) => Number(supervisor.id) !== currentSupervisorId
  )
})

const supervisorChangeOptions = computed(() => availableSupervisorsForChange.value)

const supervisorChangeRequest = computed(() => project.value?.supervisor_change_request || null)

const supervisorChangeRequestActive = computed(() =>
  ['pending', 'awaiting_authority'].includes(supervisorChangeRequest.value?.overall_status)
)

const canRequestSupervisorChange = computed(() => !!project.value?.can_request_supervisor_change)

const canRespondAsCurrentSupervisor = computed(() => !!project.value?.viewer_can_respond_as_current_supervisor)

const canRespondAsNewSupervisor = computed(() => !!project.value?.viewer_can_respond_as_new_supervisor)

const canDecideSupervisorChange = computed(() => !!project.value?.viewer_can_decide_supervisor_change)

const canManageSupervisorChange = computed(() => !!project.value?.viewer_can_manage_supervisor_change)

const canManageSupervisorChangeForCurrent = computed(() => {
  const req = supervisorChangeRequest.value
  return canManageSupervisorChange.value
    && req?.overall_status === 'pending'
    && req?.current_supervisor_status === 'pending'
    && Number(req?.current_supervisor?.id) !== Number(authStore.user?.id)
})

const canManageSupervisorChangeForNew = computed(() => {
  const req = supervisorChangeRequest.value
  return canManageSupervisorChange.value
    && req?.overall_status === 'pending'
    && req?.new_supervisor_status === 'pending'
    && Number(req?.new_supervisor?.id) !== Number(authStore.user?.id)
})

const canCancelSupervisorChange = computed(() => {
  const req = supervisorChangeRequest.value
  if (!req || !['pending', 'awaiting_authority'].includes(req.overall_status)) return false
  if (isLeader.value) return true
  return authStore.roles.some((role) => ['admin', 'fyp-committee-head'].includes(role))
})

const scStatusLabel = (status) => ({
  pending: 'Pending',
  accepted: 'Accepted',
  rejected: 'Rejected',
}[status] || status || '—')

const scStatusVariant = (status) => ({
  pending: 'warning',
  accepted: 'success',
  rejected: 'danger',
}[status] || 'secondary')

const scOverallLabel = (status) => ({
  pending: 'Awaiting supervisor responses',
  awaiting_authority: 'Awaiting authority decision',
  approved: 'Approved',
  declined: 'Declined',
  cancelled: 'Cancelled',
}[status] || status || '—')

const scOverallVariant = (status) => ({
  pending: 'warning',
  awaiting_authority: 'info',
  approved: 'success',
  declined: 'danger',
  cancelled: 'secondary',
}[status] || 'secondary')

const canCommitteeFinal = computed(() =>
  project.value?.workflow_stage === 'committee_final' &&
  authStore.roles.some((r) => ['admin', 'fyp-committee-member', 'fyp-committee-head'].includes(r))
)

const canCommitteeHeadAct = computed(() =>
  project.value?.workflow_stage === 'committee_head_approval' &&
  authStore.roles.includes('fyp-committee-head')
)

const canDecidePhaseRepeat = computed(() => !!project.value?.can_decide_phase_repeat)

const committeeHeadWaitingBanner = computed(() =>
  project.value?.workflow_stage === 'committee_final' &&
  authStore.roles.includes('fyp-committee-head') &&
  !authStore.roles.some((role) => ['admin', 'fyp-committee-member'].includes(role))
)

const canManageProjectTeam = computed(() => authStore.canManageProjectTeam)

const canManageTeamMembersHere = computed(() => !!project.value?.can_manage_team_members)

const canTransferLeadership = computed(() => !!project.value?.can_transfer_leadership)

const newLeaderId = ref('')

const nonLeaderMembers = computed(() =>
  (project.value?.members || []).filter((member) => member.status === 'active' && member.role !== 'leader')
)

const canRequestTransfer = computed(() => !!project.value?.can_request_transfer)

const myTransferRequest = computed(() => project.value?.viewer_transfer_request || null)

const incomingTransferRequests = computed(() => project.value?.incoming_transfer_requests || [])

const canReturnToTeamFormation = computed(() => !!project.value?.can_return_to_team_formation)

const canCancelInvitations = computed(() => !!project.value?.can_cancel_invitations)

const canAcceptInvitationsOnBehalf = computed(() => !!project.value?.can_accept_invitations_on_behalf)

const canViewInviteeContact = computed(() => canManageProjectTeam.value || canCancelInvitations.value)

const teamMembers = computed(() => project.value?.members || [])

const teamStatusMembers = computed(() => {
  const members = (project.value?.members || [])
    .filter((member) => member.status === 'active' && member.user)
    .map((member) => ({
      id: member.user.id,
      name: member.user.name,
      sap_id: member.user.sap_id || member.user.registration_no || '',
      role: member.role,
      roleLabel: member.role === 'leader' ? 'Leader' : 'Member',
    }))

  if (members.length) {
    return members
  }

  if (project.value?.student) {
    return [{
      id: project.value.student.id,
      name: project.value.student.name,
      sap_id: project.value.student.sap_id || project.value.student.registration_no || '',
      role: 'leader',
      roleLabel: 'Leader',
    }]
  }

  return []
})

const syncSelectedEvaluators = () => {
  const allowedIds = new Set(
    availableEvaluatorsForProject.value.map((evaluator) => Number(evaluator.id))
  )

  selectedEvaluators.value = (project.value?.evaluators || [])
    .map((item) => item.evaluator?.id)
    .filter((id) => id && allowedIds.has(Number(id)))
}

const syncOfficeEvaluatorSelection = () => {
  const pendingIds = officePendingEvaluators.value.map((evaluator) => Number(evaluator.id))
  if (!pendingIds.length) {
    officeEvaluatorId.value = null
    return
  }

  if (!pendingIds.includes(Number(officeEvaluatorId.value))) {
    officeEvaluatorId.value = pendingIds[0]
  }
}

const loadStudentSessionContext = async () => {
  if (!authStore.isStudent) {
    sessionContext.value = null
    return
  }

  try {
    const res = await fetchProposalSessionContext()
    sessionContext.value = res.data || null
  } catch {
    sessionContext.value = null
  }
}

const refreshSessionContext = async () => {
  await loadStudentSessionContext()
  if (project.value) {
    await loadProject()
  }
}

const loadProject = async () => {
  if (props.projectId) {
    const res = await fetchProject(props.projectId)
    project.value = res.data
    canRegisterProposal.value = false
    await loadStudentSessionContext()
  } else if (authStore.isStudent) {
    const res = await fetchMyProject()
    project.value = res.data.project || null
    canRegisterProposal.value = !!res.data.can_register_proposal
    registrationStatus.value = {
      is_proposal_enrolled: !!res.data.registration?.is_proposal_enrolled,
      in_group: !!res.data.registration?.in_group,
      has_pending_invitations: !!res.data.registration?.has_pending_invitations,
      block_reason: res.data.registration?.block_reason || null,
    }
    sessionContext.value = res.data.registration?.session || null
    if (!sessionContext.value?.session) {
      await loadStudentSessionContext()
    }
  } else {
    const list = await fetchProjects({ per_page: 1 })
    project.value = list.data.projects?.[0] || null
    canRegisterProposal.value = false
  }
  revisionFile.value = null
  revisionFileName.value = ''
  reinviteMemberIds.value = []
  evaluatorResubmitMode.value = project.value?.evaluator_resubmit_mode || 'negative_only'
  syncSelectedEvaluators()
  syncOfficeEvaluatorSelection()
  if (project.value?.viewer_can_invite_members || project.value?.can_admin_manage_team) {
    await loadEligibleMembersForProject()
  }
  if (project.value?.can_manage_team_members) {
    await loadTransferableMembers()
  }
  await loadEvaluationQuestions()

  emit('loaded', sessionContext.value)
  await refreshNotifications()
}

const reviewPhaseForQuestions = computed(() => {
  const phase = project.value?.current_phase
  return phase === 'phase_1' || phase === 'phase_2' ? phase : 'proposal'
})

const loadEvaluationQuestions = async () => {
  const workflowStage = showPhaseDeliverable.value
    ? project.value?.phases?.find((row) => row.phase === project.value?.current_phase)?.workflow_stage
    : project.value?.workflow_stage

  if (workflowStage !== 'evaluator_review') {
    evaluationQuestions.value = []
    return
  }

  try {
    const res = await fetchQuestionBank({ phase: reviewPhaseForQuestions.value })
    evaluationQuestions.value = res.data || []
  } catch {
    evaluationQuestions.value = []
  }

  evaluatorAnswers.value = {}
  officeEvaluatorAnswers.value = {}
}

const loadEligibleMembersForProject = async () => {
  if (!project.value?.id) return
  const res = await fetchEligibleMembers({ project_id: project.value.id })
  eligibleMembers.value = res.data
}

const onProposalFileChange = (event) => {
  const file = event.target.files?.[0] || null
  if (file && file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
    formError.value = 'Only PDF files are allowed.'
    proposalFile.value = null
    proposalFileName.value = ''
    if (proposalFileInput.value) proposalFileInput.value.value = ''
    return
  }
  formError.value = ''
  proposalFile.value = file
  proposalFileName.value = file?.name || ''
}

const onRevisionFileChange = (event) => {
  const file = event.target.files?.[0] || null
  if (file && file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
    toast.warning('Only PDF files are allowed.')
    revisionFile.value = null
    revisionFileName.value = ''
    if (revisionFileInput.value) revisionFileInput.value.value = ''
    return
  }
  revisionFile.value = file
  revisionFileName.value = file?.name || ''
}

const loadFormData = async () => {
  const needsMemberPicker =
    props.canRegister && authStore.isStudent && !project.value && canRegisterProposal.value

  const projectParams = project.value?.id ? { project_id: project.value.id } : {}
  const requests = [fetchProposalSettings(), fetchSupervisors(projectParams)]

  if (needsMemberPicker) {
    requests.push(fetchEligibleMembers())
  }

  if (authStore.canAssignEvaluators) {
    requests.push(fetchEvaluators(projectParams))
  }

  const results = await Promise.all(requests)
  settings.value = results[0].data
  supervisors.value = results[1].data

  let index = 2
  if (needsMemberPicker) {
    eligibleMembers.value = results[index].data
    index += 1
  }
  if (authStore.canAssignEvaluators) {
    evaluators.value = results[index].data
  }
}

onMounted(async () => {
  try {
    await loadProject()
    await loadFormData()
  } finally {
    loading.value = false
  }
})

defineExpose({ loadProject, loadFormData, sessionContext })

const sendInvitations = async () => {
  if (!reinviteMemberIds.value.length) return

  saving.value = true
  try {
    const res = await inviteProjectMembers(project.value.id, reinviteMemberIds.value.map(Number))
    project.value = res.data
    reinviteMemberIds.value = []
    await loadEligibleMembersForProject()
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to send invitations.'))
  } finally {
    saving.value = false
  }
}

const confirmCancelInvitation = async (invitation) => {
  const inviteeName = invitation.invitee?.name || 'this student'
  const comments = await confirmDialog.confirm({
    title: 'Cancel Invitation',
    message: `Cancel the pending invitation for ${inviteeName}?`,
    confirmText: 'Cancel Invitation',
    variant: 'danger',
    prompt: true,
    promptLabel: 'Optional reason for cancellation',
  })

  if (!comments) return

  saving.value = true
  try {
    const res = await cancelProjectInvitation(project.value.id, invitation.id, {
      comments: comments === true ? null : comments,
    })
    project.value = res.data
    toast.success('Invitation cancelled.')
    if (showTeamFormationTools.value) {
      await loadEligibleMembersForProject()
    }
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to cancel invitation.'))
  } finally {
    saving.value = false
  }
}

const confirmAcceptInvitationOnBehalf = async (invitation) => {
  const inviteeName = invitation.invitee?.name || 'this student'
  const inviteeEmail = invitation.invitee?.email ? ` (${invitation.invitee.email})` : ''
  const comments = await confirmDialog.confirm({
    title: 'Accept On Behalf',
    message: `Accept the group invitation on behalf of ${inviteeName}${inviteeEmail}?`,
    confirmText: 'Accept On Behalf',
    prompt: true,
    promptLabel: 'Optional note for the student and group leader',
  })

  if (comments === false) return

  saving.value = true
  try {
    const res = await acceptProjectInvitationOnBehalf(project.value.id, invitation.id, {
      comments: comments === true ? null : comments,
    })
    project.value = res.data
    toast.success('Invitation accepted on behalf of student.')
    if (showTeamFormationTools.value) {
      await loadEligibleMembersForProject()
    }
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to accept invitation on behalf of the student.'))
  } finally {
    saving.value = false
  }
}

const confirmDirectAddMembers = async () => {
  if (!reinviteMemberIds.value.length) return

  const comments = await confirmDialog.confirm({
    title: 'Add Team Members',
    message: `Add ${reinviteMemberIds.value.length} student(s) directly to this team? They will not need to accept an invitation.`,
    confirmText: 'Add Members',
    prompt: true,
    promptLabel: 'Optional note for the students',
  })

  if (comments === false) return

  saving.value = true
  try {
    const res = await addTeamMembersDirectly(project.value.id, {
      user_ids: reinviteMemberIds.value.map(Number),
      comments: comments === true ? null : comments,
    })
    project.value = res.data
    reinviteMemberIds.value = []
    toast.success('Team members added.')
    if (showTeamFormationTools.value) {
      await loadEligibleMembersForProject()
    }
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to add team members.'))
  } finally {
    saving.value = false
  }
}

const loadTransferableMembers = async () => {
  if (!project.value?.id) return
  try {
    const res = await fetchTransferableMembers(project.value.id)
    transferableMembers.value = res.data || []
  } catch {
    transferableMembers.value = []
  }
}

const confirmDirectTeamAdd = async () => {
  if (!directAddUserIds.value.length) return

  const comments = await confirmDialog.confirm({
    title: 'Add Student Directly',
    message: `Add ${directAddUserIds.value.length} student(s) directly to this team? A student currently on another same-phase project will be transferred here.`,
    confirmText: 'Add',
    prompt: true,
    promptLabel: 'Optional note for the student(s)',
  })

  if (comments === false) return

  saving.value = true
  try {
    const res = await addTeamMembersDirectly(project.value.id, {
      user_ids: directAddUserIds.value.map(Number),
      comments: comments === true ? null : comments,
    })
    project.value = res.data
    directAddUserIds.value = []
    toast.success('Student(s) added to the team.')
    await loadTransferableMembers()
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to add student(s) directly.'))
  } finally {
    saving.value = false
  }
}

const confirmTransferLeadership = async () => {
  if (!newLeaderId.value) return

  const newLeader = nonLeaderMembers.value.find((m) => String(m.user?.id) === String(newLeaderId.value))

  const comments = await confirmDialog.confirm({
    title: 'Change Team Leader',
    message: `Make ${newLeader?.user?.name || 'this student'} the new team leader?`,
    confirmText: 'Make Leader',
    prompt: true,
    promptLabel: 'Optional note',
  })

  if (comments === false) return

  saving.value = true
  try {
    const res = await transferProjectLeadership(project.value.id, {
      new_leader_id: Number(newLeaderId.value),
      comments: comments === true ? null : comments,
    })
    project.value = res.data
    newLeaderId.value = ''
    toast.success('Team leader updated.')
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to update team leader.'))
  } finally {
    saving.value = false
  }
}

const loadTransferTargets = async () => {
  try {
    const res = await fetchTransferTargets()
    transferTargets.value = res.data || []
  } catch {
    transferTargets.value = []
  }
}

const transferStatusVariant = (status) => ({
  pending: 'warning',
  eligible: 'info',
  pending_leader: 'warning',
  approved: 'success',
  rejected: 'danger',
  cancelled: 'secondary',
}[status] || 'secondary')

const submitTransferRequest = async () => {
  if (!transferForm.reason) return

  transferError.value = ''
  saving.value = true
  try {
    await createTransferRequest({ reason: transferForm.reason })
    transferForm.reason = ''
    toast.success('Transfer request submitted.')
    await loadProject()
  } catch (err) {
    transferError.value = formatApiError(err, 'Failed to submit transfer request.')
  } finally {
    saving.value = false
  }
}

const submitSelectTransferTarget = async () => {
  if (!myTransferRequest.value || !transferTargetSelection.value) return

  transferError.value = ''
  saving.value = true
  try {
    await selectTransferTarget(myTransferRequest.value.id, {
      to_project_id: transferTargetSelection.value,
    })
    transferTargetSelection.value = null
    transferTargets.value = []
    toast.success('Join request sent to the group leader.')
    await loadProject()
  } catch (err) {
    transferError.value = formatApiError(err, 'Failed to send join request.')
  } finally {
    saving.value = false
  }
}

const decideIncomingTransferRequest = async (req, approve) => {
  const comments = await confirmDialog.confirm({
    title: approve ? 'Accept Join Request' : 'Decline Join Request',
    message: approve
      ? `Accept ${req.student?.name} into your group?`
      : `Decline ${req.student?.name}'s request to join your group?`,
    confirmText: approve ? 'Accept' : 'Decline',
    variant: approve ? 'primary' : 'danger',
    prompt: true,
    promptLabel: approve ? 'Optional comments' : 'Reason (optional)',
  })

  if (comments === false) return

  saving.value = true
  try {
    await decideTransferRequest(req.id, {
      approve,
      comments: comments === true ? null : comments,
    })
    toast.success('Decision recorded.')
    await loadProject()
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to record decision.'))
  } finally {
    saving.value = false
  }
}

const cancelMyTransferRequest = async () => {
  if (!myTransferRequest.value) return

  const confirmed = await confirmDialog.confirm({
    title: 'Cancel Transfer Request',
    message: 'Cancel your pending transfer request?',
    confirmText: 'Cancel Request',
    variant: 'danger',
  })

  if (!confirmed) return

  saving.value = true
  try {
    await cancelTransferRequest(myTransferRequest.value.id)
    toast.success('Transfer request cancelled.')
    await loadProject()
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to cancel transfer request.'))
  } finally {
    saving.value = false
  }
}

const registerProposal = async () => {
  const count = form.invitee_ids.length
  const minInvitees = settings.value.min_members - 1
  const maxInvitees = settings.value.max_members - 1

  if (count < minInvitees || count > maxInvitees) {
    formError.value = `Please select between ${minInvitees} and ${maxInvitees} team members.`
    return
  }

  if (!proposalFile.value) {
    formError.value = 'Please upload your proposal PDF file.'
    return
  }

  if (!form.supervisor_id) {
    formError.value = 'Please select a supervisor.'
    return
  }

  saving.value = true
  formError.value = ''
  try {
    const inviteeIds = Array.isArray(form.invitee_ids) ? form.invitee_ids : [form.invitee_ids]
    const formData = new FormData()
    formData.append('title', form.title)
    formData.append('description', form.description || '')
    formData.append('area_of_specialization', form.area_of_specialization || '')
    formData.append('supervisor_id', String(form.supervisor_id))
    formData.append('proposal_file', proposalFile.value)
    inviteeIds.map(Number).forEach((id) => formData.append('invitee_ids[]', id))

    const res = await submitProposal(formData)
    project.value = res.data
    canRegisterProposal.value = false
    proposalFile.value = null
    proposalFileName.value = ''
  } catch (err) {
    formError.value = formatApiError(err, 'Submission failed.')
  } finally {
    saving.value = false
  }
}

const supervisorDecision = async (accept) => {
  saving.value = true
  try {
    const res = await supervisorRespond(project.value.id, { accept, feedback: feedbackModal.comments || null })
    project.value = res.data
  } catch (err) {
    toast.error(err.response?.data?.message || 'Action failed.')
  } finally {
    saving.value = false
  }
}

const supervisorRequestRevisionAction = async () => {
  const feedback = feedbackModal.comments?.trim()
  if (!feedback) {
    toast.warning('Please enter comments before returning the proposal for revision.')
    return
  }

  saving.value = true
  try {
    const res = await supervisorRequestRevision(project.value.id, { feedback })
    project.value = res.data
  } catch (err) {
    toast.error(formatApiError(err, 'Action failed.'))
  } finally {
    saving.value = false
  }
}

const supervisorRevisionProceed = async () => {
  const confirmed = await confirmDialog.confirm({
    title: 'Proceed With Revision',
    message: 'Proceed with this revised proposal and send it to the next review stage?',
    confirmText: 'Proceed',
  })

  if (!confirmed) return

  saving.value = true
  try {
    const res = await supervisorRevisionReview(project.value.id, {
      proceed: true,
      feedback: feedbackModal.comments || null,
    })
    project.value = res.data
    toast.success('Revision sent to the next review stage.')
  } catch (err) {
    toast.error(formatApiError(err, 'Action failed.'))
  } finally {
    saving.value = false
  }
}

const supervisorReturnRevision = async () => {
  const feedback = feedbackModal.comments?.trim()
  if (!feedback) {
    toast.warning('Please enter comments before returning the proposal to students.')
    return
  }

  saving.value = true
  try {
    const res = await supervisorRevisionReview(project.value.id, {
      proceed: false,
      feedback,
    })
    project.value = res.data
  } catch (err) {
    toast.error(formatApiError(err, 'Action failed.'))
  } finally {
    saving.value = false
  }
}

const updateSupervisor = async () => {
  saving.value = true
  try {
    const res = await changeSupervisor(project.value.id, newSupervisorId.value)
    project.value = res.data
  } catch (err) {
    toast.error(err.response?.data?.message || 'Update failed.')
  } finally {
    saving.value = false
  }
}

const assignEvaluatorsAction = async () => {
  const count = selectedEvaluators.value.length
  const min = settings.value.min_evaluators
  const max = settings.value.max_evaluators

  if (count < min || count > max) {
    toast.warning(`Please select between ${min} and ${max} evaluators.`)
    return
  }

  saving.value = true
  try {
    const ids = Array.isArray(selectedEvaluators.value) ? selectedEvaluators.value : [selectedEvaluators.value]
    const payload = { evaluator_ids: ids.map(Number) }
    if (project.value?.workflow_stage === 'revision_required') {
      payload.evaluator_resubmit_mode = evaluatorResubmitMode.value
    }
    const res = await assignEvaluators(project.value.id, payload)
    project.value = res.data
    syncSelectedEvaluators()
  } catch (err) {
    toast.error(formatApiError(err, 'Assignment failed.'))
  } finally {
    saving.value = false
  }
}

const phaseApi = () => activeDeliverablePhase.value

const phaseSupervisorDecision = async (accept) => {
  const phase = phaseApi()
  if (!phase) return
  saving.value = true
  try {
    const res = await phaseSupervisorRespond(project.value.id, phase, {
      accept,
      feedback: feedbackModal.comments || null,
    })
    project.value = res.data
  } catch (err) {
    toast.error(formatApiError(err, 'Action failed.'))
  } finally {
    saving.value = false
  }
}

const phaseSupervisorRevisionProceed = async () => {
  const phase = phaseApi()
  if (!phase) return
  saving.value = true
  try {
    const res = await phaseSupervisorRevisionReview(project.value.id, phase, {
      proceed: true,
      feedback: feedbackModal.comments || null,
    })
    project.value = res.data
  } catch (err) {
    toast.error(formatApiError(err, 'Action failed.'))
  } finally {
    saving.value = false
  }
}

const phaseSupervisorReturnRevision = async () => {
  const phase = phaseApi()
  if (!phase) return
  const feedback = feedbackModal.comments?.trim()
  if (!feedback) {
    toast.warning('Please enter comments before returning the deliverable to students.')
    return
  }
  saving.value = true
  try {
    const res = await phaseSupervisorRevisionReview(project.value.id, phase, {
      proceed: false,
      feedback,
    })
    project.value = res.data
  } catch (err) {
    toast.error(formatApiError(err, 'Action failed.'))
  } finally {
    saving.value = false
  }
}

const keepPhaseEvaluatorsAction = async () => {
  const phase = phaseApi()
  if (!phase) return
  saving.value = true
  try {
    const res = await phaseKeepEvaluators(project.value.id, phase)
    project.value = res.data
  } catch (err) {
    toast.error(formatApiError(err, 'Action failed.'))
  } finally {
    saving.value = false
  }
}

const assignPhaseEvaluatorsAction = async () => {
  const phase = phaseApi()
  if (!phase) return
  const count = selectedEvaluators.value.length
  const min = settings.value.min_evaluators
  const max = settings.value.max_evaluators
  if (count < min || count > max) {
    toast.warning(`Please select between ${min} and ${max} evaluators.`)
    return
  }
  saving.value = true
  try {
    const payload = {
      evaluator_ids: selectedEvaluators.value.map(Number),
    }
    if (project.value?.workflow_stage === 'revision_required') {
      payload.evaluator_resubmit_mode = evaluatorResubmitMode.value
    }
    const res = await phaseAssignEvaluators(project.value.id, phase, payload)
    project.value = res.data
    syncSelectedEvaluators()
  } catch (err) {
    toast.error(formatApiError(err, 'Assignment failed.'))
  } finally {
    saving.value = false
  }
}

const phaseReturnFromCommitteeAction = async () => {
  const phase = phaseApi()
  if (!phase) return
  saving.value = true
  try {
    const res = await phaseReturnFromCommittee(project.value.id, phase, {
      comments: feedbackModal.comments || null,
    })
    project.value = res.data
  } catch (err) {
    toast.error(formatApiError(err, 'Action failed.'))
  } finally {
    saving.value = false
  }
}

const submitPhaseEvaluatorDecision = async (decision) => {
  const phase = phaseApi()
  if (!phase) return
  evaluatorCommentError.value = ''
  const comments = evaluatorComments.value.trim()
  const answers = buildAnswersPayload(evaluatorAnswers.value, evaluatorCommentError)
  if (answers === null) return
  if (!comments) {
    evaluatorCommentError.value = 'Please enter a final comment.'
    return
  }
  saving.value = true
  try {
    const res = await phaseEvaluatorReview(project.value.id, phase, { decision, comments, answers })
    project.value = res.data
    evaluatorComments.value = ''
    evaluatorAnswers.value = {}
  } catch (err) {
    toast.error(formatApiError(err, 'Evaluation failed.'))
  } finally {
    saving.value = false
  }
}

const submitPhaseOfficeEvaluatorDecision = async (decision) => {
  const phase = phaseApi()
  if (!phase) return
  officeEvaluatorCommentError.value = ''
  const comments = officeEvaluatorComments.value.trim()

  if (!officeEvaluatorId.value) {
    officeEvaluatorCommentError.value = 'Please select an evaluator.'
    return
  }

  const answers = buildAnswersPayload(officeEvaluatorAnswers.value, officeEvaluatorCommentError)
  if (answers === null) return

  if (!comments) {
    officeEvaluatorCommentError.value = 'Please enter a final comment.'
    return
  }

  saving.value = true
  try {
    const res = await phaseEvaluatorReview(project.value.id, phase, {
      decision,
      comments,
      evaluator_id: Number(officeEvaluatorId.value),
      answers,
    })
    project.value = res.data
    officeEvaluatorComments.value = ''
    officeEvaluatorAnswers.value = {}
    syncOfficeEvaluatorSelection()
  } catch (err) {
    toast.error(formatApiError(err, 'Evaluation failed.'))
  } finally {
    saving.value = false
  }
}

const phaseCommitteeFinalForward = async () => {
  committeeCommentError.value = ''
  saving.value = true
  try {
    await phaseCommitteeFinal(true)
    committeeComments.value = ''
  } finally {
    saving.value = false
  }
}

const phaseCommitteeFinalReturn = async () => {
  committeeCommentError.value = ''
  if (!committeeComments.value.trim()) {
    committeeCommentError.value = 'Please enter comments before returning for revision.'
    return
  }
  saving.value = true
  try {
    await phaseCommitteeFinal(false)
    committeeComments.value = ''
  } finally {
    saving.value = false
  }
}

const downloadCertificate = async (phase) => {
  if (!project.value?.id) return

  const certificate = approvedCertificates.value.find((item) => item.phase === phase)
  const fileName = `${project.value.proposal_session?.code || 'fyp'}-${phase}-certificate.pdf`.toLowerCase()

  downloadingCertificatePhase.value = phase
  formError.value = ''

  try {
    await downloadPhaseCertificate(project.value.id, phase, fileName)
  } catch (err) {
    formError.value = formatApiError(err, `Could not download ${certificate?.phase_label || 'phase'} certificate.`)
  } finally {
    downloadingCertificatePhase.value = null
  }
}

const phaseCommitteeHeadApproveAction = async () => {
  headCommentError.value = ''
  saving.value = true
  try {
    await phaseCommitteeHeadDecision(true)
    headComments.value = ''
  } finally {
    saving.value = false
  }
}

const phaseCommitteeHeadReturnAction = async () => {
  headCommentError.value = ''
  if (!headComments.value.trim()) {
    headCommentError.value = 'Please enter comments before returning for revision.'
    return
  }
  saving.value = true
  try {
    await phaseCommitteeHeadDecision(false)
    headComments.value = ''
  } finally {
    saving.value = false
  }
}

const resubmitDeliverableAction = async () => {
  const phase = phaseApi()
  if (!phase) return
  saving.value = true
  try {
    const res = await phaseResubmitDeliverable(project.value.id, phase)
    project.value = res.data
  } catch (err) {
    toast.error(formatApiError(err, 'Resubmit failed.'))
  } finally {
    saving.value = false
  }
}

const phaseCommitteeFinal = async (approve) => {
  const phase = phaseApi()
  if (!phase) return
  try {
    const payload = {
      approve,
      comments: committeeComments.value.trim() || feedbackModal.comments || null,
    }
    if (!approve) {
      payload.evaluator_resubmit_mode = evaluatorResubmitMode.value
    }
    const res = await phaseCommitteeFinalReview(project.value.id, phase, payload)
    project.value = res.data
  } catch (err) {
    toast.error(formatApiError(err, 'Action failed.'))
    throw err
  }
}

const phaseCommitteeHeadDecision = async (approve) => {
  const phase = phaseApi()
  if (!phase) return
  try {
    const payload = {
      approve,
      comments: headComments.value.trim() || feedbackModal.comments || null,
    }
    if (!approve) {
      payload.evaluator_resubmit_mode = evaluatorResubmitMode.value
    }
    const res = await phaseCommitteeHeadApprove(project.value.id, phase, payload)
    project.value = res.data
  } catch (err) {
    toast.error(formatApiError(err, 'Action failed.'))
    throw err
  }
}

const allowPhaseRepeatCarryForward = async () => {
  const phase = phaseApi()
  if (!phase) return

  const notes = await confirmDialog.confirm({
    title: 'Allow Phase Repeat',
    message: `Approve this student to continue ${project.value?.current_phase_label || 'this phase'} in the next available session without submitting a new proposal?`,
    confirmText: 'Allow',
    prompt: true,
    promptLabel: 'Optional note for the student',
  })

  if (notes === false) return

  saving.value = true
  try {
    const res = await phaseAllowRepeatCarryForward(project.value.id, phase, {
      notes: notes === true ? null : notes,
    })
    project.value = res.data
    toast.success('Student approved to continue in the next available session.')
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to record decision.'))
  } finally {
    saving.value = false
  }
}

const requirePhaseRepeatResubmission = async () => {
  const phase = phaseApi()
  if (!phase) return

  const notes = await confirmDialog.confirm({
    title: 'Require New Proposal',
    message: 'This permanently closes out the current project and requires a brand new proposal submission in a future session. This cannot be undone.',
    confirmText: 'Require Resubmission',
    variant: 'danger',
    prompt: true,
    promptLabel: 'Reason (required, shown to the student)',
    promptRequired: true,
  })

  if (!notes) return

  saving.value = true
  try {
    await phaseRequireRepeatResubmission(project.value.id, phase, { notes })
    toast.success('Student notified to submit a new proposal.')
    router.push('/projects')
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to record decision.'))
  } finally {
    saving.value = false
  }
}

const evaluatorCommentError = ref('')
const officeEvaluatorCommentError = ref('')

const buildAnswersPayload = (answersMap, errorRef) => {
  if (!evaluationQuestions.value.length) {
    errorRef.value = 'No evaluation questions are configured for this phase yet.'
    return null
  }

  const answers = []
  for (const question of evaluationQuestions.value) {
    const entry = answersMap[question.id]
    const marks = Number(entry?.marks)

    if (entry?.marks === undefined || entry?.marks === '' || !Number.isInteger(marks) || marks < 0 || marks > question.max_marks) {
      errorRef.value = `Enter marks between 0 and ${question.max_marks} for "${question.text}".`
      return null
    }

    answers.push({ question_id: question.id, marks, comment: entry?.comment || null })
  }

  errorRef.value = ''
  return answers
}

const submitEvaluatorDecision = async (decision) => {
  evaluatorCommentError.value = ''
  const comments = evaluatorComments.value.trim()
  const answers = buildAnswersPayload(evaluatorAnswers.value, evaluatorCommentError)
  if (answers === null) return

  if (!comments) {
    evaluatorCommentError.value = 'Please enter a final comment.'
    return
  }

  saving.value = true
  try {
    const res = await submitEvaluatorReview(project.value.id, {
      decision,
      comments,
      answers,
    })
    project.value = res.data
    evaluatorComments.value = ''
    evaluatorAnswers.value = {}
  } catch (err) {
    toast.error(formatApiError(err, 'Review failed.'))
  } finally {
    saving.value = false
  }
}

const submitOfficeEvaluatorDecision = async (decision) => {
  officeEvaluatorCommentError.value = ''
  const comments = officeEvaluatorComments.value.trim()

  if (!officeEvaluatorId.value) {
    officeEvaluatorCommentError.value = 'Please select an evaluator.'
    return
  }

  const answers = buildAnswersPayload(officeEvaluatorAnswers.value, officeEvaluatorCommentError)
  if (answers === null) return

  if (!comments) {
    officeEvaluatorCommentError.value = 'Please enter a final comment.'
    return
  }

  saving.value = true
  try {
    const res = await submitEvaluatorReview(project.value.id, {
      decision,
      comments,
      evaluator_id: Number(officeEvaluatorId.value),
      answers,
    })
    project.value = res.data
    officeEvaluatorComments.value = ''
    officeEvaluatorAnswers.value = {}
    syncOfficeEvaluatorSelection()
  } catch (err) {
    toast.error(formatApiError(err, 'Review failed.'))
  } finally {
    saving.value = false
  }
}

const resubmit = async () => {
  if (!revisionFile.value) {
    toast.warning('Please upload the revised proposal PDF.')
    return
  }

  saving.value = true
  try {
    const formData = new FormData()
    formData.append('proposal_file', revisionFile.value)
    const res = await resubmitProposal(project.value.id, formData)
    project.value = res.data
    revisionFile.value = null
    revisionFileName.value = ''
    if (revisionFileInput.value) revisionFileInput.value.value = ''
  } catch (err) {
    toast.error(err.response?.data?.message || 'Resubmit failed.')
  } finally {
    saving.value = false
  }
}

const committeeDecision = async (approve) => {
  committeeCommentError.value = ''
  if (!approve && !committeeComments.value.trim()) {
    committeeCommentError.value = 'Please enter comments before returning for revision.'
    return
  }

  saving.value = true
  try {
    const payload = {
      approve,
      comments: committeeComments.value.trim() || null,
    }
    if (!approve) {
      payload.evaluator_resubmit_mode = evaluatorResubmitMode.value
    }
    const res = await committeeFinalReview(project.value.id, payload)
    project.value = res.data
    committeeComments.value = ''
  } catch (err) {
    toast.error(err.response?.data?.message || 'Action failed.')
  } finally {
    saving.value = false
  }
}

const headDecision = async (approve) => {
  headCommentError.value = ''
  if (!approve && !headComments.value.trim()) {
    headCommentError.value = 'Please enter comments before returning for revision.'
    return
  }

  saving.value = true
  try {
    const payload = {
      approve,
      comments: headComments.value.trim() || null,
    }
    if (!approve) {
      payload.evaluator_resubmit_mode = evaluatorResubmitMode.value
    }
    const res = await committeeHeadApprove(project.value.id, payload)
    project.value = res.data
    headComments.value = ''
  } catch (err) {
    toast.error(err.response?.data?.message || 'Action failed.')
  } finally {
    saving.value = false
  }
}

const openFeedbackModal = (type, meta = null) => {
  feedbackModal.show = true
  feedbackModal.type = type
  feedbackModal.comments = ''
  feedbackModal.meta = meta
}

const closeFeedbackModal = () => {
  feedbackModal.show = false
}

const confirmFeedback = async () => {
  const type = feedbackModal.type
  const meta = feedbackModal.meta
  const comments = feedbackModal.comments
  closeFeedbackModal()
  if (type === 'supervisor_reject') return supervisorDecision(false)
  if (type === 'supervisor_request_revision') return supervisorRequestRevisionAction()
  if (type === 'supervisor_return_revision') return supervisorReturnRevision()
  if (type === 'phase_supervisor_return') return phaseSupervisorDecision(false)
  if (type === 'phase_supervisor_return_revision') return phaseSupervisorReturnRevision()
  if (type === 'phase_committee_return') return phaseReturnFromCommitteeAction()
  if (type === 'phase_committee_final_return') return phaseCommitteeFinal(false)
  if (type === 'phase_committee_head_return') return phaseCommitteeHeadDecision(false)
  if (type === 'sc_current_reject') return supervisorChangeRespond(false, meta?.which || 'current', comments)
  if (type === 'sc_new_reject') return supervisorChangeRespond(false, meta?.which || 'new', comments)
  if (type === 'sc_decline') return supervisorChangeDecide(false, comments)
}

const submitSupervisorChangeRequest = async () => {
  if (!project.value?.id || !supervisorChangeNewId.value || !supervisorChangeReason.value.trim()) {
    supervisorChangeError.value = 'Please select a new supervisor and provide a reason.'
    return
  }

  supervisorChangeError.value = ''
  saving.value = true
  try {
    const res = await submitSupervisorChangeRequestApi(project.value.id, {
      new_supervisor_id: supervisorChangeNewId.value,
      reason: supervisorChangeReason.value.trim(),
    })
    project.value = res.data
    supervisorChangeNewId.value = null
    supervisorChangeReason.value = ''
  } catch (err) {
    supervisorChangeError.value = formatApiError(err, 'Failed to submit supervisor change request.')
  } finally {
    saving.value = false
  }
}

const supervisorChangeRespond = async (accept, which = null, comments = null) => {
  if (!project.value?.id || !supervisorChangeRequest.value?.id) return

  saving.value = true
  try {
    const payload = {
      accept,
      comments: comments ?? feedbackModal.comments ?? null,
    }
    if (which) payload.which = which

    const res = await respondSupervisorChangeRequest(
      project.value.id,
      supervisorChangeRequest.value.id,
      payload
    )
    project.value = res.data
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to record supervisor change response.'))
  } finally {
    saving.value = false
  }
}

const respondSupervisorChangeAction = (which, accept) => supervisorChangeRespond(accept, which)

const supervisorChangeDecide = async (approve, comments = null) => {
  if (!project.value?.id || !supervisorChangeRequest.value?.id) return

  saving.value = true
  try {
    const res = await decideSupervisorChangeRequest(
      project.value.id,
      supervisorChangeRequest.value.id,
      {
        approve,
        comments: comments ?? feedbackModal.comments ?? null,
      }
    )
    project.value = res.data
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to record supervisor change decision.'))
  } finally {
    saving.value = false
  }
}

const decideSupervisorChangeAction = (approve) => supervisorChangeDecide(approve)

const cancelSupervisorChangeAction = async () => {
  if (!project.value?.id || !supervisorChangeRequest.value?.id) return

  const confirmed = await confirmDialog.confirm({
    title: 'Cancel Supervisor Change',
    message: 'Cancel this supervisor change request?',
    confirmText: 'Cancel Request',
    variant: 'danger',
  })

  if (!confirmed) return

  saving.value = true
  try {
    const res = await cancelSupervisorChangeRequest(
      project.value.id,
      supervisorChangeRequest.value.id
    )
    project.value = res.data
    toast.success('Supervisor change request cancelled.')
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to cancel supervisor change request.'))
  } finally {
    saving.value = false
  }
}

const openTeamActionModal = (type, member = null) => {
  teamActionModal.show = true
  teamActionModal.type = type
  teamActionModal.member = member
  teamActionModal.comments = ''

  if (type === 'remove-member') {
    teamActionModal.title = 'Remove Team Member'
    teamActionModal.message = `Remove ${member?.user?.name || 'this student'} from the project team?`
  } else if (type === 'return-to-team-formation') {
    teamActionModal.title = 'Return to Team Formation'
    teamActionModal.message = 'This will reopen the team formation stage. The group leader can invite more members. Evaluator assignments and downstream review progress will be cleared.'
  } else {
    teamActionModal.title = 'Delete Project'
    teamActionModal.message = 'This will delete the project and dissolve the entire team. All members will be unenrolled from the proposal.'
  }
}

const closeTeamActionModal = () => {
  teamActionModal.show = false
  teamActionModal.type = null
  teamActionModal.member = null
}

const confirmTeamAction = async () => {
  if (!project.value) return

  saving.value = true
  try {
    if (teamActionModal.type === 'remove-member' && teamActionModal.member) {
      const res = await removeProjectMember(project.value.id, teamActionModal.member.id, {
        comments: teamActionModal.comments || null,
      })
      project.value = res.data
      closeTeamActionModal()
      if (project.value?.can_manage_team_members) {
        await loadTransferableMembers()
      }
      return
    }

    if (teamActionModal.type === 'return-to-team-formation') {
      const res = await returnToTeamFormation(project.value.id, {
        comments: teamActionModal.comments || null,
      })
      project.value = res.data
      closeTeamActionModal()
      if (showTeamFormationTools.value) {
        await loadEligibleMembersForProject()
      }
      return
    }

    if (teamActionModal.type === 'delete-project') {
      await deleteProject(project.value.id, { comments: teamActionModal.comments || null })
      closeTeamActionModal()
      if (props.projectId) {
        if (authStore.canViewAllProjects) {
          router.push('/projects')
        } else if (authStore.hasRole('evaluator')) {
          router.push('/evaluator/assigned-projects')
        } else if (authStore.hasRole('supervisor')) {
          router.push('/supervisor/projects')
        } else {
          router.push('/projects')
        }
      } else {
        project.value = null
      }
    }
  } catch (err) {
    toast.error(formatApiError(err, 'Action failed.'))
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.workflow-card {
  border-radius: 0.5rem;
  overflow: hidden;
  box-shadow: 0 4px 24px rgba(34, 41, 47, 0.08);
  background: #fff;
}

.workflow-card--dropdown {
  overflow: visible;
}

.workflow-card--dropdown .workflow-card__body {
  overflow: visible;
}

.workflow-card__header {
  background: linear-gradient(135deg, #7367f0, #9055fd);
  color: #fff;
  padding: 0.85rem 1.25rem;
  font-weight: 600;
}

.workflow-card__header--danger {
  background: linear-gradient(135deg, #ea5455, #ff6b6b);
}

.workflow-card__body {
  padding: 1.25rem;
}

.workflow-stepper {
  display: flex;
  gap: 0.35rem;
  overflow-x: auto;
  padding-bottom: 0.5rem;
}

.workflow-step {
  min-width: 110px;
  text-align: center;
  flex: 1;
}

.workflow-step__circle {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  border: 2px solid #d8d6de;
  color: #6e6b7b;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  margin-bottom: 0.35rem;
  background: #fff;
}

.workflow-step.active .workflow-step__circle,
.workflow-step.completed .workflow-step__circle {
  background: #7367f0;
  border-color: #7367f0;
  color: #fff;
}

.workflow-step__label {
  font-size: 0.72rem;
  color: #6e6b7b;
  line-height: 1.2;
}

.details-table th {
  width: 42%;
  background: #f8f8f8;
  font-size: 0.85rem;
  color: #6e6b7b;
}

.details-table td {
  font-size: 0.9rem;
  color: #5e5873;
  font-weight: 500;
}

.remarks-list {
  max-height: 420px;
  overflow-y: auto;
}

.remark-badge {
  background: #7367f0;
  color: #fff;
  border-radius: 999px;
  padding: 0.15rem 0.65rem;
  font-size: 0.75rem;
  text-transform: capitalize;
}

.remark-date {
  float: right;
  font-size: 0.75rem;
  color: #b9b9c3;
}

.remark-action {
  color: #7367f0;
  font-weight: 600;
  margin: 0.35rem 0;
}

.remark-comments {
  background: #f8f8f8;
  border-radius: 0.35rem;
  padding: 0.65rem;
  font-size: 0.85rem;
}

.proposal-pdf-panel {
  display: flex;
  flex-direction: column;
  min-height: 420px;
}

.proposal-pdf-viewer {
  display: flex;
  flex-direction: column;
  flex: 1;
}

.team-member-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.team-member-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.85rem 1rem;
  border: 1px solid #ebe9f1;
  border-radius: 0.5rem;
  background: #fafafa;
}

.team-member-row__name {
  font-weight: 600;
  color: #5e5873;
}

.team-member-row__meta {
  font-size: 0.8125rem;
  color: #b9b9c3;
  margin-top: 0.15rem;
}

.team-delete-section {
  margin-top: 1.25rem;
  padding-top: 1rem;
  border-top: 1px dashed #ebe9f1;
}

.invite-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding-right: 0.35rem;
  flex-wrap: wrap;
}

.invite-badge__email {
  font-size: 0.75rem;
  color: #5e5873;
}

.pending-invite-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.invite-badge__accept {
  border: 0;
  background: transparent;
  color: #28c76f;
  padding: 0;
  line-height: 1;
  display: inline-flex;
  align-items: center;
}

.invite-badge__accept:hover:not(:disabled) {
  color: #1f9d57;
}

.invite-badge__cancel {
  border: 0;
  background: transparent;
  color: #5e5873;
  padding: 0;
  line-height: 1;
  display: inline-flex;
  align-items: center;
}

.invite-badge__cancel:hover:not(:disabled) {
  color: #ea5455;
}

.sc-info {
  background: #f8f8f8;
  border: 1px solid #ebe9f1;
  border-radius: 0.375rem;
  padding: 0.85rem 1rem;
  height: 100%;
}

.sc-info__label {
  font-size: 0.75rem;
  color: #6e6b7b;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 0.35rem;
}

.sc-info__value {
  font-weight: 600;
  color: #5e5873;
  margin-bottom: 0.5rem;
}

.sc-info__comment {
  font-size: 0.82rem;
  color: #6e6b7b;
  margin-top: 0.35rem;
}

.team-status-list {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.team-status-item {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
}
</style>
