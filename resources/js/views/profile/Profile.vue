<template>
  <div>
    <PageHeader title="Profile" subtitle="Manage your account information" breadcrumb="Profile" />

    <div class="row g-4">
      <div class="col-12 col-lg-6">
        <AppCard title="Profile Information">
          <form @submit.prevent="saveProfile">
            <div v-if="profileMessage" class="alert alert-success py-2">{{ profileMessage }}</div>
            <div class="mb-3">
              <label class="form-label">Name</label>
              <input v-model="profileForm.name" type="text" class="form-control" required />
            </div>
            <div class="mb-3">
              <label class="form-label">Email</label>
              <input v-model="profileForm.email" type="email" class="form-control" required />
            </div>
            <div class="mb-3">
              <label class="form-label">Phone</label>
              <input v-model="profileForm.phone" type="text" class="form-control" />
            </div>
            <button type="submit" class="btn btn-primary">Save Profile</button>
          </form>
        </AppCard>
      </div>

      <div class="col-12 col-lg-6">
        <AppCard title="Change Password">
          <form @submit.prevent="savePassword">
            <div v-if="passwordMessage" class="alert alert-success py-2">{{ passwordMessage }}</div>
            <div v-if="passwordError" class="alert alert-danger py-2">{{ passwordError }}</div>
            <div class="mb-3">
              <label class="form-label">Current Password</label>
              <input v-model="passwordForm.current_password" type="password" class="form-control" required />
            </div>
            <div class="mb-3">
              <label class="form-label">New Password</label>
              <input v-model="passwordForm.password" type="password" class="form-control" required />
            </div>
            <div class="mb-3">
              <label class="form-label">Confirm Password</label>
              <input v-model="passwordForm.password_confirmation" type="password" class="form-control" required />
            </div>
            <button type="submit" class="btn btn-primary">Update Password</button>
          </form>
        </AppCard>
      </div>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref, watch } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import { useAuthStore } from '@/stores/auth'
import { updateProfile, updatePassword } from '@/api/auth'

const authStore = useAuthStore()
const profileMessage = ref('')
const passwordMessage = ref('')
const passwordError = ref('')

const profileForm = reactive({
  name: '',
  email: '',
  phone: '',
})

const passwordForm = reactive({
  current_password: '',
  password: '',
  password_confirmation: '',
})

watch(
  () => authStore.user,
  (user) => {
    if (user) {
      profileForm.name = user.name
      profileForm.email = user.email
      profileForm.phone = user.phone || ''
    }
  },
  { immediate: true }
)

const saveProfile = async () => {
  profileMessage.value = ''
  const response = await updateProfile(profileForm)
  authStore.user = response.data.user
  profileMessage.value = response.message
}

const savePassword = async () => {
  passwordMessage.value = ''
  passwordError.value = ''
  try {
    const response = await updatePassword(passwordForm)
    passwordMessage.value = response.message
    passwordForm.current_password = ''
    passwordForm.password = ''
    passwordForm.password_confirmation = ''
  } catch (err) {
    passwordError.value = err.response?.data?.message || 'Password update failed.'
  }
}
</script>
