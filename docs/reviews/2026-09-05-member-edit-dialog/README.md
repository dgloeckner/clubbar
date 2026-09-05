# Member edit dialog — status strip redesign (2026-09-05)

Design proposal for the admin panel's *Mitglied bearbeiten* dialog: one status
strip that answers "can this member use the Clubbar?", field markers only where
something is missing, a dialog that fits a 900 px screen without scrolling, and
a sticky footer so *Speichern* is never out of view.

The editable canvas lives at
<https://claude.ai/code/artifact/c27ac99d-acf5-4f4a-8a75-6cd35ccb7157>; the
PNGs here are exports of its artboards, kept so the GitHub issue that tracks the
work can show them.

| File | Artboard |
|------|----------|
| `01-desktop-complete.png` | Desktop, complete member, one info tooltip open |
| `02-desktop-gaps.png` | Desktop, member with gaps: strip, orange borders, "Pflicht" pill |
| `03-status-strip-states.png` | The strip in its four states (ready, gaps, save changes something, save refused) |
| `04-alternative-sidebar.png` | Low-fi alternative with the status as a side rail, with its trade-off |
| `05-mobile-complete.png` | Phone, complete member, top of form |
| `06-mobile-gaps.png` | Phone, member with gaps |
| `07-mobile-scrolled.png` | Phone, scrolled mid-form: pinned header summary and pinned footer |
