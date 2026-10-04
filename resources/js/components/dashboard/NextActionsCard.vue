<template>
  <AppCard title="What To Do Next" subtitle="Your highest-priority tasks" class="mb-3">
    <div v-if="!actions.length" class="text-muted small">
      You're all caught up. No urgent actions right now.
    </div>
    <div v-else class="row g-3">
      <div v-for="action in actions" :key="action.key" class="col-12 col-sm-6 col-xl-3">
        <component :is="actionRoute(action) ? 'router-link' : 'div'" :to="actionRoute(action)" class="next-action-card-link">
          <div class="next-action-card">
            <div
              class="next-action-card__icon"
              :class="`next-action-card__icon--${action.priority === 'high' ? 'warning' : 'primary'}`"
            >
              <i :class="action.icon || 'bi bi-arrow-right-circle'"></i>
            </div>
            <div v-if="action.count" class="next-action-card__value">{{ action.count }}</div>
            <div class="next-action-card__title">{{ action.title }}</div>
            <div class="next-action-card__description">{{ action.description }}</div>
          </div>
        </component>
      </div>
    </div>
  </AppCard>
</template>

<script setup>
import AppCard from '@/components/ui/AppCard.vue'

defineProps({
  actions: { type: Array, default: () => [] },
})

const actionRoute = (action) => {
  if (action.path) {
    return action.path
  }

  if (action.route) {
    return typeof action.route === 'string' ? { name: action.route } : action.route
  }

  return null
}
</script>

<style scoped>
.next-action-card-link {
  display: block;
  height: 100%;
  text-decoration: none;
  color: inherit;
}

.next-action-card {
  position: relative;
  height: 100%;
  padding: 1.1rem 1.15rem;
  border: 1px solid #ebe9f1;
  border-radius: 0.6rem;
  transition: box-shadow 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
}

.next-action-card-link:hover .next-action-card {
  box-shadow: 0 0.35rem 1rem rgba(67, 89, 113, 0.14);
  transform: translateY(-2px);
  border-color: #7367f0;
}

.next-action-card__icon {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 0.75rem;
}

.next-action-card__icon i {
  font-size: 1.25rem;
}

.next-action-card__icon--primary {
  background: rgba(115, 103, 240, 0.12);
  color: #7367f0;
}

.next-action-card__icon--warning {
  background: rgba(255, 159, 67, 0.16);
  color: #ff9f43;
}

.next-action-card__value {
  position: absolute;
  top: 1.1rem;
  right: 1.15rem;
  font-size: 1.5rem;
  font-weight: 700;
  color: #5e5873;
  line-height: 1;
}

.next-action-card__title {
  font-weight: 600;
  margin-bottom: 0.25rem;
}

.next-action-card__description {
  font-size: 0.8125rem;
  color: #a8aaae;
  line-height: 1.4;
}
</style>
