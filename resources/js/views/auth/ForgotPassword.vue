<template>
  <AuthLayout title="Forgot password?" subtitle="Enter your email and we'll send you a reset link">
    <form v-if="!submitted" class="auth-form" @submit.prevent="handleSubmit" novalidate>
      <div v-if="error" class="alert alert-danger d-flex align-items-center py-2 mb-3" role="alert">
        <i class="bi bi-exclamation-circle me-2"></i>
        <span>{{ error }}</span>
      </div>

      <div class="mb-4">
        <label for="email" class="form-label">Email</label>
        <div class="input-group-auth">
          <span class="input-group-auth__icon"><i class="bi bi-envelope"></i></span>
          <input
            id="email"
            v-model="email"
            type="email"
            class="form-control"
            placeholder="Enter your registered email"
            autocomplete="email"
            required
            :disabled="loading"
          />
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100 auth-form__submit" :disabled="loading">
        <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span>
        <i v-else class="bi bi-send me-2"></i>
        Send Reset Link
      </button>

      <div class="text-center mt-4">
        <router-link to="/login" class="auth-form__back-link">
          <i class="bi bi-arrow-left me-1"></i> Back to sign in
        </router-link>
      </div>
    </form>

    <div v-else class="text-center">
      <div class="auth-form__success-icon mb-3">
        <i class="bi bi-envelope-check"></i>
      </div>
      <p class="mb-4">{{ successMessage }}</p>
      <router-link to="/login" class="btn btn-outline-primary w-100">
        <i class="bi bi-arrow-left me-1"></i> Back to sign in
      </router-link>
    </div>
  </AuthLayout>
</template>

<script setup>
import { ref } from 'vue'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { forgotPassword } from '@/api/auth'
import { formatApiError } from '@/utils/apiErrors'

const email = ref('')
const loading = ref(false)
const error = ref('')
const submitted = ref(false)
const successMessage = ref('')

const handleSubmit = async () => {
  error.value = ''

  if (!email.value) {
    error.value = 'Please enter your email.'
    return
  }

  loading.value = true
  try {
    const res = await forgotPassword({ email: email.value })
    successMessage.value = res.message || 'If an account exists for that email, a password reset link has been sent.'
    submitted.value = true
  } catch (err) {
    error.value = formatApiError(err, 'Could not send the reset link. Please try again.')
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

.auth-form__back-link {
  font-size: 0.875rem;
  color: #8a8fa3;
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
