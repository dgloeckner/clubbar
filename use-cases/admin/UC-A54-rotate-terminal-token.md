# UC-A54: Rotate Terminal Token

## Actor
Admin

## Preconditions
- Admin is logged in
- Terminal exists in system with active API token
- Terminal ID is valid

## Trigger
Admin clicks "Rotate Token" button on a terminal detail view or actions menu

## Main Flow
1. Admin navigates to terminal detail page
2. Admin clicks "Rotate Token" button
3. System displays confirmation dialog:
   - "Rotate API token for this terminal?"
   - "The old token will become invalid immediately"
   - "Save the new token - it will not be shown again"
   - Buttons: Cancel, Rotate
4. Admin clicks "Rotate" to confirm
5. System generates new secure 64-character API token
6. System updates terminal with new token hash
7. System clears last_sync_at timestamp (forces re-sync with new token)
8. System logs token rotation to audit log
9. System displays new token in response (plaintext, one-time display)
10. Old token becomes invalid immediately

## Token Rotation Effects
- Old token hash is replaced with new token hash (cannot use old token)
- last_sync_at is set to NULL (terminal must re-sync to get current data)
- Terminal must be configured with new token to continue communicating

## Response Format (Success)
```json
{
  "terminal": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "name": "Bar Counter Terminal 1",
    "device_id": "HC-2024-001",
    "is_active": true,
    "last_sync_at": null,
    "created_at": "2026-01-26T18:00:00Z",
    "updated_at": "2026-01-26T18:20:00Z"
  },
  "api_token": "x9y8z7w6v5u4t3s2r1q0p9o8n7m6l5k4j3i2h1g0f9e8d7c6b5a4z3y2x1w0",
  "message": "API token rotated successfully. New token shown above (save this token, it will not be shown again)."
}
```

## Security Rules
- New token generated with cryptographically secure random number generator
- Old token hash is immediately replaced (not stored)
- Terminal can continue operating with old token until re-sync is required
- New token shown only once in rotation response
- Subsequent API calls do NOT return the token

## Postconditions
- Terminal has new API token hash
- Old token is no longer valid
- last_sync_at is cleared (terminal will re-sync on next connection)
- Token rotation recorded in audit log

## Business Rules
- Rotation is immediate (old token becomes invalid at once)
- Should be used when token is compromised or needs regular rotation
- Terminal will automatically re-sync next connection to get new data
- Cannot rotate token for nonexistent or inactive terminal

## API Mapping
- Endpoint: POST /api/admin/terminals/{id}/rotate-token
- Response: 200 OK with TerminalWithTokenDto + new plaintext token
- Audit Log: UPDATE action logged with note "token_rotated", old/new values masked

## Test Derivation
- Rotate token: verify new token generated and returned
- Token changed: verify new token differs from old token
- Token length: verify new token is 64 hex characters
- Old token invalid: verify old token no longer works for authentication
- New token valid: verify new terminal can authenticate with new token
- Last sync cleared: verify last_sync_at set to NULL
- Audit log: verify UPDATE action logged for token rotation
- 404 for nonexistent: verify error if terminal doesn't exist
- Token not repeated: create second rotation, verify different token
- Message included: verify helpful message about saving new token
