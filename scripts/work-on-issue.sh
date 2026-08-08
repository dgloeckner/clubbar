#!/usr/bin/env bash
#
# work-on-issue.sh — pick an unblocked GitHub issue by priority and hand it to Claude Code.
#
# Only issues labelled "ready-for-agent" are ever considered: that label is the
# triage gate saying a ticket is fully specified and safe to hand to an
# unattended agent. Anything still in triage, or needing a human, is skipped.
# (-n bypasses discovery entirely, so it can still target any issue by number.)
#
# "Unblocked" means: open, no open blocked-by dependency, and (unless -A) no assignee.
#
# Candidates are ranked by impact (see IMPACT SCORE below) and the highest-value
# one is offered first; -1 takes it without prompting.
#
# Before launching anything the script checks that the implementation skill is
# installed, that the working tree is clean, that HEAD is on main (offering to
# switch), and that main fast-forwards to origin. Any failure exits non-zero.
# Set MAIN_BRANCH to use a name other than "main".
#
# Usage:
#   scripts/work-on-issue.sh [-p PRIORITY] [-l LABEL] [-n NUMBER] [-1] [-A] [-C] [-d] [PRIORITY]
#
#   -p PRIORITY  priority label(s), comma-separated: critical|high|medium|low  (default: high)
#   -l LABEL     extra label filter, repeatable (e.g. -l terminal-frontend -l bug)
#   -n NUMBER    skip the picker and use this issue number directly
#   -1           auto-pick the highest-impact candidate, no prompt
#   -A           include issues that already have an assignee
#   -C           do not claim the issue (skip `--add-assignee @me`)
#   -d           dry run: print the ranked list and the prompt, do not launch Claude
#   -h           show this help
#
# IMPACT SCORE (highest first; ties broken by lower issue number, i.e. older):
#   priority   critical 400 | high 300 | medium 200 | low 100
#   type       bug +60 | enhancement +25 | tech-debt +5 | documentation +0
#   area       accessibility +15 | ux +15 | terminal-frontend +10 | i18n +5
#   traction   reactions x8 + comments x4 (capped at +60)
#   unblocks   open dependents x25 (capped at +100)
#
# The "unblocks" term makes gating work float. A schema migration that seven
# issues wait on carries no bug label and would otherwise rank below a leaf
# frontend bug — correct by priority, wrong by consequence, since taking it
# first is what lets everything else start. Capped at one priority tier so it
# reorders within a tier without ever outranking severity. The count is read
# from the same API response as the blocker check, so it costs no extra call.
#
set -euo pipefail

PRIORITY="high"
EXTRA_LABELS=()

# The triage gate. Every discovered candidate must carry this label; it is what
# distinguishes "someone wrote this down" from "this is specified well enough to
# hand to an agent that no one is watching". Not overridable by flag on purpose —
# use -n to work a specific issue that has not been through triage.
REQUIRED_LABEL="ready-for-agent"
ISSUE_NUM=""
INCLUDE_ASSIGNED=0
CLAIM=1
DRY_RUN=0
AUTO_PICK=0

usage() { awk 'NR>1 && /^#/ {sub(/^# ?/, ""); print; next} NR>1 {exit}' "$0"; exit "${1:-0}"; }

while getopts ":p:l:n:1ACdh" opt; do
  case "$opt" in
    p) PRIORITY="$OPTARG" ;;
    l) EXTRA_LABELS+=("$OPTARG") ;;
    n) ISSUE_NUM="$OPTARG" ;;
    1) AUTO_PICK=1 ;;
    A) INCLUDE_ASSIGNED=1 ;;
    C) CLAIM=0 ;;
    d) DRY_RUN=1 ;;
    h) usage 0 ;;
    \?) echo "unknown option: -$OPTARG" >&2; usage 1 ;;
    :) echo "option -$OPTARG needs a value" >&2; usage 1 ;;
  esac
done
shift $((OPTIND - 1))
[ $# -gt 0 ] && PRIORITY="$1"

command -v gh >/dev/null || { echo "gh CLI not found" >&2; exit 1; }
command -v jq >/dev/null || { echo "jq not found" >&2; exit 1; }

# ---------------------------------------------------------------------------
# The implementation skill the prompt hands the work to.
#
# Checked here, before anything else happens, because a session told to use a
# skill that isn't installed will reasonably conclude it is missing and quietly
# substitute the nearest match. Fail loudly at launch instead of discovering it
# in the diff.
# ---------------------------------------------------------------------------
SKILL="mattpocock-skills:tdd"
SKILL_PLUGIN="${SKILL%%:*}"
SKILL_NAME="${SKILL##*:}"

skill_installed() {
  # Plugin skills: ~/.claude/plugins/cache/<marketplace>/<plugin>/<ver>/skills/**/<name>/SKILL.md
  find "$HOME/.claude/plugins" \
       -type f -path "*/$SKILL_PLUGIN/*/skills/*/$SKILL_NAME/SKILL.md" \
       -print -quit 2>/dev/null | grep -q . && return 0
  # Plain skills: personal or project-local
  [ -f "$HOME/.claude/skills/$SKILL_NAME/SKILL.md" ] && return 0
  [ -f ".claude/skills/$SKILL_NAME/SKILL.md" ] && return 0
  return 1
}

if ! skill_installed; then
  echo "Required skill '/$SKILL' is not installed." >&2
  echo "Install it with:  claude plugin install $SKILL_PLUGIN" >&2
  echo "(Refusing to launch: the session would silently fall back to another skill.)" >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# Git preflight: start every issue from a clean, current main.
#
# The session branches off whatever HEAD happens to be. Starting from a feature
# branch quietly makes the new work a child of the old, and starting from a
# stale main puts a rebase between you and review. Both are cheap to prevent
# here and tedious to unpick later.
#
# Under -d this reports what it would decide and touches nothing — no checkout,
# no fetch, no merge. That makes the preflight inspectable without launching a
# session, which is the only safe way to test it.
# ---------------------------------------------------------------------------
MAIN_BRANCH="${MAIN_BRANCH:-main}"

git_preflight() {
  git rev-parse --git-dir >/dev/null 2>&1 || { echo "Not a git repository." >&2; exit 1; }

  if [ "$DRY_RUN" -eq 1 ]; then
    local d_current
    d_current="$(git rev-parse --abbrev-ref HEAD)"
    if ! git diff --quiet || ! git diff --cached --quiet; then
      echo "preflight: working tree is dirty — would stop" >&2
    elif [ "$d_current" != "$MAIN_BRANCH" ]; then
      echo "preflight: on '$d_current', not '$MAIN_BRANCH' — would offer to switch" >&2
    else
      echo "preflight: clean tree on $MAIN_BRANCH — would fetch and fast-forward" >&2
    fi
    return 0
  fi

  # Tracked changes only: this repo always carries untracked scratch dirs, and
  # untracked files survive a branch switch untouched anyway.
  if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "Working tree has uncommitted changes:" >&2
    git status --short --untracked-files=no >&2
    echo >&2
    echo "Commit or stash them before starting a new issue." >&2
    exit 1
  fi

  local current
  current="$(git rev-parse --abbrev-ref HEAD)"
  if [ "$current" != "$MAIN_BRANCH" ]; then
    if [ "$AUTO_PICK" -eq 1 ] || [ ! -t 0 ]; then
      echo "On '$current', not '$MAIN_BRANCH'. Switch before running unattended." >&2
      exit 1
    fi
    local answer
    read -r -p "On '$current', not '$MAIN_BRANCH'. Switch to $MAIN_BRANCH? [y/N] " answer
    case "$answer" in
      y|Y) git checkout "$MAIN_BRANCH" >&2 || exit 1 ;;
      *) echo "Aborted — issue work starts from $MAIN_BRANCH." >&2; exit 1 ;;
    esac
  fi

  echo "Fetching origin/${MAIN_BRANCH}…" >&2
  git fetch origin "$MAIN_BRANCH" >&2 || { echo "Could not fetch origin/$MAIN_BRANCH." >&2; exit 1; }

  # Fast-forward only. A local main carrying commits origin doesn't have is a
  # mistake worth stopping on, not one to merge past.
  if ! git merge-base --is-ancestor HEAD "origin/$MAIN_BRANCH"; then
    echo "Local $MAIN_BRANCH has commits that are not on origin/$MAIN_BRANCH." >&2
    echo "Push or reset them before starting a new issue." >&2
    exit 1
  fi
  git merge --ff-only "origin/$MAIN_BRANCH" >&2 || exit 1
}

git_preflight

# Resolve the repo from the git remote rather than gh's default, so renamed-repo
# redirects don't send label queries to the stale name.
REPO="$(git remote get-url origin 2>/dev/null \
  | sed -E 's#^git@github\.com:#https://github.com/#; s#\.git$##; s#^https://github\.com/##')"
[ -n "$REPO" ] || { echo "could not determine GitHub repo from 'origin' remote" >&2; exit 1; }

PRIORITY_LABELS_JSON="$(printf '%s' "$PRIORITY" | tr ',' '\n' \
  | sed -E 's/^[[:space:]]*//; s/[[:space:]]*$//; /^$/d; s/^/priority: /' | jq -R . | jq -s .)"

echo "Repo:     $REPO" >&2

if [ -n "$ISSUE_NUM" ]; then
  # Explicit issue number: skip discovery entirely.
  ISSUE_TITLE="$(gh issue view "$ISSUE_NUM" --repo "$REPO" --json title --jq .title)"
else
  echo "Priority: $(jq -r 'join(" | ")' <<<"$PRIORITY_LABELS_JSON")" >&2
  echo "Required: $REQUIRED_LABEL" >&2
  [ ${#EXTRA_LABELS[@]} -gt 0 ] && echo "Labels:   ${EXTRA_LABELS[*]}" >&2

  # -------------------------------------------------------------------------
  # 1. Candidate issues: open + matching labels (filtered client-side; the
  #    server-side label filter is unreliable across the repo rename redirect).
  #    An issue matches if it carries ANY of the priority labels and ALL of the
  #    required labels — the triage gate plus any -l filters.
  # -------------------------------------------------------------------------
  required_labels_json="$(printf '%s\n' "$REQUIRED_LABEL" "${EXTRA_LABELS[@]+"${EXTRA_LABELS[@]}"}" \
    | jq -R . | jq -s 'map(select(. != ""))')"

  candidates="$(gh issue list --repo "$REPO" --state open --limit 200 \
    --json number,title,labels,assignees,comments,reactionGroups \
    --jq "map(select(([.labels[].name]) as \$have
                     | ($PRIORITY_LABELS_JSON | any(. as \$l | \$have | index(\$l)))
                       and ($required_labels_json | all(. as \$l | \$have | index(\$l)))))")"

  labelled="$(jq 'length' <<<"$candidates")"
  [ "$labelled" -eq 0 ] && {
    echo "No open issues are labelled '$REQUIRED_LABEL' with those labels." >&2
    echo "Triage some first, or use -n NUMBER to work a specific issue." >&2
    exit 2
  }

  if [ "$INCLUDE_ASSIGNED" -eq 0 ]; then
    candidates="$(jq 'map(select((.assignees | length) == 0))' <<<"$candidates")"
  fi

  count="$(jq 'length' <<<"$candidates")"
  [ "$count" -eq 0 ] && {
    echo "All $labelled matching issue(s) already have an assignee (use -A to include them)." >&2
    exit 2
  }

  # -------------------------------------------------------------------------
  # 2. Drop issues with open blockers, and record how many issues each one
  #    unblocks (GitHub native issue dependencies).
  #
  #    Both numbers come from the same response, so the dependents count is
  #    free — no extra request beyond the blocker check we already make.
  # -------------------------------------------------------------------------
  echo "Checking blockers on $count issue(s)…" >&2
  unblocked_pairs="$(jq -r '.[].number' <<<"$candidates" \
    | xargs -P 8 -I{} gh api "repos/$REPO/issues/{}" \
        --jq '.issue_dependencies_summary as $d
              | select(($d.blocked_by // 0) == 0)
              | "\(.number)\t\($d.blocking // 0)"' \
    | sort -n)"

  [ -n "$unblocked_pairs" ] || { echo "All matching issues are blocked by open issues." >&2; exit 2; }

  # number -> open-dependent count, as a JSON object keyed by issue number
  unblocking_json="$(printf '%s\n' "$unblocked_pairs" \
    | jq -R -s 'split("\n") | map(select(length > 0) | split("\t")
                | {key: .[0], value: (.[1] | tonumber)}) | from_entries')"
  unblocked="$(cut -f1 <<<"$unblocked_pairs")"

  # -------------------------------------------------------------------------
  # 3. Rank by impact. Highest score first; ties go to the lower (older) number.
  # -------------------------------------------------------------------------
  rows="$(jq -r --argjson nums "$(printf '%s\n' "$unblocked" | jq -R 'tonumber' | jq -s .)" \
             --argjson unblocking "$unblocking_json" '
    def weight($labels; $table):
      [$table | to_entries[] as $e | select($labels | index($e.key)) | $e.value] | add // 0;
    map(select(.number as $n | $nums | index($n)))
    | map(. + {score: (
        ([.labels[].name]) as $l
        | ([{"priority: critical":400,"priority: high":300,"priority: medium":200,"priority: low":100}
            | to_entries[] as $e | select($l | index($e.key)) | $e.value] | max // 150)
          + weight($l; {"bug":60,"enhancement":25,"tech-debt":5,"documentation":0})
          + weight($l; {"accessibility":15,"ux":15,"terminal-frontend":10,"i18n":5})
          + ([([.reactionGroups[]?.users.totalCount] | add // 0) * 8
              + ((.comments | length) * 4), 60] | min)
          + ([(($unblocking[.number | tostring] // 0) * 25), 100] | min)
      )})
    | sort_by(-.score, .number)
    | .[] | "\(.number)\t\(.title)\t\([.labels[].name] | join(", "))\t\(.score)\t\($unblocking[.number | tostring] // 0)"' <<<"$candidates")"

  top_num="$(printf '%s\n' "$rows" | head -1 | cut -f1)"
  top_title="$(printf '%s\n' "$rows" | head -1 | cut -f2)"

  echo >&2
  echo "Unblocked candidates, highest impact first:" >&2
  printf '%s\n' "$rows" \
    | awk -F'\t' 'NR==1{m="  ->"} NR>1{m="    "}
                  {u = ($5 > 0) ? sprintf("  ->  unblocks %d", $5) : "";
                   printf "%s #%-4s (%3s)  %s\n              [%s]%s\n", m, $1, $4, $2, $3, u}' >&2

  # -------------------------------------------------------------------------
  # 4. Ask the user to confirm the top pick (or choose another).
  # -------------------------------------------------------------------------
  if [ "$DRY_RUN" -eq 1 ]; then
    echo >&2
    echo "(dry run: would offer #$top_num)" >&2
    ISSUE_NUM="$top_num"; ISSUE_TITLE="$top_title"
  elif [ "$AUTO_PICK" -eq 1 ]; then
    ISSUE_NUM="$top_num"; ISSUE_TITLE="$top_title"
  else
    while :; do
      echo >&2
      if command -v fzf >/dev/null; then
        read -r -p "Work on #$top_num? [Enter=yes / number / l=browse / q=quit] " answer
      else
        read -r -p "Work on #$top_num? [Enter=yes / number / q=quit] " answer
      fi
      case "$answer" in
        "") selection="$(printf '%s\n' "$rows" | head -1)" ;;
        q|Q) echo "Aborted." >&2; exit 130 ;;
        l|L) selection="$(printf '%s\n' "$rows" \
               | fzf --delimiter='\t' --with-nth=1,4,2 \
                     --prompt="unblocked > " --height=60% --border \
                     --preview="gh issue view {1} --repo $REPO" --preview-window=right,60%,wrap)" || true ;;
        *) selection="$(printf '%s\n' "$rows" | awk -F'\t' -v n="${answer#\#}" '$1 == n')" ;;
      esac
      [ -n "${selection:-}" ] && break
      echo "Not one of the candidates above." >&2
    done
    ISSUE_NUM="$(cut -f1 <<<"$selection")"
    ISSUE_TITLE="$(cut -f2 <<<"$selection")"
  fi
fi

BRANCH="issue-$ISSUE_NUM-$(printf '%s' "$ISSUE_TITLE" \
  | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-+|-+$//g' | cut -c1-40)"

# ---------------------------------------------------------------------------
# 5. Build the Claude prompt.
# ---------------------------------------------------------------------------
read -r -d '' PROMPT <<EOF || true
Implement GitHub issue #$ISSUE_NUM in $REPO: "$ISSUE_TITLE"

Start by reading the full issue and its discussion:
  gh issue view $ISSUE_NUM --repo $REPO --comments

Then implement it using the $SKILL skill. Invoke it with the Skill tool under
exactly that name, and follow its red-green-refactor loop: write the failing
test that captures the issue first, then make it pass.

The skill is a hard requirement, not a suggestion. If invoking it fails, stop
immediately, change nothing, and report that the skill could not be invoked. Do
not substitute a different skill, do not improvise an equivalent workflow, and
do not proceed without one.

Follow the project conventions in CLAUDE.md (ADRs, patterns, TDD,
test-verification policy).

Locally, run only the tests relevant to the change — the unit/feature tests for
the code you touched, and the E2E spec (or --grep subset) that covers it. Those
must be green before you commit. Leave the rest of the suite to CI rather than
running it locally: it is slower, and the dev database carries residual data
that makes unrelated specs fail for reasons that have nothing to do with your
change. If a test you did not touch fails locally, check whether it also fails
on clean main before spending time on it.

Delegate to sub-agents where the work parallelises, and use cheaper models
(Haiku for mechanical/search work, Sonnet for routine implementation) where
that is sufficient — reserve the strongest model for design decisions and
tricky debugging.

When the implementation is done and the tests pass:
1. Commit on a branch named $BRANCH (create it if it does not exist).
2. Push and open a PR whose body contains "Closes #$ISSUE_NUM".
3. Monitor the CI build for that PR (gh pr checks <pr> --repo $REPO --watch).
4. If a check fails, read the failing logs (gh run view --log-failed),
   fix the cause, push, and keep monitoring until every check passes.

Report back once the build is green, with the PR link and a short summary of
what changed. If the build cannot be made green, stop and report exactly what
is failing and why.
EOF

echo >&2
echo "Selected: #$ISSUE_NUM — $ISSUE_TITLE" >&2
echo "Branch:   $BRANCH" >&2

if [ "$DRY_RUN" -eq 1 ]; then
  echo >&2; echo "--- prompt ---" >&2
  printf '%s\n' "$PROMPT"
  exit 0
fi

if [ "$CLAIM" -eq 1 ]; then
  gh issue edit "$ISSUE_NUM" --repo "$REPO" --add-assignee @me >/dev/null \
    && echo "Claimed #$ISSUE_NUM for @me" >&2 \
    || echo "Warning: could not assign #$ISSUE_NUM" >&2
fi

command -v claude >/dev/null || { echo "claude CLI not found" >&2; exit 1; }
exec claude --model opus "$PROMPT"
