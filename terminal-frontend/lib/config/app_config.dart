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

  // Seed values for the credit limit, and **only** seed values (ADR-0046).
  //
  // The club configures its own ceiling and warning band; the terminal fetches
  // them from `GET /sync/config` and persists them in `config.json`, so a
  // terminal that boots with no backend reachable still enforces what the club
  // last said. These two constants are what it enforces before its *first*
  // successful config sync — a fresh install, nothing more.
  //
  // They are the values the backend seeds `credit_limit_config` with, which is
  // why a club that has never touched the setting sees no change at all.
  // `CreditLimitPolicy.shipped` is how they are read; nothing else may read
  // them, because a call site that did would be enforcing a compiled-in number
  // instead of the club's.
  static const int balanceLimitCents = 10000; // €100.00
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
