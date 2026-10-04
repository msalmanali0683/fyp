<template>
  <div v-if="sessionContext?.session" class="session-countdown mb-4">
    <div class="session-countdown__header">
      <div>
        <div class="session-countdown__title">{{ sessionContext.session.name }}</div>
        <div class="session-countdown__subtitle">
          Session code: {{ sessionContext.session.code }}
          <span v-if="lifecycleLabel" class="badge bg-primary ms-2">{{ lifecycleLabel }}</span>
          <template v-if="sessionContext.awaiting_next_phase">
            <span class="badge bg-warning text-dark ms-2">Waiting on your phase</span>
          </template>
          <template v-else>
            <span v-if="sessionContext.is_submission_open" class="badge bg-success ms-2">{{ submissionKindLabel }} open</span>
            <span v-else class="badge bg-secondary ms-2">{{ submissionKindLabel }} closed</span>
            <span v-if="sessionContext.is_fully_locked" class="badge bg-danger ms-2">All changes locked</span>
            <span v-else-if="sessionContext.phase === 'final_edits'" class="badge bg-info ms-2">Existing projects active</span>
          </template>
        </div>
      </div>
      <div v-if="countdownLabel && !sessionContext.awaiting_next_phase" class="session-countdown__phase">{{ countdownLabel }}</div>
    </div>

    <div v-if="sessionContext.awaiting_next_phase" class="alert alert-info py-2 mb-0 mt-3">
      {{ sessionContext.awaiting_next_phase_message }}
    </div>

    <template v-else>
      <div v-if="sessionContext.is_fully_locked" class="alert alert-danger py-2 mb-0 mt-3">
        All proposal changes are locked for this session. You can view your project but cannot make further edits.
      </div>

      <div
        v-if="showSubmissionClosedNotice"
        class="alert alert-info py-2 mb-0 mt-3"
      >
        New project registration is closed. If you already have a project, you can continue workflow actions until the final lock deadline below.
      </div>

      <div v-if="showCountdownTimer" class="session-countdown__timer mt-3">
        <div class="session-countdown__digits">
          <div class="session-countdown__unit">
            <span class="session-countdown__value">{{ parts.days }}</span>
            <span class="session-countdown__label">Days</span>
          </div>
          <div class="session-countdown__unit">
            <span class="session-countdown__value">{{ parts.hours }}</span>
            <span class="session-countdown__label">Hours</span>
          </div>
          <div class="session-countdown__unit">
            <span class="session-countdown__value">{{ parts.minutes }}</span>
            <span class="session-countdown__label">Minutes</span>
          </div>
          <div class="session-countdown__unit">
            <span class="session-countdown__value">{{ parts.seconds }}</span>
            <span class="session-countdown__label">Seconds</span>
          </div>
        </div>
        <div class="session-countdown__target text-muted small mt-2">
          Deadline: {{ formattedTarget }}
        </div>
      </div>

      <div
        v-else-if="!sessionContext.is_fully_locked && (sessionContext.phase === 'final_edits' || sessionContext.phase === 'initial_draft')"
        class="alert alert-warning py-2 mb-0 mt-3"
      >
        Deadline information is not available yet. Contact the FYP office.
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

const props = defineProps({
  sessionContext: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['expired'])

const now = ref(Date.now())
let timer = null

const countdownTarget = computed(() => props.sessionContext?.countdown_target || null)
const countdownLabel = computed(() => props.sessionContext?.countdown_label || null)
const lifecycleLabel = computed(() => props.sessionContext?.session?.lifecycle_phase_label || null)
const submissionKindLabel = computed(() => props.sessionContext?.submission_kind_label || 'New registration')

const showSubmissionClosedNotice = computed(() =>
  !props.sessionContext?.awaiting_next_phase
  && !props.sessionContext?.is_fully_locked
  && !props.sessionContext?.is_submission_open
  && props.sessionContext?.phase === 'final_edits'
)

const showCountdownTimer = computed(() =>
  !!countdownTarget.value
  && !props.sessionContext?.is_fully_locked
  && !props.sessionContext?.awaiting_next_phase
)

const remainingSeconds = computed(() => {
  if (!countdownTarget.value) return 0
  return Math.max(0, Math.floor((new Date(countdownTarget.value).getTime() - now.value) / 1000))
})

const parts = computed(() => {
  const total = remainingSeconds.value
  const days = Math.floor(total / 86400)
  const hours = Math.floor((total % 86400) / 3600)
  const minutes = Math.floor((total % 3600) / 60)
  const seconds = total % 60

  return {
    days: String(days).padStart(2, '0'),
    hours: String(hours).padStart(2, '0'),
    minutes: String(minutes).padStart(2, '0'),
    seconds: String(seconds).padStart(2, '0'),
  }
})

const formattedTarget = computed(() => {
  if (!countdownTarget.value) return ''
  return new Date(countdownTarget.value).toLocaleString()
})

onMounted(() => {
  timer = window.setInterval(() => {
    now.value = Date.now()
  }, 1000)
})

onUnmounted(() => {
  if (timer) window.clearInterval(timer)
})

watch(
  () => props.sessionContext?.countdown_target,
  () => {
    now.value = Date.now()
  }
)

watch(remainingSeconds, (value, previous) => {
  if (previous > 0 && value === 0 && countdownTarget.value) {
    emit('expired')
  }
})
</script>

<style scoped>
.session-countdown {
  border: 1px solid rgba(99, 102, 241, 0.25);
  border-radius: 1rem;
  padding: 1.25rem 1.5rem;
  background: linear-gradient(135deg, rgba(99, 102, 241, 0.08), rgba(14, 165, 233, 0.08));
}

.session-countdown__header {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: flex-start;
  flex-wrap: wrap;
}

.session-countdown__title {
  font-size: 1.1rem;
  font-weight: 700;
}

.session-countdown__subtitle {
  color: #64748b;
  font-size: 0.875rem;
}

.session-countdown__phase {
  font-weight: 600;
  color: #4f46e5;
  max-width: 320px;
  text-align: right;
}

.session-countdown__digits {
  display: grid;
  grid-template-columns: repeat(4, minmax(70px, 1fr));
  gap: 0.75rem;
}

.session-countdown__unit {
  background: #fff;
  border-radius: 0.75rem;
  padding: 0.75rem;
  text-align: center;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
}

.session-countdown__value {
  display: block;
  font-size: 1.5rem;
  font-weight: 700;
  line-height: 1.2;
}

.session-countdown__label {
  display: block;
  font-size: 0.75rem;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

@media (max-width: 767.98px) {
  .session-countdown__phase {
    text-align: left;
    max-width: none;
  }

  .session-countdown__digits {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
