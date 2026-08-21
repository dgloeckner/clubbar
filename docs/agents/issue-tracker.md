# Issue tracker: GitHub

Issues and PRDs for this repo live as GitHub issues (`dgloeckner/clubbar`). Use the `gh` CLI for all operations.

## Conventions

- **Create an issue**: `gh issue create --title "..." --body "..."`. Use a heredoc for multi-line bodies.
- **Read an issue**: `gh issue view <number> --comments`, filtering comments by `jq` and also fetching labels.
- **List issues**: `gh issue list --state open --json number,title,body,labels,comments --jq '[.[] | {number, title, body, labels: [.labels[].name], comments: [.comments[].body]}]'` with appropriate `--label` and `--state` filters.
- **Comment on an issue**: `gh issue comment <number> --body "..."`
- **Apply / remove labels**: `gh issue edit <number> --add-label "..."` / `--remove-label "..."`
- **Close**: `gh issue close <number> --comment "..."`

Infer the repo from `git remote -v` — `gh` does this automatically when run inside a clone.

## Labels in use

Reuse this repo's existing label vocabulary when creating or editing issues; don't invent parallel labels:

- **Type**: `bug`, `enhancement`, `documentation`, `question`, `tech-debt`
- **Priority**: `priority: critical` (money-wrong or data-loss; fix first), `priority: high`, `priority: medium`, `priority: low`
- **Area**: `terminal-frontend` (Flutter POS terminal app), `ux`, `accessibility`, `i18n`
- **State**: `in progress`, `wontfix`, `duplicate`, `invalid`, `help wanted`, `good first issue`
- **Dependency pull requests**: `dependencies` and `javascript` are Dependabot's own;
  `blocked-upstream` is ours — see below.

New issues should carry at least a type label, a priority label, and an area label.

## `blocked-upstream`: a dependency PR nothing here can fix

Most red Dependabot pull requests are ordinary work: react 19 (#618) failed on one
tooltip callback whose types recharts had widened, and a commit fixed it. Some are
not. #620 raised typescript to 7 while `@typescript-eslint` 8.67.0 — the newest
published release — still declares `"typescript": ">=4.8.4 <6.1.0"`, so npm refuses
the tree and no combination of versions in the group resolves. The release that
would fix it has not been published by anyone.

That is what `blocked-upstream` marks: **the branch cannot be made green from this
repository, and the thing it waits for is someone else's release.** A bump that
merely needs a code change here does not get the label; it gets the code change.

Closing such a pull request loses the reasoning and Dependabot opens it again on the
next run. Labelling keeps one pull request as the standing record, with a comment
naming the exact constraint and the condition that clears it.

**Review the label weekly**, after Dependabot's Monday run:

```bash
gh pr list --state open --label blocked-upstream \
  --json number,title,updatedAt,url --jq '.[] | "\(.number)  \(.title)"'
```

For each one, check whether the blocker has shipped — for a peer range that is one
query, e.g. `npm view @typescript-eslint/parser peerDependencies.typescript` — and
then either:

- **it shipped**: drop the label, comment `@dependabot recreate`, and treat the pull
  request as ordinary work again;
- **it has not**: leave the label, and add a dated line to the comment only if the
  situation changed. Silence is the normal outcome of this sweep.

A pull request that is still blocked after several sweeps is worth a second look at
the constraint itself: pinning the dependency back, or an `ignore` entry in
`.github/dependabot.yml` naming the condition for its removal, may serve better than
a pull request that reopens forever.

## Pull requests as a triage surface

**PRs as a request surface: no.** _(Set to `yes` if this repo treats external PRs as feature requests; `/triage` reads this flag.)_

When set to `yes`, PRs run through the same labels and states as issues, using the `gh pr` equivalents:

- **Read a PR**: `gh pr view <number> --comments` and `gh pr diff <number>` for the diff.
- **List external PRs for triage**: `gh pr list --state open --json number,title,body,labels,author,authorAssociation,comments` then keep only `authorAssociation` of `CONTRIBUTOR`, `FIRST_TIME_CONTRIBUTOR`, or `NONE` (drop `OWNER`/`MEMBER`/`COLLABORATOR`).
- **Comment / label / close**: `gh pr comment`, `gh pr edit --add-label`/`--remove-label`, `gh pr close`.

GitHub shares one number space across issues and PRs, so a bare `#42` may be either — resolve with `gh pr view 42` and fall back to `gh issue view 42`.

## When a skill says "publish to the issue tracker"

Create a GitHub issue.

## When a skill says "fetch the relevant ticket"

Run `gh issue view <number> --comments`.

## Wayfinding operations

Used by `/wayfinder`. The **map** is a single issue with **child** issues as tickets.

- **Map**: a single issue labelled `wayfinder:map`, holding the Notes / Decisions-so-far / Fog body. `gh issue create --label wayfinder:map`.
- **Child ticket**: an issue linked to the map as a GitHub sub-issue (`gh api` on the sub-issues endpoint). Where sub-issues aren't enabled, add the child to a task list in the map body and put `Part of #<map>` at the top of the child body. Labels: `wayfinder:<type>` (`research`/`prototype`/`grilling`/`task`). Once claimed, the ticket is assigned to the driving dev.
- **Blocking**: GitHub's **native issue dependencies** — the canonical, UI-visible representation. Add an edge with `gh api --method POST repos/<owner>/<repo>/issues/<child>/dependencies/blocked_by -F issue_id=<blocker-db-id>`, where `<blocker-db-id>` is the blocker's numeric **database id** (`gh api repos/<owner>/<repo>/issues/<n> --jq .id`, _not_ the `#number` or `node_id`). GitHub reports `issue_dependencies_summary.blocked_by` (open blockers only — the live gate). Where dependencies aren't available, fall back to a `Blocked by: #<n>, #<n>` line at the top of the child body. A ticket is unblocked when every blocker is closed.
- **Frontier query**: list the map's open children (`gh issue list --state open`, scoped to the map's sub-issues / task list), drop any with an open blocker (`issue_dependencies_summary.blocked_by > 0`, or an open issue in the `Blocked by` line) or an assignee; first in map order wins.
- **Claim**: `gh issue edit <n> --add-assignee @me` — the session's first write.
- **Resolve**: `gh issue comment <n> --body "<answer>"`, then `gh issue close <n>`, then append a context pointer (gist + link) to the map's Decisions-so-far.
