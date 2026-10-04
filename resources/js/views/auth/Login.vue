<template>
  <AuthLayout>
    <form class="auth-form" @submit.prevent="handleSubmit" novalidate>
      <div v-if="error" class="alert alert-danger d-flex align-items-center py-2 mb-3" role="alert">
        <i class="bi bi-exclamation-circle me-2"></i>
        <span>{{ error }}</span>
      </div>

      <div class="mb-3">
        <label for="email" class="form-label">Email</label>
        <div class="input-group-auth">
          <span class="input-group-auth__icon"><i class="bi bi-envelope"></i></span>
          <input
            id="email"
            v-model="form.email"
            type="email"
            class="form-control"
            placeholder="Enter your email"
            autocomplete="email"
            required
            :disabled="authStore.loading"
          />
        </div>
      </div>

      <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <label for="password" class="form-label mb-0">Password</label>
          <router-link to="/forgot-password" class="auth-form__forgot-link">Forgot password?</router-link>
        </div>
        <div class="input-group-auth">
          <span class="input-group-auth__icon"><i class="bi bi-lock"></i></span>
          <input
            id="password"
            v-model="form.password"
            :type="showPassword ? 'text' : 'password'"
            class="form-control"
            placeholder="Enter your password"
            autocomplete="current-password"
            required
            :disabled="authStore.loading"
          />
          <button
            type="button"
            class="input-group-auth__toggle"
            :aria-label="showPassword ? 'Hide password' : 'Show password'"
            @click="showPassword = !showPassword"
          >
            <i :class="showPassword ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>
          </button>
        </div>
      </div>

      <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="form-check">
          <input
            id="remember"
            v-model="form.remember"
            class="form-check-input"
            type="checkbox"
            :disabled="authStore.loading"
          />
          <label class="form-check-label" for="remember">Remember me</label>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100 auth-form__submit" :disabled="authStore.loading">
        <span v-if="authStore.loading" class="spinner-border spinner-border-sm me-2"></span>
        <i v-else class="bi bi-box-arrow-in-right me-2"></i>
        Sign In
      </button>

      <div v-if="showDemoHint" class="auth-demo-hint">
        <small class="text-muted d-block text-center">
          Demo accounts (password: <strong>password</strong>)
        </small>
        <small class="text-muted d-block text-center mt-1">
          <strong>student@fyp.com</strong> · <strong>supervisor@fyp.com</strong> · <strong>admin@fyp.com</strong>
        </small>
        <small class="text-muted d-block text-center">
          <strong>evaluator@fyp.com</strong> · <strong>committee@fyp.com</strong> · <strong>committee-head@fyp.com</strong>
        </small>
      </div>
    </form>
  </AuthLayout>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { useAuthStore } from '@/stores/auth'
import { formatApiError } from '@/utils/apiErrors'

const authStore = useAuthStore()
const router = useRouter()
const route = useRoute()
const error = ref('')
const showPassword = ref(false)
const showDemoHint = import.meta.env.DEV

const form = reactive({
  email: '',
  password: '',
  remember: true,
})

const handleSubmit = async () => {
  error.value = ''

  if (!form.email || !form.password) {
    error.value = 'Please enter email and password.'
    return
  }

  try {
    await authStore.login(form)
    const redirect = route.query.redirect
    if (redirect && typeof redirect === 'string') {
      router.push(redirect)
    } else {
      router.push(authStore.resolveHomeRoute())
    }
  } catch (err) {
    error.value = formatApiError(err, 'Invalid email or password.')
  }
}
</script>

<style scoped>
.auth-form__submit {
  padding: 0.65rem 1rem;
  font-weight: 500;
}

.auth-form__forgot-link {
  font-size: 0.85rem;
}

.auth-demo-hint {
  padding-top: 0.5rem;
  border-top: 1px dashed rgba(34, 41, 47, 0.1);
  margin-top: 1rem;
}
</style>
