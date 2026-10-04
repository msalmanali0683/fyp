import { copyFileSync, mkdirSync } from 'fs'
import { dirname, join } from 'path'
import { fileURLToPath } from 'url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const source = join(root, 'node_modules', 'pdfjs-dist', 'build', 'pdf.worker.min.mjs')
const targetDir = join(root, 'public', 'vendor', 'pdfjs')
const target = join(targetDir, 'pdf.worker.min.mjs')

mkdirSync(targetDir, { recursive: true })
copyFileSync(source, target)

console.log(`Copied PDF.js worker to ${target}`)
