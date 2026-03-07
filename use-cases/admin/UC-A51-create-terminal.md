# UC-A51: Create Terminal

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in
- Device ID is not already registered in the system

## Trigger
Admin clicks "Register New Terminal" button

## Main Flow
1. Admin navigates to Terminals section
2. Admin clicks "Register New Terminal" button
3. System displays terminal registration form with fields:
   - Terminal Name (text, max 100 chars, required)
   - Device ID (text, max 100 chars, required)
4. Admin enters terminal information
5. Admin clicks "Register" button
6. System validates input:
   - Name: required, non-empty, max 100 characters
   - Device ID: required, non-empty, max 100 characters, must be unique
7. If validation passes:
   - System generates secure 64-character API token (256-bit entropy)
   - System creates terminal record in database
   - System displays success message with terminal details including:
     - Terminal ID (UUID)
     - Terminal name
     - Device ID
     - API token (plaintext, displayed only once)
     - Message: "Terminal created successfully. Save this token as it will not be shown again."
8. If validation fails:
   - System displays error messages for each invalid field
   - Form is preserved for correction

## Response Format (Success)
```json
{
  "terminal": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "name": "Bar Counter Terminal 1",
    "device_id": "HC-2024-001",
    "is_active": true,
    "created_at": "2026-01-26T18:00:00Z",
    "updated_at": "2026-01-26T18:00:00Z"
  },
  "api_token": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f",
  "message": "Terminal created successfully. API token shown above (save this token, it will not be shown again)."
}
```

## Security Rules
- API token generated with cryptographically secure random number generator
- Token is 64 hexadecimal characters (256-bit entropy)
- Token never stored in plaintext in database
- Token hash stored using bcrypt (cost factor 12)
- Token shown only once in response
- Subsequent API calls do NOT return the token
- Device ID must be unique (no two terminals can have same device_id)

## Postconditions
- Terminal registered and active
- API token generated and displayed
- Terminal ready to authenticate API requests with the token

## Business Rules
- Device ID uniqueness enforced at database level (UNIQUE constraint) and application level
- Terminal is created with is_active=true by default
- Terminal can immediately start authenticating API requests with provided token

## API Mapping
- Endpoint: POST /api/admin/terminals
- Request body:
  ```json
  {
    "name": "Bar Counter Terminal 1",
    "device_id": "HC-2024-001"
  }
  ```
- Response: 201 Created with TerminalWithTokenDto + plaintext token
- Audit Log: CREATE action logged with EntityType=TERMINAL

## Test Derivation
- Create terminal: verify success response with all fields
- Token generation: verify token is 64 hex characters
- Token not repeated: create second terminal, verify different token
- Validation - empty name: verify 422 error with message
- Validation - name too long: verify 422 error with message
- Validation - duplicate device_id: verify 422 error with message
- Validation - empty device_id: verify 422 error with message
- Success response includes message: verify helpful message about saving token
- Token not in subsequent requests: verify terminal details don't include api_token field
- Audit log: verify CREATE action logged for this terminal
- Authentication ready: verify new terminal can authenticate API requests
