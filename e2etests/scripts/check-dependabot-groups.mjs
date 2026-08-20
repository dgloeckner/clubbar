#!/usr/bin/env node
/**
 * Dependabot must never offer a bump whose companion bump it was not asked to
 * make. This reads the manifests and the committed lockfiles and says so in
 * two tenses.
 *
 * **Now.** `@testing-library/react@14` declares `"react": "^18.0.0"`; react
 * lived in the `react` group and testing-library in the `vite` group, so the
 * react 19 bump (#594) came alone and every npm job died before running a
 * line:
 *
 *     npm error ERESOLVE could not resolve
 *     npm error Found: react@19.2.8
 *     npm error peer react@"^18.0.0" from @testing-library/react@14.3.1
 *
 * Nothing in review fixes that: the testing-library bump that would resolve it
 * was never on the branch. `unresolvablePeers` finds that tree in a second,
 * off a checkout, and names the companion — where `npm ci` reports it four
 * jobs and eight minutes later.
 *
 * **Next major.** A peer range that has run out of room — `^18.0.0` while
 * react is 18 — is #594 waiting for its release date, so `splitLockedPeers`
 * insists the pair be grouped *before* that happens. Ranges with room to spare
 * (`react: ">= 16.8.0"`) are left where they are.
 *
 * That second tense is a forecast, and forecasts must be arguable: a pair can
 * be left split by recording it in UNGROUPED_PEERS with a reason. Nothing
 * silences the first tense, which is a fact about the tree in front of it.
 *
 * **The runtime.** npm treats `engines` as advice, so a dependency that has
 * outgrown the Node CI runs installs perfectly and then fails somewhere with
 * no mention of a version: jsdom 30 needs Node ^22.22.2 and, under Node 20,
 * killed every vitest worker with `webidl.util.markAsUncloneable is not a
 * function` (#619). `engineViolations` compares the engines of the installed
 * tree against the Node the workflow sets up, and says the version out loud.
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
const WORKFLOW = resolve(REPO, '.github', 'workflows', 'build.yaml')

/**
 * Peer edges whose range has run out of room and that stay split anyway, keyed
 * `"<dependent> -> <dependency>"`, with the reason. An entry belongs here when
 * leaving the pair split is the decision — never to quiet a tree that is
 * already broken, which `unresolvablePeers` reports and this cannot exempt.
 *
 * @type {Record<string, string>}
 */
export const UNGROUPED_PEERS = {
  'i18next -> typescript':
    'typescript is a type-support peer here, not a runtime one, and grouping is the wrong ' +
    'instrument: the typescript group is two tooling packages, and pulling the i18n runtime ' +
    'into it would put i18next releases in every weekly compiler bump. typescript 8 does not ' +
    'exist yet; when it does, whichever lands first — a wider i18next range or the compiler — ' +
    'unresolvablePeers reports the pair by name before npm ci does.',
  'react-i18next -> typescript': 'Same peer, same package family — see i18next -> typescript.',
}

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
      engines: entry.engines?.node,
    }
  })
}

/** Every version the lockfile installs, keyed by its path in node_modules. */
export function installedVersions(lockfile) {
  return Object.fromEntries(
    Object.entries(lockfile.packages ?? {})
      .filter(([path, entry]) => path.startsWith('node_modules/') && entry.version)
      .map(([path, entry]) => [path, entry.version]),
  )
}

// --- semver, only as far as a peer range goes --------------------------------
//
// Peer ranges in the wild are unions of carets, tildes and bounds — `^18.0.0`,
// `>= 16.8.0`, `^5 || ^6 || ^7`, `4.1.11`, `*`. That is the whole grammar this
// needs, and it is smaller than the dependency it would otherwise cost.

function parseVersion(text) {
  const parts = String(text).split('-')[0].split('.')

  return [0, 1, 2].map((index) => Number.parseInt(parts[index] ?? '0', 10) || 0)
}

function compare(left, right) {
  for (let index = 0; index < 3; index += 1) {
    if (left[index] !== right[index]) return left[index] < right[index] ? -1 : 1
  }

  return 0
}

function satisfiesComparator(version, comparator) {
  const text = comparator.trim()
  if (text === '' || text === '*' || text.toLowerCase() === 'x') return true

  const parsed = text.match(/^(\^|~|>=|<=|>|<|=)?\s*v?(\d+)(?:\.(\d+|[xX*]))?(?:\.(\d+|[xX*]))?/)
  if (!parsed) return true // Unparsed: never invent a failure out of a grammar we do not read.

  const [, operator = '=', major, minor, patch] = parsed
  const open = (part) => part === undefined || part === 'x' || part === 'X' || part === '*'
  const target = [Number(major), open(minor) ? 0 : Number(minor), open(patch) ? 0 : Number(patch)]
  const order = compare(parseVersion(version), target)

  switch (operator) {
    case '>=':
      return order >= 0
    case '>':
      return order > 0
    case '<=':
      return order <= 0
    case '<':
      return order < 0
    case '^': {
      // Caret pins the leftmost non-zero: ^0.2.3 stops at 0.3.0.
      const ceiling =
        target[0] !== 0 ? [target[0] + 1, 0, 0] : target[1] !== 0 ? [0, target[1] + 1, 0] : [0, 0, target[2] + 1]

      return order >= 0 && compare(parseVersion(version), ceiling) < 0
    }
    case '~':
      return order >= 0 && compare(parseVersion(version), [target[0], target[1] + 1, 0]) < 0
    default: {
      // A bare version is as precise as it is written: "18" means any 18.
      const installed = parseVersion(version)
      if (installed[0] !== target[0]) return false
      if (!open(minor) && installed[1] !== target[1]) return false

      return open(patch) || installed[2] === target[2]
    }
  }
}

/** Does `version` satisfy `range`? Unions of `||`, each a run of comparators. */
export function satisfies(version, range) {
  return String(range)
    .split('||')
    .some((group) =>
      group
        // `>= 16.8.0` is one comparator with a space in it, not two.
        .replace(/(>=|<=|>|<|=|\^|~)\s+/g, '$1')
        .trim()
        .split(/\s+/)
        .every((comparator) => satisfiesComparator(version, comparator)),
    )
}

/**
 * @returns {string[]} one line per peer range the committed tree already
 * violates — the lockfile `npm ci` will refuse, with the companion bump that
 * is missing from it named.
 */
export function unresolvablePeers(directs, installed) {
  const problems = []

  for (const dependent of directs) {
    for (const [peerName, range] of Object.entries(dependent.peers)) {
      // A nested copy is a resolution npm made on purpose; read that one.
      const version =
        installed[`node_modules/${dependent.name}/node_modules/${peerName}`] ?? installed[`node_modules/${peerName}`]

      if (version === undefined) continue // Absent: an optional peer nobody installed.
      if (satisfies(version, range)) continue

      problems.push(
        `${dependent.name}@${dependent.version} peers ${peerName}@"${range}", but the lockfile ` +
          `installs ${peerName} ${version} — npm ci refuses this tree. It needs a ${dependent.name} ` +
          `release that accepts ${peerName} ${version}, in the same pull request`,
      )
    }
  }

  return problems
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
 * Every Node version the build workflow sets up, resolved the way
 * `actions/setup-node` resolves it: a bare major means the newest release of
 * that major, so `'22'` reads as "any 22.x that exists".
 *
 * @returns {string[]} one version per distinct `node-version:` in the file.
 */
export function ciNodeVersions(workflowSource) {
  const declared = new Set()
  const literal = [...workflowSource.matchAll(/node-version:\s*'([\d.]+)'/g)].map((match) => match[1])
  const viaEnv = /node-version:\s*\$\{\{\s*env\.([A-Z_]+)\s*\}\}/.test(workflowSource)

  for (const version of literal) declared.add(version)

  if (viaEnv) {
    for (const [, name] of workflowSource.matchAll(/node-version:\s*\$\{\{\s*env\.([A-Z_]+)\s*\}\}/g)) {
      const assigned = workflowSource.match(new RegExp(`${name}:\\s*'([\\d.]+)'`))
      if (assigned) declared.add(assigned[1])
    }
  }

  return [...declared].map((version) => (version.includes('.') ? version : `${version}.9999.9999`))
}

/**
 * @returns {string[]} one line per direct dependency whose `engines.node`
 * excludes a Node version CI runs. npm only warns about this — and only on
 * install, where nobody reads it.
 */
export function engineViolations(directs, nodeVersions) {
  const problems = []

  for (const dependency of directs) {
    const required = dependency.engines
    if (!required) continue

    for (const node of nodeVersions) {
      if (satisfies(node, required)) continue

      problems.push(
        `${dependency.name}@${dependency.version} needs Node "${required}", and CI runs ` +
          `${node.replace('.9999.9999', '.x')} — it installs and then fails at run time`,
      )
    }
  }

  return problems
}

/**
 * An exemption outlives the edge it was written for: the dependency is dropped
 * or a release stops declaring that peer, and the reason stays behind as
 * folklore.
 *
 * An entry for a pair that is not locked *yet* is not stale — writing the
 * decision down before the range runs out is the point of the list.
 *
 * @returns {string[]} one line per exemption whose peer edge is gone.
 */
export function staleExemptions(directs, exempt = UNGROUPED_PEERS) {
  const byName = new Map(directs.map((dependency) => [dependency.name, dependency]))

  return Object.keys(exempt).filter((key) => {
    const [dependentName, peerName] = key.split(' -> ')
    const dependent = byName.get(dependentName)

    // Absent here may mean present in the entry's other directory; only a
    // dependent this manifest does declare can prove the edge is gone.
    return dependent !== undefined && dependent.peers[peerName] === undefined
  })
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
  const broken = []
  const forecast = []
  let checked = 0

  for (const entry of npmEntries(config)) {
    for (const problem of catchAllDrift(entry.groups)) {
      forecast.push(`[.github/dependabot.yml] ${problem}`)
    }

    for (const directory of entry.directories) {
      const root = resolve(REPO, `.${directory}`)
      const lockfile = readJson(resolve(root, 'package-lock.json'))
      const directs = directDependencies(readJson(resolve(root, 'package.json')), lockfile)
      checked += directs.length

      for (const problem of unresolvablePeers(directs, installedVersions(lockfile))) {
        broken.push(`[${directory}] ${problem}`)
      }

      for (const problem of engineViolations(directs, ciNodeVersions(readFileSync(WORKFLOW, 'utf8')))) {
        broken.push(`[${directory}] ${problem}`)
      }

      for (const problem of splitLockedPeers(directs, entry.groups)) {
        forecast.push(`[${directory}] ${problem}`)
      }

      for (const key of staleExemptions(directs)) {
        forecast.push(
          `[${directory}] UNGROUPED_PEERS still exempts "${key}", but that pair is no longer ` +
            `locked — delete the entry`,
        )
      }
    }
  }

  if (broken.length > 0) {
    console.error(
      `This tree will not run here — a dependency outgrew the rest of the branch (#594, #619):\n\n` +
        broken.map((problem) => `  - ${problem}`).join('\n') +
        `\n\nGroup the packages in .github/dependabot.yml so the next attempt carries both — or,\n` +
        `for a Node floor, raise NODE_VERSION in .github/workflows/build.yaml — and ask\n` +
        `Dependabot to recreate this pull request.\n`,
    )
  }

  if (forecast.length > 0) {
    console.error(
      `${broken.length > 0 ? '\n' : ''}These pairs can only move together, and Dependabot would move them apart:\n\n` +
        forecast.map((problem) => `  - ${problem}`).join('\n') +
        `\n\nPut each pair in one group in .github/dependabot.yml, or record it in UNGROUPED_PEERS\n` +
        `in this file with the reason the split is worth keeping.\n`,
    )
  }

  if (broken.length > 0 || forecast.length > 0) process.exit(1)

  const node = ciNodeVersions(readFileSync(WORKFLOW, 'utf8'))
    .map((version) => version.replace('.9999.9999', '.x'))
    .join(', ')

  console.log(
    `[dependabot groups] ${checked} direct dependencies checked; every peer range resolves, ` +
      `every engines floor fits Node ${node}, and no locked pair is split across groups.`,
  )
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  main()
}
