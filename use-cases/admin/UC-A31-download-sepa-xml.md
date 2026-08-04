# UC-A31: Download SEPA XML

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in
- Settlement exists

## Trigger
Admin clicks "Download SEPA XML" on settlement

## Main Flow
1. Admin opens settlement details
2. Admin clicks "Download SEPA XML"
3. System generates pain.008.001.08 XML
4. Browser downloads file

## XML Content
- Format: pain.008.001.08 (SEPA Direct Debit)
- Header: Organization creditor ID, name, IBAN
- Transactions: One entry per member with mandate

## Filename Format
`frgs-lastschrift-YYYY-MM-DD.xml`

## Postconditions
- XML file downloaded
- Audit log entry for download

## Business Rules
- Only members with valid SEPA mandate included
- Amount = member's settled balance
- Sequence type: RCUR (recurring)
- Mandate reference: From member record

## Test Derivation
- Download XML: valid file downloaded
- XML validation: passes pain.008 XSD schema
- Creditor info: organization data correct
- Transaction count: matches SEPA member count
- Amounts: match settlement amounts
- Mandate references: correct per member
- Audit log: download logged
