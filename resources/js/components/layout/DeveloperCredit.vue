<template>
  <div class="developer-credit">
    <a
      :href="profileUrl"
      target="_blank"
      rel="noopener noreferrer"
      class="developer-credit__link"
    >
      Developed by Muhammad Salman Ali
    </a>
    <span class="developer-credit__divider"></span>
    <img :src="uolLogoSrc" alt="University of Lahore" class="developer-credit__logo" />
    <img :src="fitLogoSrc" alt="Faculty of Information Technology" class="developer-credit__logo" />
  </div>
</template>

<script setup>
import { getBaseUrl } from '@/utils/baseUrl'

const profileUrl = 'https://faculty.uol.edu.pk/Faculty/17011/Mr-Muhammad-Salman-Ali'
const uolLogoSrc = `${getBaseUrl()}/images/uol-logo.png`
const fitLogoSrc = `${getBaseUrl()}/images/fit-logo.png`
</script>

<style scoped>
.developer-credit {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.developer-credit__link {
  color: inherit;
  text-decoration: none;
  font-size: 0.8125rem;
}

.developer-credit__link:hover {
  text-decoration: underline;
}

.developer-credit__divider {
  width: 1px;
  height: 14px;
  background: currentColor;
  opacity: 0.25;
}

.developer-credit__logo {
  height: 18px;
  width: auto;
  object-fit: contain;
  border-radius: 2px;
}
</style>
