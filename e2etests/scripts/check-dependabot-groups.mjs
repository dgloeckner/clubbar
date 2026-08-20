#!/usr/bin/env node
/**
 * Two direct dependencies that cannot move apart must be in one Dependabot
 * group, or Dependabot writes a lockfile that will not install.
 *
 * The failure this catches is not a failing test, it is a pull request with no
 * signal at all. `@testing-library/react@14` declares `"react": "^18.0.0"`;
 * react lived in the `react` group and testing-library in the `vite` group, so
 * the react 19 bump (#594) came alone and every npm job died before running a
 * line:
 *
 *     npm error ERESOLVE could not resolve
 *     npm error Found: react@19.2.8
 *     npm error peer react@"^18.0.0" from @testing-library/react@14.3.1
 *
 * Nothing in review fixes that. The bump testing-library needs was never
 * offered, so the branch is unmergeable until a human makes a second bump by
 * hand — which is the work Dependabot exists to do.
 *
 * The rule, then: **if A's peer range on B excludes B's next major, A and B
 * belong in the same group.** A wide peer range (`react: ">= 16.8.0"`,
 * recharts' `^16 || ^17 || ^18 || ^19` while react is 18) survives the bump on
 * its own and stays wherever it is; only a range that has run out of room
 * forces the grouping.
 *
 * Peer ranges come from the committed lockfiles, so this needs no network and
 * no install. Run by the `lint-e2e` job in seconds, next to the CI lane check.
 */

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const REPO = resolve(HERE, '..', '..')
const DEPENDABOT = resolve(REPO, '.github', 'dependabot.yml')

/**
 * Peer edges that are locked and still deliberately left ungrouped, keyed
 * `"<dependent> -> <dependency>"`, with the reason. An entry belongs here when
 * *not* grouping is the decision and someone has checked that the bump still
 * resolves — never to quiet this check.
 *
 * @type {Record<string, string>}
 */
export const UNGROUPED_PEERS = {}

// --- a very small YAML reader -----------------------------------------------
//
// Enough for this one file: block maps, block sequences, and quoted scalars.
// A real parser is not worth a dependency in a suite that has none, and the
// file it reads is ours.

/** @returns {any} the document as plain objects, arrays and strings. */
export function parseYaml(source) {
  const lines = source
    .split('\n')
    .map((line) => (line.includes('#') ? stripComment(line) : line))
    .filter((line) => line.trim() !== '')

  const [value] = parseBlock(lines, 0, indentOf(lines[0] ?? ''))

  return value
}

function stripComment(line) {
  // Only whole-line and trailing comments appear here; no '#' inside a scalar.
  const quoted = line.match(/^([^#"]*"[^"]*")*[^#"]*/)

  return quoted ? quoted[0].trimEnd() : line
}

function indentOf(line) {
  return line.length - line.trimStart().length
}

function scalar(raw) {
  const text = raw.trim()

  return text.startsWith('"') && text.endsWith('"') ? text.slice(1, -1) : text
}

/** @returns {[any, number]} the parsed value and the first line after it. */
function parseBlock(lines, start, indent) {
  if (lines[start]?.trimStart().startsWith('- ')) return parseSequence(lines, start, indent)

  return parseMap(lines, start, indent)
}

function parseSequence(lines, start, indent) {
  const items = []
  let i = start

  while (i < lines.length && indentOf(lines[i]) === indent && lines[i].trimStart().startsWith('- ')) {
    const rest = lines[i].trimStart().slice(2)
    const childIndent = indent + 2

    if (rest.includes(':') && !rest.trim().startsWith('"')) {
      // A mapping whose first key shares the dash's line.
      const inlined = [' '.repeat(childIndent) + rest, ...lines.slice(i + 1)]
      const [value, consumed] = parseMap(inlined, 0, childIndent)
      items.push(value)
      i += consumed
    } else {
      items.push(scalar(rest))
      i += 1
    }
  }

  return [items, i - start]
}

function parseMap(lines, start, indent) {
  const map = {}
  let i = start

  while (i < lines.length && indentOf(lines[i]) === indent && !lines[i].trimStart().startsWith('- ')) {
    const line = lines[i].trim()
    const split = line.indexOf(':')
    const key = scalar(line.slice(0, split))
    const inline = line.slice(split + 1).trim()

    if (inline !== '') {
      map[key] = scalar(inline)
      i += 1
      continue
    }

    const [value, consumed] = parseBlock(lines, i + 1, indentOf(lines[i + 1] ?? ''))
    map[key] = value
    i += 1 + consumed
  }

  return [map, i - start]
}

// --- the check ---------------------------------------------------------------

/** Every npm update entry in the config, with its directories and groups. */
export function npmEntries(config) {
  return (config.updates ?? [])
    .filter((entry) => entry['package-ecosystem'] === 'npm')
    .map((entry) => ({
      directories: entry.directories ?? [entry.directory],
      groups: Object.entries(entry.groups ?? {}).map(([name, group]) => ({
        name,
        patterns: group.patterns ?? [],
        exclude: group['exclude-patterns'] ?? [],
      })),
    }))
}

function matches(pattern, name) {
  const expression = pattern.split('*').map(escapeRegExp).join('.*')

  return new RegExp(`^${expression}$`).test(name)
}

function escapeRegExp(text) {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/** @returns {string|null} the group that claims `name`, catch-all included. */
export function groupOf(groups, name) {
  const claiming = groups.filter(
    (group) =>
      group.patterns.some((pattern) => matches(pattern, name)) &&
      !group.exclude.some((pattern) => matches(pattern, name)),
  )

  return claiming.length === 0 ? null : claiming[0].name
}

/**
 * Does `range` still admit a version above `major`? `^18.0.0` against react 18
 * does not — the next react is 19 and the range stops at 18. `>= 16.8.0` and
 * `*` do, and so does `^16 || ^17 || ^18 || ^19` while react is 18.
 */
export function peerRangeIsExhausted(range, major) {
  if (range === '*' || range === '' || range.includes('>=') || range.includes('>')) return false

  const majors = [...range.matchAll(/(\d+)(?:\.[\dx*]+)*/g)].map((match) => Number(match[1]))

  if (majors.length === 0) return false

  return Math.max(...majors) <= major
}

/** Direct dependencies of a manifest, with the peer ranges of the installed tree. */
export function directDependencies(manifest, lockfile) {
  const declared = { ...(manifest.dependencies ?? {}), ...(manifest.devDependencies ?? {}) }
  const installed = lockfile.packages ?? {}

  return Object.keys(declared).map((name) => {
    const entry = installed[`node_modules/${name}`] ?? {}

    return {
      name,
      version: entry.version ?? '',
      peers: entry.peerDependencies ?? {},
    }
  })
}

/**
 * @returns {string[]} one line per peer edge that pins two direct dependencies
 * to each other while Dependabot would offer them in separate pull requests.
 */
export function splitLockedPeers(directs, groups, exempt = UNGROUPED_PEERS) {
  const byName = new Map(directs.map((dependency) => [dependency.name, dependency]))
  const problems = []

  for (const dependent of directs) {
    for (const [peerName, range] of Object.entries(dependent.peers)) {
      const peer = byName.get(peerName)
      if (!peer) continue // Transitive: not something a group can move.

      const major = Number(peer.version.split('.')[0])
      if (!Number.isInteger(major)) continue
      if (!peerRangeIsExhausted(range, major)) continue
      if (`${dependent.name} -> ${peerName}` in exempt) continue

      const here = groupOf(groups, dependent.name)
      const there = groupOf(groups, peerName)
      if (here !== null && here === there) continue

      problems.push(
        `${dependent.name} peers ${peerName}@"${range}", which stops at ${peerName} ${major} — ` +
          `a ${peerName} ${major + 1} bump must carry ${dependent.name} with it, but ` +
          `${dependent.name} is in group "${here ?? '(none)'}" and ${peerName} in "${there ?? '(none)'}"`,
      )
    }
  }

  return problems
}

/**
 * The catch-all lists every other group's patterns by hand, so it can drift
 * out of step with them and silently swallow a package a group should own.
 *
 * @returns {string[]} one line per pattern on the wrong side of that list.
 */
export function catchAllDrift(groups) {
  const catchAll = groups.find((group) => group.patterns.includes('*'))
  if (!catchAll) return []

  const claimed = groups.filter((group) => group !== catchAll).flatMap((group) => group.patterns)
  const excluded = new Set(catchAll.exclude)
  const problems = []

  for (const pattern of claimed) {
    if (!excluded.has(pattern)) {
      problems.push(`"${pattern}" is claimed by a group but missing from ${catchAll.name}'s exclude-patterns`)
    }
  }

  for (const pattern of excluded) {
    if (!claimed.includes(pattern)) {
      problems.push(`"${pattern}" is excluded from ${catchAll.name} but claimed by no group`)
    }
  }

  return problems
}

function readJson(path) {
  return JSON.parse(readFileSync(path, 'utf8'))
}

function main() {
  const config = parseYaml(readFileSync(DEPENDABOT, 'utf8'))
  const problems = []
  let checked = 0

  for (const entry of npmEntries(config)) {
    for (const problem of catchAllDrift(entry.groups)) {
      problems.push(`[.github/dependabot.yml] ${problem}`)
    }

    for (const directory of entry.directories) {
      const root = resolve(REPO, `.${directory}`)
      const directs = directDependencies(
        readJson(resolve(root, 'package.json')),
        readJson(resolve(root, 'package-lock.json')),
      )
      checked += directs.length

      for (const problem of splitLockedPeers(directs, entry.groups)) {
        problems.push(`[${directory}] ${problem}`)
      }
    }
  }

  if (problems.length === 0) {
    console.log(`[dependabot groups] ${checked} direct dependencies checked; no locked peer is split across groups.`)
    return
  }

  console.error(
    `Dependabot would offer these updates in pull requests that cannot install:\n\n` +
      problems.map((problem) => `  - ${problem}`).join('\n') +
      `\n\nPut each pair in one group in .github/dependabot.yml, or record the pair in\n` +
      `UNGROUPED_PEERS in this file with the reason the split is safe.\n`,
  )
  process.exit(1)
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  main()
}
