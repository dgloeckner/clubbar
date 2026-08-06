# Triage Labels

The skills speak in terms of five canonical triage roles. This file maps those roles to the actual label strings used in this repo's issue tracker.

| Label in mattpocock/skills | Label in our tracker | Meaning                                  |
| -------------------------- | -------------------- | ---------------------------------------- |
| `needs-triage`             | `needs-triage`       | Maintainer needs to evaluate this issue  |
| `needs-info`               | `needs-info`         | Waiting on reporter for more information |
| `ready-for-agent`          | `ready-for-agent`    | Fully specified, ready for an AFK agent  |
| `ready-for-human`          | `ready-for-human`    | Requires human implementation            |
| `wontfix`                  | `wontfix`            | Will not be actioned                     |

When a skill mentions a role (e.g. "apply the AFK-ready triage label"), use the corresponding label string from this table.

Edit the right-hand column to match whatever vocabulary you actually use.

## Relationship to the repo's other labels

These five describe **where an issue is in the triage pipeline**. They sit alongside — and do not replace — the type / priority / area vocabulary documented in `issue-tracker.md`:

- **Type**: `bug`, `enhancement`, `documentation`, `question`, `tech-debt`
- **Priority**: `priority: critical`, `priority: high`, `priority: medium`, `priority: low`
- **Area**: `terminal-frontend`, `ux`, `accessibility`, `i18n`

A triaged issue normally carries one triage label plus a type, a priority, and an area. `wontfix` is shared between the two vocabularies — it is both a triage outcome and this repo's existing "will not be worked on" label.
