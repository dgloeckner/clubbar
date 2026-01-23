# UC-A02: Logout

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin clicks "Logout" button

## Main Flow
1. Admin clicks logout in header
2. System destroys session
3. System clears session cookie
4. System redirects to login page

## Postconditions
- Session invalidated
- Protected routes inaccessible
- Login form displayed

## Test Derivation
- Happy path: logout → login page shown
- Session destroyed: access protected route → redirected to login
- Browser back button: no access to previous page
