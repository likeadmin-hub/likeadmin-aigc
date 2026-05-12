import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const root = path.join(__dirname, '..')
const vuePath = path.join(root, 'components', 'site-login-modal.vue')
const outPath = path.join(root, 'assets', 'styles', 'pc-site-login-modal.scss')

const s = fs.readFileSync(vuePath, 'utf8')
const start = s.indexOf('<style lang="scss" scoped>')
const end = s.indexOf('</style>', start)
if (start < 0 || end < 0) throw new Error('style not found')

let css = s.slice(start + '<style lang="scss" scoped>'.length, end)
css = css.replace(':deep(svg)', 'svg')
fs.writeFileSync(outPath, css.trim() + '\n', 'utf8')
console.log('written', outPath)
