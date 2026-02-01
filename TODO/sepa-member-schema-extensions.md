# SEPA Member Schema Extensions

## Context

The Ruderbar POS system for FRGS (Frankfurter Rudergesellschaft Sachsenhausen) currently stores only first name, last name, and IBAN per member. This document describes the required schema extensions to support SEPA Direct Debit (Lastschrift) correctly, with a focus on divergent account holders and character sanitization.

## Schema Changes

### New Fields on `members`

| Field | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `account_holder_name` | VARCHAR(70) | Yes | NULL | Name of the account holder if different from the member. NULL means the member themselves is the account holder. 70 chars is the SEPA maximum for account holder names. |
| `iban` | VARCHAR(34) | Yes | NULL | IBAN of the bank account. NULL means no SEPA mandate exists (member pays cash or by bank transfer). 34 chars is the max IBAN length (currently used by Malta and others). |
| `mandate_signed_at` | DATE | Yes | NULL | Date the SEPA mandate was signed. Required for valid SEPA Direct Debit XML (pain.008). |
| `mandate_first_used_at` | TIMESTAMP | Yes | NULL | Timestamp of the first successfully executed direct debit. Used to determine the sequence type: NULL → FRST, otherwise → RCUR. Reset to NULL when IBAN changes. |

### Derived Logic

**Effective account holder name** for SEPA XML export:

```
IF account_holder_name IS NOT NULL
    THEN account_holder_name
    ELSE CONCAT(first_name, ' ', last_name)
```

**Mandate reference**: Use the member UUID. One mandate per member, simplifies tracking.

**Sequence type** (FRST / RCUR):

```
IF mandate_first_used_at IS NULL
    THEN 'FRST'   -- first direct debit under this mandate
    ELSE 'RCUR'   -- all subsequent direct debits
```

**Has SEPA mandate**:

```
iban IS NOT NULL AND mandate_signed_at IS NOT NULL
```

## Edge Cases

### Divergent Account Holder

The most common scenario in a club setting. The person who owns the bank account is not the club member.

Typical cases at FRGS:

- **Parent pays for child**: Child rows, parent provides IBAN and signs the mandate.
- **Spouse pays for partner**: One partner's account is used for both memberships.
- **One family member pays for several**: A single IBAN with one account holder name covers multiple member records.

The `account_holder_name` field must contain the actual name on the bank account. This is a SEPA requirement — the direct debit XML must reference the real account holder, not the club member.

### Members Without SEPA Mandate

Not every member wants direct debit. Some pay cash at the bar or settle by bank transfer. Making `iban` nullable allows creating member records without payment information. The system should still track their tab and provide a balance, but exclude them from the direct debit export.

### Mandate Changes (Bank Switch)

When a member changes their bank account:

1. Update `iban` to the new value.
2. Update `account_holder_name` if it changed.
3. Update `mandate_signed_at` to the date the new mandate was signed.
4. Set `mandate_first_used_at` to NULL — the next direct debit will use FRST again.

For a club system, overwriting the old data is sufficient. There is no regulatory requirement to keep a history of previous IBANs.

### International IBANs

The IBAN-only rule (no BIC required) applies only to domestic German SEPA transactions. If FRGS has members with foreign bank accounts, a BIC might be needed. In practice this is rare for a Frankfurt rowing club, but worth noting. If it becomes relevant, add an optional `bic` field (VARCHAR 11).

### Pre-Notification

SEPA rules require notifying the debtor before executing a direct debit (default: 14 days). This can be shortened to 1 day by including a clause in the mandate text. Recommended mandate wording:

> "The pre-notification period is shortened to 1 day before the due date."

This is an operational concern, not a schema concern, but important for the overall SEPA workflow.

## Character Sanitization

SEPA XML files (pain.008) only allow the EPC Basic Latin Character Set. German umlauts and accented characters from other European languages must be converted before export.

### Applicable Standards

- **EPC217-08**: "Best Practices SEPA Requirements for an Extended Character Set (UNICODE Subset)" — includes a conversion table (Excel) mapping Unicode characters to Basic Latin equivalents.
- **Anlage 3 des DFÜ-Abkommens** (DK): German banking specification that defines the allowed character set and conversion rules for the German market.

### Allowed Characters

```
a-z A-Z 0-9 / - ? : ( ) . , ' + [Space]
```

Everything else must be converted or removed.

### Conversion Rules

Applied in this order (order matters):

1. **German-specific replacements** (before NFD normalization):

   | Character | Replacement | Source |
   |---|---|---|
   | Ä, ä | Ae, ae | DK alternative |
   | Ö, ö | Oe, oe | DK alternative |
   | Ü, ü | Ue, ue | DK alternative |
   | ß | ss | DK alternative |

   These must happen before Unicode normalization. NFD would decompose ä into `a` + combining diaeresis, and stripping the combining mark would produce just `a` instead of `ae`.

2. **Special character replacements** (per EPC217-08):

   | Character | Replacement |
   |---|---|
   | & | + |
   | * | . |
   | € | EUR |
   | Typographic quotes (" " ' ') | . or ' |
   | En/em dash (– —) | - |

3. **Ligatures and non-decomposable letters**:

   | Character | Replacement |
   |---|---|
   | Æ, æ | AE, ae |
   | Œ, œ | OE, oe |
   | Ø, ø | O, o |
   | Ð, ð | D, d |
   | Þ, þ | Th, th |
   | Ł, ł | L, l |

4. **Unicode NFD normalization**: Decomposes remaining accented characters into base letter + combining diacritical mark (e.g., é → e + U+0301).

5. **Strip combining marks**: Remove all Unicode category Mn (Mark, Nonspacing) characters, leaving only the base letters.

6. **Remove remaining invalid characters**: Anything not in the allowed set is dropped.

7. **Normalize whitespace**: Collapse multiple spaces, trim.

8. **Truncate**: Enforce SEPA field length limits (70 chars for names, 140 for remittance info, 35 for references).

### Implementation

A PHP implementation (`SepaSanitizer.php`) is provided alongside this document. It requires the `php-intl` extension for the `Normalizer` class.

Key design decision: Store names with their original characters (umlauts, accents) in the database. Only apply sanitization at the point of SEPA XML export. This keeps the UI and internal reports readable while ensuring valid SEPA output.

### SEPA Reference Rules (EPC217-08 §6.3)

In addition to the character set, identifiers and references have structural rules:

- Must not start or end with `/`.
- Must not contain `//` (consecutive slashes).
- Must be restricted to the Basic Latin character set (no extended characters even if the bank supports them).
