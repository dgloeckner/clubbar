class AppConfig {
  static const String appName = 'Club Bar Terminal';
  static const String version = String.fromEnvironment('APP_VERSION', defaultValue: 'dev');

  // Display
  static const bool isProduction = false;

  // Sync timing
  static const Duration syncInterval = Duration(seconds: 60);
  static const Duration syncTimeout = Duration(seconds: 10);

  // Session inactivity timeout (ADR-0027): 60 s without interaction, then a
  // visible countdown warning before the session ends. The cart dies with the
  // session — there is no cross-session cart preservation.
  static const Duration inactivityTimeout = Duration(seconds: 60);
  static const Duration inactivityWarningDuration = Duration(seconds: 10);

  // How long the checkout receipt stays up before the terminal returns itself
  // to idle (ADR-0027 rule 10). Short on purpose: the receipt is the tail of an
  // otherwise fast flow, and every second here is a second the next person in
  // the queue cannot start.
  static const Duration receiptAutoReturnDelay = Duration(seconds: 8);

  // Balance Limit (€100.00 = 10000 cents; configurable from backend later).
  // Enforced by `CreditLimitCheck` (UC-T11 E3, UC-T12): a checkout that would
  // push the tab *past* this is blocked; landing exactly on it is allowed.
  // Zero or less turns enforcement off.
  //
  // **The backend keeps a copy** in `App\Modules\Dashboard\Domain\CreditLimit`,
  // so the admin dashboard can name the same line when it lists the members
  // close to it (#385). Change both together, or the dashboard will warn about
  // members this terminal is still serving without a word — the terminal cannot
  // read the value from the backend, because it has to decide this with nothing
  // reachable.
  static const int balanceLimitCents = 10000; // €100.00

  // Share of the limit at which the member is warned but not yet blocked.
  // Copied backend-side as well — see above.
  static const int balanceWarnThresholdPercent = 80; // €80.00 of €100.00

  // Backend API
  static const String healthEndpoint = '/health';
  static const String syncEndpointMembers = '/sync/members';
  static const String syncEndpointCategories = '/sync/categories';
  static const String syncEndpointProducts = '/sync/products';
  static const String syncEndpointTransactions = '/sync/transactions';

  // Health check
  static const Duration healthCheckTimeout = Duration(seconds: 5);

  // UI
  static const double minTapTarget = 48.0;
}
