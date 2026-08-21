# Research: JuSchG § 9 — the age limits a club bar has to enforce

**Epic:** [#582](https://github.com/dgloeckner/clubbar/issues/582) · **Milestone:** M1
**Date:** 2026-08-20
**Status:** Basis for [ADR-0045](../adr/0045-age-restricted-products.md)

> ⚠️ **Not legal advice.** This note records the statutory text the design is built on and the
> reasoning from it, so a later reader does not have to re-derive why the limit sits on the
> product and why one number per product is enough.

---

## 1. The statute

**Jugendschutzgesetz § 9 — Alkoholhaltige Getränke**
(<https://www.gesetze-im-internet.de/juschg/__9.html>)

**Abs. 1** — in Gaststätten, Verkaufsstellen *und sonst in der Öffentlichkeit*:

| Drink | May not be handed to, or consumed by |
|---|---|
| Branntwein, branntweinhaltige Getränke, Lebensmittel mit nicht nur geringfügigem Branntweingehalt | anyone under 18 |
| Other alcoholic drinks — beer, wine, sparkling wine, mixed drinks based on them | anyone under 16 |

**Abs. 2** carves out the 14- and 15-year-old accompanied by a *personensorgeberechtigte Person*
for the second row only. Never for Branntwein.

**Abs. 3** extends the ban to vending machines except in tightly controlled locations.

Two things follow immediately for this system.

## 2. Why the limit belongs to the product

**The statute classifies the drink, not the shelf it sits on.** Abs. 1 draws its line by what is
in the glass — distilled alcohol or not — and it does so per beverage. Nothing in it refers to how
a seller groups their range.

This is the whole argument for putting `min_age` on `products` rather than on `categories`:

- A category in this system is a **display grouping**. The Getränkewart drags tiles between them
  to make a busy shift easier. That action is presentational and is expected to be casual.
- If the limit rode the category, moving "Alt" from *Bier* into *Angebote der Woche* would
  silently un-restrict it. The person doing it would have no reason to think they had changed
  anything legal, and no error would appear.
- The same product may plausibly appear under more than one grouping over its life. Its legal
  classification does not change with any of that.

**A category-level rule would be a rule that a routine, correct, everyday action can switch off.**
That is the definition of a control that will eventually fail.

## 3. Why one integer per product is enough — and why not an enum

Abs. 1 has exactly two thresholds, so `{16, 18}` as an enum is tempting and would be a faithful
model of German law. It is rejected for two reasons, in order of weight:

1. **This software is self-hosted open source, not a German-only product.** Austria sets its
   limits per Bundesland (16 for beer and wine in most, 18 for spirits, with variations);
   Switzerland runs 16/18 federally with cantonal additions; the Netherlands is a flat 18 for
   everything. A club that installs this in Utrecht needs `18` on every alcoholic product and no
   `16` at all, and a club in Vienna may need `16` on something a German club does not.
2. **The enum would have to be widened by a migration** each time a jurisdiction is discovered,
   and every widening touches the sync contract, the terminal cache and the generated clients.

A free `TINYINT UNSIGNED NULL` bounded to 1–99 expresses "this product requires a minimum age"
without encoding one country's answer. The German club sets 16 and 18; nobody else is blocked.

**NULL means unrestricted.** Not "unknown" — the Getränkewart's default state for a Spezi is
genuinely "no age applies", and making them assert that explicitly would be friction with no
safety gain: an unrestricted product is the overwhelming majority and a forgotten field would
otherwise block sales of Apfelschorle.

## 4. Why Abs. 2 is deliberately not modelled

The accompanied-minor exception is real law and it is the one place a "guardian present" override
could be legitimate. It is a non-goal in [ADR-0045](../adr/0045-age-restricted-products.md) anyway:

- The exception is a **judgement about a person standing in the room** — is this adult actually
  personensorgeberechtigt? An unattended kiosk cannot make it, and it cannot record having made
  it truthfully.
- An override button on a self-service terminal is a button that gets pressed. Its existence
  converts a hard refusal into a soft one, and the audit row it writes says only that somebody
  tapped it.
- The bar has staff. If the exception applies, the correct handling is a human serving the drink,
  not a kiosk being told to look the other way.

Modelling it would make the system's refusal weaker while making the club's record of the sale no
more honest.

## 5. Why the check is the *terminal's* job

§ 9 attaches to the moment of *Abgabe* — handing over. In this system that moment is checkout at
an offline kiosk ([ADR-0033](../adr/0033-terminal-sync-contract.md)), with the server possibly
hours or weeks away.

So the only layer that can *prevent* anything is the terminal, and the only way it can be right
on an arbitrary future day is to hold the raw birth date and compute the age itself. Anything
derived — an age in years, a boolean — is stale from the member's next birthday, and the person
it is wrong about is the one it is most conspicuous to.

The server's job is therefore not prevention but **detection**, and § 146 Abs. 1 AO
(`docs/legal-requirements-and-how-we-meet-them.md` §1.3) settles what it may do about a violation
it detects: record the sale, raise the alarm, never drop the row. The full argument is in
[ADR-0045](../adr/0045-age-restricted-products.md) §3.

## 6. What this note does not answer

- **Whether the club also needs a posted Aushang** of § 9's text (JuSchG § 3 requires
  Veranstalter and Gewerbetreibende to display the relevant provisions in a conspicuous place).
  That is a physical-premises obligation, not a software one, but somebody should check it.
- **Ordnungswidrigkeit exposure** under § 28 for the club and the individual serving. Not modelled
  here; the system's contribution is that a violation becomes known.
- **Whether a Vereinsheim bar is a "Gaststätte"** for Abs. 1's purposes. It does not matter for
  the design: *"und sonst in der Öffentlichkeit"* catches it either way.

## References

- JuSchG § 9 — <https://www.gesetze-im-internet.de/juschg/__9.html>
- JuSchG § 3 (Aushang), § 28 (Bußgeld)
- `docs/legal-requirements-and-how-we-meet-them.md` §1.3 — § 146 AO Einzelaufzeichnung
- `research/175-onboarding-form-datenschutz.md:348` — where the gap was first recorded
- [ADR-0045](../adr/0045-age-restricted-products.md) — the decision this note supports
