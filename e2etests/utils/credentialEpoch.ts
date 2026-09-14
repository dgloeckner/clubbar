/**
 * Waiting out the second in which a credential changed.
 *
 * `admin_users.credentials_changed_at` is second-granular, and
 * `SessionTimeout::predatesCredentialChange()` refuses a session whose
 * `authenticated_at` is not **strictly** later — a tie is refused on purpose,
 * so that no attacker session survives the victim's password change for the
 * remainder of the second it was made in.
 *
 * That makes a test which changes a credential and then immediately logs in
 * again a coin flip decided by where in the second the change landed: the new
 * session is real, the login succeeds, and the very next request comes back
 * 401 `credentials_changed` — a correct refusal, reported as a broken login.
 * It is the same rule the backend already works around for the acting session
 * (`SessionTimeout::beginAfterCredentialChange()` stamps a second ahead) and
 * for an accepted invitation (`AdminInvitationService` stamps a second back);
 * a caller logging in from the outside has no such stamp to lean on and has to
 * wait instead.
 *
 * Call it after the change has returned and before re-authenticating. The cost
 * is under a second; the failure it removes costs a re-run.
 */
export async function waitPastCredentialEpoch(): Promise<void> {
  // Past the next whole second, plus a margin for the clock the backend reads
  // being a hair ahead of this one.
  const msIntoSecond = Date.now() % 1000
  await new Promise((resolve) => setTimeout(resolve, 1000 - msIntoSecond + 150))
}
