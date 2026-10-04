import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { csrf } from './api/axios'

import 'bootstrap/dist/css/bootstrap.min.css'
import 'bootstrap-icons/font/bootstrap-icons.css'
import '../sass/app.scss'

import * as bootstrap from 'bootstrap'
window.bootstrap = bootstrap

const app = createApp(App)

app.use(createPinia())
app.use(router)

async function startApp() {
  await Promise.race([
    csrf().catch(() => {}),
    new Promise((resolve) => setTimeout(resolve, 4000)),
  ])

  app.mount('#app')

  await router.isReady().catch(() => {})

  if (router.currentRoute.value.matched.length === 0) {
    const base = router.options.history.base.replace(/\/$/, '')
    const path = window.location.pathname.replace(base, '') || '/'
    await router.replace({
      path,
      query: Object.fromEntries(new URLSearchParams(window.location.search)),
      hash: window.location.hash,
    }).catch(() => {})
  }
}

startApp()
