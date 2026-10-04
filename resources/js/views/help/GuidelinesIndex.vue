<template>
  <div>
    <PageHeader
      title="Guidelines"
      subtitle="Step-by-step guide to the whole FYP workflow — what happens, who acts, and what to do next"
      breadcrumb="Guidelines"
    />

    <AppCard class="mb-4">
      <div class="guide-toc">
        <a v-for="section in sections" :key="section.id" :href="`#${section.id}`" class="guide-toc__link">
          <i :class="section.icon"></i> {{ section.title }}
        </a>
      </div>
    </AppCard>

    <AppCard id="overview" title="1. How the FYP Journey Works" class="mb-4">
      <p class="text-muted">
        Every project moves through three phases in order. You cannot skip ahead — each phase must be approved
        before the next one opens.
      </p>
      <div class="guide-pipeline">
        <div class="guide-pipeline__step">
          <div class="guide-pipeline__badge">1</div>
          <div>
            <strong>Proposal</strong>
            <div class="text-muted small">Form a team, choose a supervisor, submit and get the proposal approved.</div>
          </div>
        </div>
        <i class="bi bi-arrow-right guide-pipeline__arrow"></i>
        <div class="guide-pipeline__step">
          <div class="guide-pipeline__badge">2</div>
          <div>
            <strong>Phase 1</strong>
            <div class="text-muted small">Submit your first deliverable and get it reviewed and approved.</div>
          </div>
        </div>
        <i class="bi bi-arrow-right guide-pipeline__arrow"></i>
        <div class="guide-pipeline__step">
          <div class="guide-pipeline__badge">3</div>
          <div>
            <strong>Phase 2</strong>
            <div class="text-muted small">Submit your final deliverable — approval here completes the project.</div>
          </div>
        </div>
      </div>
      <p class="text-muted mb-0 mt-3">
        Phase 1 and Phase 2 each go through the <strong>same review chain</strong> as the proposal
        (Supervisor → Committee → Evaluators → Committee → Committee Head), just on that phase's deliverable
        instead of the initial proposal document.
      </p>
    </AppCard>

    <AppCard id="team" title="2. Forming Your Team" class="mb-4">
      <RoleTag role="student" /> <RoleTag role="admin" />
      <ul class="guide-list mt-3">
        <li>A team needs <strong>3 to 5 members</strong>, including the leader (the student who submits the proposal).</li>
        <li>The leader invites other students from the <strong>same department</strong> who aren't already on a team.</li>
        <li>Each invitee sees the invitation and can <AppBadge variant="success" label="Accept" light /> or
          <AppBadge variant="danger" label="Reject" light /> it. A reason is required to reject.</li>
        <li>
          <strong>If a student rejects</strong>, that slot re-opens immediately — the leader can invite a
          different student, or re-send the invitation to the same one later.
        </li>
        <li>Once the team has enough active members and no invitations are still pending, the team is
          automatically considered formed and moves on to choosing a supervisor.</li>
        <li>The FYP office (admin / committee) can also invite on the leader's behalf, add a student directly
          without an invitation, or cancel a pending invitation if needed.</li>
      </ul>
    </AppCard>

    <AppCard id="supervisor" title="3. Choosing a Supervisor" class="mb-4">
      <RoleTag role="student" /> <RoleTag role="supervisor" /> <RoleTag role="admin" />
      <ul class="guide-list mt-3">
        <li>The leader picks one supervisor when submitting the proposal (or once the team is formed).</li>
        <li>
          <strong>While the invitation is pending</strong>, the supervisor's name is shown as
          "Pending confirmation" on your project page — it isn't a confirmed assignment yet, so it can still
          change if they decline.
        </li>
        <li>The invited supervisor (or the FYP office on their behalf) can
          <AppBadge variant="success" label="Accept" light /> or <AppBadge variant="danger" label="Decline" light />.
        </li>
        <li>
          <strong>If the supervisor declines</strong>, the leader immediately gets a "Select New Supervisor" box
          to pick someone else — no need to wait or contact the office.
        </li>
        <li>Once a supervisor <strong>accepts</strong>, the project automatically moves to Committee review, and
          only then does a "Request Supervisor Change" option become available if you ever need to switch later
          (see section 8).</li>
      </ul>
    </AppCard>

    <AppCard id="review-chain" title="4. The Review Chain (Proposal, Phase 1, Phase 2)" class="mb-4">
      <p class="text-muted">
        Once submitted, every proposal or deliverable passes through the same four checkpoints, in order:
      </p>
      <ol class="guide-chain">
        <li>
          <strong>Supervisor Review</strong> <RoleTag role="supervisor" />
          <div class="text-muted small">
            Your supervisor checks it first. Approve → moves to Committee. Reject → comes back to you as
            <AppBadge variant="warning" label="Revision Required" light /> with feedback to fix.
          </div>
        </li>
        <li>
          <strong>Committee Review</strong> <RoleTag role="committee" />
          <div class="text-muted small">
            The FYP committee assigns 2–3 evaluators (or keeps the existing ones), or can send it straight back
            for revision without evaluators if something is clearly missing.
          </div>
        </li>
        <li>
          <strong>Evaluator Review</strong> <RoleTag role="evaluator" />
          <div class="text-muted small">
            Each assigned evaluator scores it against the question bank (see section 5) and records a decision:
            Accepted, Rejected, or Revision Required. Nothing moves forward until <em>every</em> assigned
            evaluator has submitted.
          </div>
        </li>
        <li>
          <strong>Committee Final Decision</strong> <RoleTag role="committee" />
          <div class="text-muted small">
            Once all evaluator reviews are in, the committee reads the summary and either forwards it to the
            Committee Head, or returns it for revision (choosing whether all evaluators or only the ones who
            didn't approve need to re-review after resubmission).
          </div>
        </li>
        <li>
          <strong>Committee Head Approval</strong> <RoleTag role="committee-head" />
          <div class="text-muted small">
            The final sign-off. Approve → the phase is officially <AppBadge variant="success" label="Approved" light />.
            Reject → back for revision.
          </div>
        </li>
      </ol>
    </AppCard>

    <AppCard id="evaluation" title="5. How Evaluation & Marks Work" class="mb-4">
      <RoleTag role="evaluator" /> <RoleTag role="admin" />
      <ul class="guide-list mt-3">
        <li>Each phase (Proposal, Phase 1, Phase 2) has its own <strong>question bank</strong>, set up by the FYP office.</li>
        <li>Every question carries its own maximum marks, decided when the question was created.</li>
        <li>When you evaluate, you must enter a mark (0 up to that question's maximum) for
          <strong>every</strong> question — you can't skip one.</li>
        <li>Each question also has an <strong>optional</strong> comment box just for that question.</li>
        <li>A single <strong>overall final comment is mandatory</strong> on every submission, regardless of your decision.</li>
        <li>Your total score is the sum of your per-question marks — this is what students and the certificate
          eventually show, not a separate number you type in.</li>
        <li>All evaluators carry equal weight — there's no per-evaluator weighting to configure.</li>
      </ul>
    </AppCard>

    <AppCard id="revisions" title="6. If You're Asked to Revise" class="mb-4">
      <RoleTag role="student" /> <RoleTag role="supervisor" />
      <ul class="guide-list mt-3">
        <li>
          <AppBadge variant="warning" label="Revision Required" light /> means a supervisor, the committee, or
          the Committee Head sent it back with feedback — read the comments on your project page.
        </li>
        <li>The leader edits the content and re-uploads the file, then resubmits.</li>
        <li>A resubmission always goes back to the <strong>supervisor</strong> first, not straight to evaluators.</li>
        <li>
          When the committee or Committee Head first requested the revision, they chose one of two modes:
          <ul class="mt-2">
            <li><strong>All evaluators re-review</strong> — every evaluator scores the resubmission fresh.</li>
            <li><strong>Only previously-negative evaluators re-review</strong> — evaluators who already approved
              keep their marks; only the ones who rejected or asked for changes look at it again.</li>
          </ul>
        </li>
      </ul>
    </AppCard>

    <AppCard id="reevaluate" title="7. Reevaluation (After a Phase Is Already Approved)" class="mb-4">
      <RoleTag role="admin" /> <RoleTag role="committee-head" /> <RoleTag role="evaluator" />
      <ul class="guide-list mt-3">
        <li>The FYP office can reopen an already-<strong>approved</strong> Phase 1 or Phase 2 deliverable for a
          fresh evaluation cycle — for example, to correct a scoring mistake.</li>
        <li>They choose to keep the same evaluators or assign new ones, and set an extended deadline
          (3 days by default).</li>
        <li>This clears the previous marks for that phase only and reopens it for evaluator review —
          it does <strong>not</strong> undo your project's overall progress or move it back a phase.</li>
        <li>Evaluators simply review and score again like normal, before the extended deadline.</li>
      </ul>
    </AppCard>

    <AppCard id="team-changes" title="8. Changing Teams or Supervisors Later" class="mb-4">
      <RoleTag role="student" /> <RoleTag role="supervisor" /> <RoleTag role="admin" />
      <p class="fw-semibold mb-1">Moving to a different team (transfer request)</p>
      <ul class="guide-list">
        <li>A team <strong>member</strong> (not the leader) who wants to switch groups requests a transfer.</li>
        <li>The FYP office first reviews the request; once approved as eligible, the student can pick another
          team in the <strong>same phase</strong> to try to join.</li>
        <li>The target team's leader (or their supervisor, or the office) decides whether to accept — if declined,
          the student is simply eligible again to try another team.</li>
      </ul>
      <p class="fw-semibold mb-1 mt-3">Changing your supervisor after they've accepted</p>
      <ul class="guide-list">
        <li>This formal request only appears once your current supervisor has actually <strong>accepted</strong> —
          before that, just use the "select a different supervisor" option described in section 3.</li>
        <li>The leader picks a proposed new supervisor and a reason; both the <strong>current</strong> and the
          <strong>proposed new</strong> supervisor must each respond.</li>
        <li>Once both have responded, the FYP office/Committee Head makes the final call — approve to switch the
          supervisor, or decline to keep the current one.</li>
        <li>Any party can cancel the request while it's still pending.</li>
      </ul>
    </AppCard>

    <AppCard id="privacy" title="9. Who Can See What" class="mb-4">
      <ul class="guide-list">
        <li>In the "Review &amp; Approval Remarks" timeline, evaluator and supervisor names are hidden from
          students and from supervisors — you'll see the role (e.g. "Evaluator", "Supervisor") instead of a
          name. Only admin/committee roles see the actual identities, for accountability.</li>
        <li>Whether students or supervisors can see an evaluator's identity or marks inside a review itself is
          separately configurable by the FYP office per phase (Proposal Settings → Evaluator Visibility).</li>
      </ul>
    </AppCard>

    <AppCard id="status-reference" title="10. Status Reference — &quot;I see status X, what do I do?&quot;" class="mb-4">
      <p class="text-muted">Look up whatever status your project or phase currently shows.</p>
      <div class="table-responsive">
        <table class="table table-sm align-middle guide-table">
          <thead>
            <tr>
              <th style="width: 22%">Status</th>
              <th style="width: 34%">What it means</th>
              <th style="width: 16%">Who acts next</th>
              <th style="width: 28%">What to do</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in statusRows" :key="row.status">
              <td><AppBadge :variant="row.variant" :label="row.status" /></td>
              <td>{{ row.meaning }}</td>
              <td><RoleTag :role="row.roleKey" :inline="true" /></td>
              <td>{{ row.action }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppCard>

    <AppCard id="locked" title="11. Locked Projects & Deadlines" class="mb-4">
      <RoleTag role="student" /> <RoleTag role="admin" />
      <ul class="guide-list mt-3">
        <li>Separately from all the statuses above, each session has its own submission window and deadlines,
          set by the FYP office.</li>
        <li>If a session is fully locked or its deadline has passed, you'll see a locked notice and won't be
          able to submit or edit — even if your project's own status would otherwise allow it.</li>
        <li>Only the FYP office can extend a deadline or reopen submissions for a session — contact them if
          you're blocked by a deadline.</li>
      </ul>
    </AppCard>
  </div>
</template>

<script setup>
import { computed, h } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import AppBadge from '@/components/ui/AppBadge.vue'

const sections = [
  { id: 'overview', title: 'How It Works', icon: 'bi bi-signpost-2' },
  { id: 'team', title: 'Forming Your Team', icon: 'bi bi-people' },
  { id: 'supervisor', title: 'Choosing a Supervisor', icon: 'bi bi-person-badge' },
  { id: 'review-chain', title: 'The Review Chain', icon: 'bi bi-diagram-3' },
  { id: 'evaluation', title: 'Evaluation & Marks', icon: 'bi bi-clipboard-check' },
  { id: 'revisions', title: 'Revisions', icon: 'bi bi-arrow-repeat' },
  { id: 'reevaluate', title: 'Reevaluation', icon: 'bi bi-arrow-clockwise' },
  { id: 'team-changes', title: 'Team & Supervisor Changes', icon: 'bi bi-arrow-left-right' },
  { id: 'privacy', title: 'Who Can See What', icon: 'bi bi-eye' },
  { id: 'status-reference', title: 'Status Reference', icon: 'bi bi-list-check' },
  { id: 'locked', title: 'Locked & Deadlines', icon: 'bi bi-lock' },
]

const ROLE_LABELS = {
  student: { label: 'Student / Leader', variant: 'primary' },
  supervisor: { label: 'Supervisor', variant: 'info' },
  evaluator: { label: 'Evaluator', variant: 'warning' },
  committee: { label: 'Committee', variant: 'secondary' },
  'committee-head': { label: 'Committee Head', variant: 'danger' },
  admin: { label: 'FYP Office / Admin', variant: 'success' },
}

// eslint-disable-next-line vue/one-component-per-file
const RoleTag = {
  props: { role: { type: String, required: true }, inline: { type: Boolean, default: false } },
  setup(props) {
    return () => {
      const meta = ROLE_LABELS[props.role] || { label: props.role, variant: 'secondary' }
      return h(AppBadge, { variant: meta.variant, label: meta.label, class: props.inline ? '' : 'me-1' })
    }
  },
}

const statusRows = computed(() => [
  { status: 'invitations_pending', variant: 'secondary', roleKey: 'student', meaning: 'Team is still forming — invitations are pending or the group isn’t full yet.', action: 'Leader invites members, or waits for pending invitations to be answered.' },
  { status: 'supervisor_pending', variant: 'secondary', roleKey: 'supervisor', meaning: 'A supervisor has been invited and hasn’t responded yet.', action: 'Wait — the supervisor needs to accept or decline.' },
  { status: 'supervisor_rejected', variant: 'danger', roleKey: 'student', meaning: 'The invited supervisor declined.', action: 'Leader selects a different supervisor from the box shown on the project page.' },
  { status: 'committee_review', variant: 'info', roleKey: 'committee', meaning: 'Supervisor accepted — waiting for the committee to assign evaluators.', action: 'Committee assigns 2–3 evaluators.' },
  { status: 'evaluator_review', variant: 'info', roleKey: 'evaluator', meaning: 'Evaluators have been assigned and are reviewing.', action: 'Each assigned evaluator submits marks + comments.' },
  { status: 'committee_final', variant: 'info', roleKey: 'committee', meaning: 'All evaluators have submitted their reviews.', action: 'Committee approves (forwards to Head) or sends back for revision.' },
  { status: 'committee_head_approval', variant: 'info', roleKey: 'committee-head', meaning: 'Committee approved — awaiting the Head’s final sign-off.', action: 'Committee Head approves or sends back for revision.' },
  { status: 'revision_required', variant: 'warning', roleKey: 'student', meaning: 'Sent back with feedback at some stage of review.', action: 'Read the feedback, fix the content/file, and resubmit.' },
  { status: 'supervisor_revision_pending', variant: 'warning', roleKey: 'supervisor', meaning: 'A revision was resubmitted and is waiting on the supervisor again.', action: 'Supervisor approves it onward or rejects again with feedback.' },
  { status: 'approved', variant: 'success', roleKey: 'admin', meaning: 'This phase is fully approved.', action: 'Nothing to do here — the FYP office later opens the next phase for the whole session.' },
])
</script>

<style scoped>
.guide-toc {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.guide-toc__link {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.4rem 0.85rem;
  border-radius: 50rem;
  background: #f8f7fa;
  color: #5e5873;
  font-size: 0.875rem;
  text-decoration: none;
  transition: background 0.15s ease, color 0.15s ease;
}

.guide-toc__link:hover {
  background: rgba(115, 103, 240, 0.12);
  color: #7367f0;
}

.guide-pipeline {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
}

.guide-pipeline__step {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex: 1;
  min-width: 200px;
}

.guide-pipeline__badge {
  width: 2.25rem;
  height: 2.25rem;
  flex-shrink: 0;
  border-radius: 50%;
  background: rgba(115, 103, 240, 0.12);
  color: #7367f0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
}

.guide-pipeline__arrow {
  color: #a8aaae;
  font-size: 1.25rem;
  flex-shrink: 0;
}

.guide-list li {
  margin-bottom: 0.5rem;
  line-height: 1.55;
}

.guide-chain {
  padding-left: 1.25rem;
}

.guide-chain > li {
  margin-bottom: 1rem;
  line-height: 1.55;
}

.guide-table th {
  font-size: 0.8125rem;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  color: #a8aaae;
}

.guide-table td {
  font-size: 0.875rem;
}
</style>
