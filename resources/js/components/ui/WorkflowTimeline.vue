<template>
  <div class="workflow-timeline">
    <div v-if="auditOnly" class="d-flex justify-content-end mb-2">
      <span class="badge bg-light text-dark">Showing sensitive audit actions only</span>
    </div>
    <div v-if="!visibleItems.length" class="text-muted small">No activity recorded yet.</div>
    <div v-for="item in visibleItems" :key="item.id" class="workflow-timeline__item">
      <div class="workflow-timeline__marker" :class="{ 'workflow-timeline__marker--sensitive': item.is_sensitive }"></div>
      <div class="workflow-timeline__content">
        <div class="d-flex flex-wrap justify-content-between gap-2">
          <div>
            <strong>{{ item.action_label || item.action }}</strong>
            <span v-if="item.is_sensitive" class="badge bg-warning text-dark ms-2">Audit</span>
          </div>
          <span class="text-muted small">{{ item.created_at }}</span>
        </div>
        <div class="text-muted small mt-1">
          {{ item.stage_label || item.stage }}
        </div>
        <div v-if="item.comments" class="workflow-timeline__comments mt-2">{{ item.comments }}</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  items: { type: Array, default: () => [] },
  auditOnly: { type: Boolean, default: false },
})

const visibleItems = computed(() =>
  props.auditOnly ? props.items.filter((item) => item.is_sensitive) : props.items
)
</script>

<style scoped>
.workflow-timeline {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.workflow-timeline__item {
  display: grid;
  grid-template-columns: 1rem 1fr;
  gap: 0.85rem;
}

.workflow-timeline__marker {
  width: 0.75rem;
  height: 0.75rem;
  border-radius: 50%;
  background: #7367f0;
  margin-top: 0.35rem;
  position: relative;
}

.workflow-timeline__marker--sensitive {
  background: #ff9f43;
}

.workflow-timeline__item:not(:last-child) .workflow-timeline__marker::after {
  content: '';
  position: absolute;
  top: 0.9rem;
  left: 50%;
  transform: translateX(-50%);
  width: 2px;
  height: calc(100% + 0.65rem);
  background: #ebe9f1;
}

.workflow-timeline__content {
  background: #fff;
  border: 1px solid #ebe9f1;
  border-radius: 0.5rem;
  padding: 0.85rem 1rem;
}

.workflow-timeline__comments {
  background: #f8f8f8;
  border-radius: 0.35rem;
  padding: 0.65rem 0.75rem;
  font-size: 0.875rem;
}
</style>
