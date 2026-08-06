#!/usr/bin/env bash
#
# work-on-issue.sh — pick an unblocked GitHub issue by priority and hand it to Claude Code.
#
# "Unblocked" means: open, no open blocked-by dependency, and (unless -A) no assignee.
#
# Candidates are ranked by impact (see IMPACT SCORE below) and the highest-value
# one is offered first; -1 takes it without prompting.
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
#
set -euo pipefail

PRIORITY="high"
EXTRA_LABELS=()
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
# Checked here, before anything else happens, because the failure is otherwise
# invisible: skills marked `disable-model-invocation: true` are absent from the
# model's available-skills listing, so a session told to use a missing one will
# reasonably conclude it isn't installed and quietly substitute the nearest
# match. Fail loudly at launch instead of discovering it in the diff.
# ---------------------------------------------------------------------------
SKILL="mattpocock-skills:implement"
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
  [ ${#EXTRA_LABELS[@]} -gt 0 ] && echo "Labels:   ${EXTRA_LABELS[*]}" >&2

  # -------------------------------------------------------------------------
  # 1. Candidate issues: open + matching labels (filtered client-side; the
  #    server-side label filter is unreliable across the repo rename redirect).
  #    An issue matches if it carries ANY of the priority labels and ALL of the
  #    extra labels.
  # -------------------------------------------------------------------------
  extra_labels_json="$(printf '%s\n' "${EXTRA_LABELS[@]+"${EXTRA_LABELS[@]}"}" | jq -R . | jq -s 'map(select(. != ""))')"

  candidates="$(gh issue list --repo "$REPO" --state open --limit 200 \
    --json number,title,labels,assignees,comments,reactionGroups \
    --jq "map(select(([.labels[].name]) as \$have
                     | ($PRIORITY_LABELS_JSON | any(. as \$l | \$have | index(\$l)))
                       and ($extra_labels_json | all(. as \$l | \$have | index(\$l)))))")"

  labelled="$(jq 'length' <<<"$candidates")"
  [ "$labelled" -eq 0 ] && { echo "No open issues carry those labels." >&2; exit 2; }

  if [ "$INCLUDE_ASSIGNED" -eq 0 ]; then
    candidates="$(jq 'map(select((.assignees | length) == 0))' <<<"$candidates")"
  fi

  count="$(jq 'length' <<<"$candidates")"
  [ "$count" -eq 0 ] && {
    echo "All $labelled matching issue(s) already have an assignee (use -A to include them)." >&2
    exit 2
  }

  # -------------------------------------------------------------------------
  # 2. Drop issues with open blockers (GitHub native issue dependencies).
  # -------------------------------------------------------------------------
  echo "Checking blockers on $count issue(s)…" >&2
  unblocked="$(jq -r '.[].number' <<<"$candidates" \
    | xargs -P 8 -I{} gh api "repos/$REPO/issues/{}" \
        --jq 'select((.issue_dependencies_summary.blocked_by // 0) == 0) | .number' \
    | sort -n)"

  [ -n "$unblocked" ] || { echo "All matching issues are blocked by open issues." >&2; exit 2; }

  # -------------------------------------------------------------------------
  # 3. Rank by impact. Highest score first; ties go to the lower (older) number.
  # -------------------------------------------------------------------------
  rows="$(jq -r --argjson nums "$(printf '%s\n' "$unblocked" | jq -R 'tonumber' | jq -s .)" '
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
      )})
    | sort_by(-.score, .number)
    | .[] | "\(.number)\t\(.title)\t\([.labels[].name] | join(", "))\t\(.score)"' <<<"$candidates")"

  top_num="$(printf '%s\n' "$rows" | head -1 | cut -f1)"
  top_title="$(printf '%s\n' "$rows" | head -1 | cut -f2)"

  echo >&2
  echo "Unblocked candidates, highest impact first:" >&2
  printf '%s\n' "$rows" \
    | awk -F'\t' 'NR==1{m="  ->"} NR>1{m="    "} {printf "%s #%-4s (%3s)  %s\n              [%s]\n", m, $1, $4, $2, $3}' >&2

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
exactly that name.

It is marked user-invocable-only, so it will NOT appear in your available-skills
listing. Invoke it by name regardless — the listing's silence is not evidence
that it is missing, and it has been verified as installed before this session
started.

The skill is a hard requirement, not a suggestion. If invoking it fails, stop
immediately, change nothing, and report that the skill could not be invoked. Do
not substitute a different skill, do not improvise an equivalent workflow, and
do not proceed without one.

Follow the project conventions in CLAUDE.md (ADRs, patterns, TDD,
test-verification policy).

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
exec claude "$PROMPT"
