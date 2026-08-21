#!/usr/bin/env node
/**
 * Renders the Sommerfest poster to print-ready A4 PDFs (plus PNG previews).
 *
 *   node artwork/poster/build-poster.mjs
 *
 * Two outputs from one source:
 *   clubbar-sommerfest-a4.pdf     full colour
 *   clubbar-sommerfest-a4-sw.pdf  greyscale, for monochrome laser printers
 *
 * The greyscale build is not a CSS filter over the colour one — a filter makes
 * Chromium rasterise the whole page and the text goes soft. Instead the poster
 * keeps every colour in a CSS custom property, `<html class="mono">` overrides
 * those properties with hand-picked greys, and the SVG logos are converted to
 * their luminance greys here. Both PDFs stay pure vector.
 *
 * Rendering goes through the DevTools protocol rather than the `--print-to-pdf`
 * command line flag, because only Page.printToPDF honours printBackground —
 * and this poster is almost entirely background.
 *
 * Logos are inlined at build time:
 *   artwork/frgs-adler.svg    club crest in the header. Optional — a plain
 *                             "FRGS" wordmark stands in while it is missing.
 *   artwork/clubbar-logo.svg  the Club Bar mark beside the title.
 */

import { existsSync, readFileSync, writeFileSync, mkdirSync, rmSync } from 'node:fs'
import { spawn } from 'node:child_process'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { tmpdir } from 'node:os'

const HERE = dirname(fileURLToPath(import.meta.url))
const ARTWORK = resolve(HERE, '..')

const SOURCE = join(HERE, 'clubbar-sommerfest-a4.html')
const BUILD_DIR = join(HERE, '.build')
const FONT_CSS = join(HERE, 'fonts', 'inter.css')
const CREST_SVG = join(ARTWORK, 'frgs-adler.svg')
const MARK_SVG = join(ARTWORK, 'clubbar-logo.svg')

const CHROMIUM = process.env.CHROMIUM_BIN || '/opt/pw-browsers/chromium'

const VARIANTS = [
  { id: 'colour', mono: false, out: 'clubbar-sommerfest-a4' },
  { id: 'mono', mono: true, out: 'clubbar-sommerfest-a4-sw' },
]

/* --------------------------------------------------------------- assets --- */

/** Strip the XML prolog so an SVG file can be inlined into HTML. */
function inlineSvg(path) {
  return readFileSync(path, 'utf8')
    .replace(/<\?xml[^>]*\?>/g, '')
    .replace(/<!DOCTYPE[^>]*>/gi, '')
    .trim()
}

/**
 * Both logos declare gradients under the same ids (bgGrad, accentGrad, …). In
 * one document the later definition silently wins and the first logo renders
 * with the other's colours, so give each copy its own id namespace.
 */
function namespaceIds(svg, prefix) {
  const ids = [...svg.matchAll(/\bid="([^"]+)"/g)].map((m) => m[1])
  for (const id of ids) {
    const safe = id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    svg = svg
      .replace(new RegExp(`\\bid="${safe}"`, 'g'), `id="${prefix}-${id}"`)
      .replace(new RegExp(`url\\(#${safe}\\)`, 'g'), `url(#${prefix}-${id})`)
      .replace(new RegExp(`\\b(xlink:href|href)="#${safe}"`, 'g'), `$1="#${prefix}-${id}"`)
  }
  return svg
}

/** Rewrite every hex colour in an SVG to its Rec. 709 luminance grey. */
function desaturateSvg(svg) {
  return svg.replace(/#([0-9a-f]{3}|[0-9a-f]{6})\b/gi, (_, hex) => {
    const full = hex.length === 3 ? [...hex].map((c) => c + c).join('') : hex
    const [r, g, b] = [0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16))
    const y = Math.round(0.2126 * r + 0.7152 * g + 0.0722 * b)
    return '#' + y.toString(16).padStart(2, '0').repeat(3)
  })
}

/**
 * Inline the webfont so the built page has no external references at all —
 * Chromium loads a file:// page with no network, and a missing @font-face
 * silently falls back to Arial without failing the build.
 */
function inlineFontCss() {
  let css = readFileSync(FONT_CSS, 'utf8')
  const files = new Set([...css.matchAll(/url\(([^)]+)\)/g)].map((m) => m[1].replace(/['"]/g, '')))
  for (const file of files) {
    const path = join(dirname(FONT_CSS), file)
    if (!existsSync(path)) throw new Error(`font file missing: ${path}`)
    const data = readFileSync(path).toString('base64')
    css = css.replaceAll(`url(${file})`, `url(data:font/woff2;base64,${data})`)
  }
  return css
}

function buildHtml(variant, fontCss, log) {
  let html = readFileSync(SOURCE, 'utf8')

  let crest = '<span class="crest-fallback">FRGS</span>'
  if (existsSync(CREST_SVG)) {
    crest = namespaceIds(inlineSvg(CREST_SVG), 'frgs')
    if (variant.mono) crest = desaturateSvg(crest)
  } else if (log) {
    console.log('  crest    : artwork/frgs-adler.svg missing — using the "FRGS" wordmark')
  }

  let mark = namespaceIds(inlineSvg(MARK_SVG), 'cb')
  if (variant.mono) mark = desaturateSvg(mark)

  html = html
    .replace('<link rel="stylesheet" href="fonts/inter.css">', `<style>\n${fontCss}\n</style>`)
    .replace('<!--FRGS_LOGO-->', crest)
    .replace('<!--CLUBBAR_LOGO-->', mark)

  if (variant.mono) html = html.replace('<html lang="de">', '<html lang="de" class="mono">')

  const path = join(BUILD_DIR, `${variant.out}.html`)
  writeFileSync(path, html)
  return path
}

/* ------------------------------------------------------------------ CDP --- */

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

function connect(url) {
  return new Promise((ok, fail) => {
    const ws = new WebSocket(url)
    ws.addEventListener('open', () => ok(ws), { once: true })
    ws.addEventListener('error', () => fail(new Error(`cannot connect to ${url}`)), { once: true })
  })
}

function makeSession(ws) {
  let seq = 0
  const pending = new Map()
  const seen = new Set()
  ws.addEventListener('message', (ev) => {
    const msg = JSON.parse(ev.data)
    if (msg.id && pending.has(msg.id)) {
      const { ok, fail } = pending.get(msg.id)
      pending.delete(msg.id)
      msg.error ? fail(new Error(msg.error.message)) : ok(msg.result)
    } else if (msg.method) {
      seen.add(msg.method)
    }
  })
  return {
    send: (method, params = {}, sessionId) =>
      new Promise((ok, fail) => {
        const id = ++seq
        pending.set(id, { ok, fail })
        ws.send(JSON.stringify({ id, method, params, sessionId }))
      }),
    loaded: () => seen.has('Page.loadEventFired'),
  }
}

async function waitForEndpoint(port, deadlineMs = 20000) {
  const until = Date.now() + deadlineMs
  while (Date.now() < until) {
    try {
      const res = await fetch(`http://127.0.0.1:${port}/json/version`)
      if (res.ok) return (await res.json()).webSocketDebuggerUrl
    } catch {
      /* not listening yet */
    }
    await sleep(150)
  }
  throw new Error('Chromium did not expose a DevTools endpoint in time')
}

function launchChromium(port) {
  const profile = join(tmpdir(), `clubbar-poster-${process.pid}`)
  const proc = spawn(
    CHROMIUM,
    [
      '--headless=new',
      '--disable-gpu',
      '--no-sandbox',
      '--hide-scrollbars',
      '--force-color-profile=srgb',
      '--font-render-hinting=none',
      `--remote-debugging-port=${port}`,
      `--user-data-dir=${profile}`,
      'about:blank',
    ],
    { stdio: 'ignore' },
  )
  return { proc, profile }
}

/**
 * The poster clips at exactly one A4 page (`.page` is overflow:hidden), so a
 * copy edit that grows the layout would silently cut the footer off rather
 * than produce a visible second page. Measure it and fail loudly instead.
 */
const MEASURE = `(() => {
  const page = document.querySelector('.page')
  return { overflow: page.scrollHeight - page.clientHeight, fonts: document.fonts.size }
})()`

async function render(send, sessionId, htmlPath, outBase) {
  await send('Page.navigate', { url: `file://${htmlPath}` }, sessionId)
  await sleep(400)
  await send('Runtime.evaluate',
    { expression: 'document.fonts.ready', awaitPromise: true }, sessionId)
  await sleep(200)

  const { result } = await send('Runtime.evaluate',
    { expression: MEASURE, returnByValue: true }, sessionId)

  const pdf = await send('Page.printToPDF', {
    printBackground: true,
    // Chromium quantises the MediaBox to ~0.96pt steps, so the exact A4 width
    // (595.276pt) is not reachable from either route — the two neighbouring
    // values are 594.96 and 595.92. Take the CSS @page size: it lands on
    // 594.96 x 841.92pt, a tenth of a millimetre *inside* A4 rather than
    // outside it, so nothing can fall off the sheet.
    preferCSSPageSize: true,
    paperWidth: 8.27,
    paperHeight: 11.69,
    marginTop: 0, marginBottom: 0, marginLeft: 0, marginRight: 0,
    scale: 1,
  }, sessionId)
  writeFileSync(join(HERE, `${outBase}.pdf`), Buffer.from(pdf.data, 'base64'))

  const png = await send('Page.captureScreenshot',
    { format: 'png', captureBeyondViewport: true }, sessionId)
  writeFileSync(join(HERE, `${outBase}.png`), Buffer.from(png.data, 'base64'))

  return result.value
}

/* ----------------------------------------------------------------- main --- */

if (!existsSync(CHROMIUM)) {
  throw new Error(`Chromium not found at ${CHROMIUM} — set CHROMIUM_BIN to override`)
}

console.log('Building the Clubbar Sommerfest poster (A4)')
mkdirSync(BUILD_DIR, { recursive: true })

const fontCss = inlineFontCss()
const port = 9500 + (process.pid % 400)
const { proc, profile } = launchChromium(port)
let problems = 0

try {
  const ws = await connect(await waitForEndpoint(port))
  const { send } = makeSession(ws)
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' })
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true })

  await send('Page.enable', {}, sessionId)
  await send('Emulation.setDeviceMetricsOverride',
    { width: 794, height: 1123, deviceScaleFactor: 2, mobile: false }, sessionId)

  for (const variant of VARIANTS) {
    const html = buildHtml(variant, fontCss, variant.id === 'colour')
    const { overflow, fonts } = await render(send, sessionId, html, variant.out)

    if (fonts === 0) {
      console.error(`  ${variant.out}: FAIL — no webfont loaded, the poster fell back to Arial`)
      problems++
    }
    if (overflow > 1) {
      console.error(`  ${variant.out}: FAIL — content overruns the page by ${overflow}px `
        + `(~${(overflow / 3.7795).toFixed(1)}mm); the footer is being clipped`)
      problems++
    } else {
      console.log(`  ${variant.out}.pdf + .png — one page, ${fonts} font faces`)
    }
  }

  ws.close()
} finally {
  proc.kill()
  // Chromium keeps writing to its profile for a moment after SIGTERM.
  await sleep(300)
  rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 })
}

if (problems > 0) process.exit(1)
console.log('Done.')
