<template>
  <AuthLayout title="Reset password" subtitle="Choose a new password for your account">
    <div v-if="!token || !email" class="alert alert-danger py-2 mb-0">
      This reset link is invalid or incomplete. Please request a new one.
      <div class="mt-3">
        <router-link to="/forgot-password" class="btn btn-outline-primary w-100">Request a new link</router-link>
      </div>
    </div>

    <form v-else-if="!submitted" class="auth-form" @submit.prevent="handleSubmit" novalidate>
      <div v-if="error" class="alert alert-danger d-flex align-items-center py-2 mb-3" role="alert">
        <i class="bi bi-exclamation-circle me-2"></i>
        <span>{{ error }}</span>
      </div>

      <div class="mb-3">
        <label class="form-label">Email</label>
        <div class="input-group-auth">
          <span class="input-group-auth__icon"><i class="bi bi-envelope"></i></span>
          <input type="email" class="form-control" :value="email" disabled />
        </div>
      </div>

      <div class="mb-3">
        <label for="password" class="form-label">New Password</label>
        <div class="input-group-auth">
          <span class="input-group-auth__icon"><i class="bi bi-lock"></i></span>
          <input
            id="password"
            v-model="form.password"
            :type="showPassword ? 'text' : 'password'"
            class="form-control"
            placeholder="At least 8 characters"
            autocomplete="new-password"
            required
            :disabled="loading"
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

      <div class="mb-4">
        <label for="password_confirmation" class="form-label">Confirm New Password</label>
        <div class="input-group-auth">
          <span class="input-group-auth__icon"><i class="bi bi-lock"></i></span>
          <input
            id="password_confirmation"
            v-model="form.password_confirmation"
            :type="showPassword ? 'text' : 'password'"
            class="form-control"
            placeholder="Re-enter the new password"
            autocomplete="new-password"
            required
            :disabled="loading"
          />
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100 auth-form__submit" :disabled="loading">
        <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span>
        <i v-else class="bi bi-check2-circle me-2"></i>
        Reset Password
      </button>
    </form>

    <div v-else class="text-center">
      <div class="auth-form__success-icon mb-3">
        <i class="bi bi-check2-circle"></i>
      </div>
      <p class="mb-4">Your password has been reset successfully.</p>
      <router-link to="/login" class="btn btn-primary w-100">Sign In</router-link>
    </div>
  </AuthLayout>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { resetPassword } from '@/api/auth'
import { formatApiError } from '@/utils/apiErrors'

const route = useRoute()
const token = route.query.token || ''
const email = route.query.email || ''

const form = reactive({
  password: '',
  password_confirmation: '',
})

const loading = ref(false)
const error = ref('')
const showPassword = ref(false)
const submitted = ref(false)

const handleSubmit = async () => {
  error.value = ''

  if (!form.password || !form.password_confirmation) {
    error.value = 'Please fill in both password fields.'
    return
  }

  if (form.password !== form.password_confirmation) {
    error.value = 'Passwords do not match.'
    return
  }

  loading.value = true
  try {
    await resetPassword({
      token,
      email,
      password: form.password,
      password_confirmation: form.password_confirmation,
    })
    submitted.value = true
  } catch (err) {
    error.value = formatApiError(err, 'Could not reset your password. The link may have expired.')
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.auth-form__submit {
  padding: 0.65rem 1rem;
  font-weight: 500;
}

.auth-form__success-icon {
  width: 64px;
  height: 64px;
  margin: 0 auto;
  border-radius: 50%;
  background: rgba(20, 184, 166, 0.12);
  color: #14b8a6;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.75rem;
}
</style>
