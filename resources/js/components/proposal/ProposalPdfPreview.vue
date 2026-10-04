<template>
  <div class="proposal-pdf-preview">
    <div v-if="loading" class="proposal-pdf-preview__state">
      <div class="spinner-border spinner-border-sm text-primary me-2"></div>
      Loading PDF preview...
    </div>
    <div v-else-if="error" class="proposal-pdf-preview__state text-danger">
      {{ error }}
    </div>
    <div
      v-show="!loading && !error && pageCount > 0"
      ref="pagesContainer"
      class="proposal-pdf-preview__pages"
    >
      <canvas
        v-for="pageNumber in pageCount"
        :key="pageNumber"
        :ref="(element) => setCanvasRef(element, pageNumber)"
        class="proposal-pdf-preview__page"
      ></canvas>
    </div>
  </div>
</template>

<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import * as pdfjsLib from 'pdfjs-dist'
import { getBaseUrl } from '@/utils/baseUrl'

pdfjsLib.GlobalWorkerOptions.workerSrc = `${getBaseUrl()}/vendor/pdfjs/pdf.worker.min.mjs`

const props = defineProps({
  url: {
    type: String,
    required: true,
  },
})

const loading = ref(false)
const error = ref('')
const pageCount = ref(0)
const pagesContainer = ref(null)
const canvasRefs = new Map()
let renderTaskId = 0

const setCanvasRef = (element, pageNumber) => {
  if (element) {
    canvasRefs.set(pageNumber, element)
    return
  }

  canvasRefs.delete(pageNumber)
}

const resetPreview = () => {
  pageCount.value = 0
  canvasRefs.clear()
  error.value = ''
}

const waitForCanvas = async (pageNumber, attempts = 8) => {
  for (let attempt = 0; attempt < attempts; attempt += 1) {
    const canvas = canvasRefs.get(pageNumber)
    if (canvas) {
      return canvas
    }

    await nextTick()
  }

  return null
}

const fetchPdfData = async (url) => {
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: {
      Accept: 'application/pdf',
    },
  })

  if (!response.ok) {
    throw new Error(`Failed to fetch PDF (${response.status})`)
  }

  return response.arrayBuffer()
}

const renderPdf = async (url) => {
  const taskId = ++renderTaskId
  loading.value = true
  error.value = ''
  pageCount.value = 0
  canvasRefs.clear()

  try {
    const data = await fetchPdfData(url)

    if (taskId !== renderTaskId) {
      return
    }

    const pdf = await pdfjsLib.getDocument({
      data,
      useWasm: false,
    }).promise

    if (taskId !== renderTaskId) {
      return
    }

    pageCount.value = pdf.numPages
    loading.value = false
    await nextTick()

    const containerWidth = pagesContainer.value?.clientWidth || 720
    const devicePixelRatio = window.devicePixelRatio || 1

    for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber += 1) {
      if (taskId !== renderTaskId) {
        return
      }

      const page = await pdf.getPage(pageNumber)
      const baseViewport = page.getViewport({ scale: 1 })
      const scale = Math.min(1.5, Math.max(0.75, (containerWidth - 24) / baseViewport.width))
      const viewport = page.getViewport({ scale })
      const canvas = await waitForCanvas(pageNumber)

      if (!canvas) {
        throw new Error(`Missing canvas for page ${pageNumber}`)
      }

      const context = canvas.getContext('2d')
      canvas.width = Math.floor(viewport.width * devicePixelRatio)
      canvas.height = Math.floor(viewport.height * devicePixelRatio)
      canvas.style.width = `${viewport.width}px`
      canvas.style.height = `${viewport.height}px`
      context.setTransform(devicePixelRatio, 0, 0, devicePixelRatio, 0, 0)

      await page.render({
        canvas,
        canvasContext: context,
        viewport,
      }).promise
    }
  } catch (previewError) {
    if (taskId === renderTaskId) {
      error.value = 'Unable to load the proposal PDF preview.'
      pageCount.value = 0
      canvasRefs.clear()
    }
  } finally {
    if (taskId === renderTaskId) {
      loading.value = false
    }
  }
}

watch(
  () => props.url,
  (url) => {
    if (!url) {
      renderTaskId += 1
      loading.value = false
      resetPreview()
      return
    }

    renderPdf(url)
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  renderTaskId += 1
})
</script>

<style scoped>
.proposal-pdf-preview {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 520px;
}

.proposal-pdf-preview__state {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 520px;
  color: #6e6b7b;
}

.proposal-pdf-preview__pages {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  max-height: 60vh;
  overflow: auto;
  padding: 0.25rem;
  border: 1px solid #ebe9f1;
  border-radius: 0.35rem;
  background: #f8f8f8;
}

.proposal-pdf-preview__page {
  display: block;
  margin: 0 auto;
  background: #fff;
  box-shadow: 0 1px 4px rgba(34, 41, 47, 0.08);
}
</style>
