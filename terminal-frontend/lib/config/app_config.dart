class AppConfig {
  static const String appName = 'Ruderbar Terminal';
  static const String version = '0.1.0';

  // Display
  static const bool isProduction = false;

  // Sync timing
  static const Duration syncInterval = Duration(seconds: 60);
  static const Duration syncTimeout = Duration(seconds: 10);

  // Inactivity & Cart Preservation
  static const Duration inactivityTimeout = Duration(seconds: 30);
  static const Duration cartPreservationDuration = Duration(hours: 1);

  // Balance Limit (€100.00 = 10000 cents; configurable from backend later)
  static const int balanceLimitCents = 10000; // €100.00

  // Backend API
  static const String apiBaseUrl = 'http://localhost:8080/api';
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
