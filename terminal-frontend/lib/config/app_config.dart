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

  // Balance Limit (€100.00 = 10000 cents; configurable from backend later)
  static const int balanceLimitCents = 10000; // €100.00

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
