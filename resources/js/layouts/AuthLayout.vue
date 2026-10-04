<template>
  <div class="auth-page">
    <div class="auth-page__body">
    <!-- Left panel: branding (hidden on mobile) -->
    <div class="auth-page__cover d-none d-lg-flex">
      <div class="auth-page__aurora" aria-hidden="true">
        <span class="auth-page__orb auth-page__orb--1"></span>
        <span class="auth-page__orb auth-page__orb--2"></span>
        <span class="auth-page__orb auth-page__orb--3"></span>
        <span class="auth-page__grid"></span>
      </div>

      <div class="auth-page__cover-content">
        <div class="auth-page__brands">
          <BrandBadge fallback="UOL" :src="uolLogoSrc" label="University of Lahore" />
          <span class="auth-page__brands-divider"></span>
          <BrandBadge fallback="FIT" :src="fitLogoSrc" label="Faculty of IT" />
        </div>

        <h2>Final Year Project<br />Management System</h2>
        <p>A modern, secure workspace for supervisors, evaluators and students to run the FYP lifecycle end to end.</p>

        <ul class="auth-page__features">
          <li><i class="bi bi-shield-check"></i> Role-based access control</li>
          <li><i class="bi bi-graph-up"></i> Real-time dashboard analytics</li>
          <li><i class="bi bi-phone"></i> Mobile, tablet &amp; desktop ready</li>
        </ul>
      </div>
    </div>

    <!-- Right panel: form -->
    <div class="auth-page__form-panel">
      <div class="auth-page__form-wrapper">
        <div class="auth-page__mobile-brand d-lg-none text-center mb-4">
          <div class="auth-page__mobile-brands">
            <BrandBadge fallback="UOL" :src="uolLogoSrc" label="University of Lahore" size="sm" />
            <BrandBadge fallback="FIT" :src="fitLogoSrc" label="Faculty of IT" size="sm" />
          </div>
          <h4 class="mb-0 mt-2">FYP Management System</h4>
        </div>

        <div class="auth-page__card">
          <div class="auth-page__header mb-4">
            <h3 class="auth-page__title">{{ title }}</h3>
            <p class="auth-page__subtitle">{{ subtitle }}</p>
          </div>

          <slot />
        </div>
      </div>
    </div>
    </div>

    <footer class="auth-page__footer">
      <span class="auth-page__footer-copyright">© {{ year }} FYP Management System</span>
      <DeveloperCredit />
    </footer>
  </div>
</template>

<script setup>
import BrandBadge from '@/components/ui/BrandBadge.vue'
import DeveloperCredit from '@/components/layout/DeveloperCredit.vue'
import { getBaseUrl } from '@/utils/baseUrl'

defineProps({
  title: { type: String, default: 'Welcome back 👋' },
  subtitle: { type: String, default: 'Please sign in to your account' },
})

const year = new Date().getFullYear()

// Drop the real files in at these paths and they'll appear automatically —
// no code change needed. Until then, BrandBadge shows a styled placeholder.
const uolLogoSrc = `${getBaseUrl()}/images/uol-logo.png`
const fitLogoSrc = `${getBaseUrl()}/images/fit-logo.png`
</script>

<style scoped>
.auth-page {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  --brand-navy: #0b1330;
  --brand-indigo: #1e2a63;
  --brand-teal: #14b8a6;
  --brand-gold: #eab676;
}

.auth-page__body {
  flex: 1;
  display: flex;
  min-height: 0;
}

.auth-page__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.5rem 1rem;
  padding: 0.625rem 1.5rem;
  border-top: 1px solid rgba(11, 19, 48, 0.08);
  background: #fff;
  color: #8a8fa3;
  font-size: 0.8125rem;
}

.auth-page__footer-copyright {
  white-space: nowrap;
}

.auth-page__cover {
  position: relative;
  flex: 1;
  overflow: hidden;
  background: linear-gradient(160deg, var(--brand-navy) 0%, var(--brand-indigo) 55%, #16204a 100%);
  align-items: center;
  justify-content: center;
  padding: 3rem;
  color: #fff;
}

.auth-page__aurora {
  position: absolute;
  inset: 0;
  overflow: hidden;
}

.auth-page__grid {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255, 255, 255, 0.05) 1px, transparent 1px);
  background-size: 44px 44px;
  mask-image: radial-gradient(ellipse at 30% 30%, black 0%, transparent 75%);
}

.auth-page__orb {
  position: absolute;
  border-radius: 50%;
  filter: blur(60px);
  opacity: 0.55;
  will-change: transform;
}

.auth-page__orb--1 {
  width: 420px;
  height: 420px;
  left: -120px;
  top: -80px;
  background: radial-gradient(circle at 30% 30%, var(--brand-teal), transparent 70%);
  animation: auth-drift-1 16s ease-in-out infinite;
}

.auth-page__orb--2 {
  width: 360px;
  height: 360px;
  right: -100px;
  bottom: -60px;
  background: radial-gradient(circle at 60% 40%, var(--brand-gold), transparent 70%);
  animation: auth-drift-2 20s ease-in-out infinite;
}

.auth-page__orb--3 {
  width: 300px;
  height: 300px;
  right: 15%;
  top: 10%;
  background: radial-gradient(circle at 50% 50%, #6d8cff, transparent 70%);
  opacity: 0.35;
  animation: auth-drift-3 24s ease-in-out infinite;
}

@keyframes auth-drift-1 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  50% { transform: translate(40px, 30px) scale(1.08); }
}

@keyframes auth-drift-2 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  50% { transform: translate(-30px, -40px) scale(1.05); }
}

@keyframes auth-drift-3 {
  0%, 100% { transform: translate(0, 0); }
  50% { transform: translate(-25px, 25px); }
}

.auth-page__cover-content {
  position: relative;
  z-index: 1;
  max-width: 440px;
}

.auth-page__brands {
  display: flex;
  align-items: center;
  gap: 0.875rem;
  margin-bottom: 2rem;
}

.auth-page__brands-divider {
  width: 1px;
  height: 32px;
  background: rgba(255, 255, 255, 0.25);
}

.auth-page__cover h2 {
  color: #fff;
  font-size: 1.85rem;
  font-weight: 700;
  line-height: 1.25;
  margin-bottom: 1rem;
}

.auth-page__cover p {
  opacity: 0.85;
  line-height: 1.6;
  margin-bottom: 2rem;
}

.auth-page__features {
  list-style: none;
  padding: 0;
  margin: 0;
}

.auth-page__features li {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.875rem;
  font-size: 0.9375rem;
}

.auth-page__features i {
  font-size: 1.125rem;
  color: var(--brand-teal);
}

.auth-page__form-panel {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem 1.5rem;
  background:
    radial-gradient(ellipse at top right, rgba(20, 184, 166, 0.06), transparent 55%),
    #f7f8fb;
}

.auth-page__form-wrapper {
  width: 100%;
  max-width: 420px;
}

.auth-page__mobile-brands {
  display: flex;
  justify-content: center;
  gap: 0.75rem;
}

.auth-page__card {
  background: #fff;
  border-radius: 1.1rem;
  padding: 2rem 1.85rem;
  box-shadow: 0 20px 45px -20px rgba(11, 19, 48, 0.25);
  border: 1px solid rgba(11, 19, 48, 0.06);
}

.auth-page__title {
  font-size: 1.4rem;
  font-weight: 700;
  color: var(--brand-navy);
  margin-bottom: 0.375rem;
}

.auth-page__subtitle {
  color: #8a8fa3;
  margin: 0;
  font-size: 0.9375rem;
}

@media (min-width: 992px) {
  .auth-page__form-panel {
    flex: 0 0 500px;
    max-width: 500px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .auth-page__orb {
    animation: none;
  }
}
</style>
