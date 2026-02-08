// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for English (`en`).
class AppLocalizationsEn extends AppLocalizations {
  AppLocalizationsEn([String locale = 'en']) : super(locale);

  @override
  String get idleTitle => 'Thirsty?';

  @override
  String get idleSubtitle => 'Hold your card to the scanner';

  @override
  String get demoScanCard => 'Demo: Scan Card';

  @override
  String get setupTitle => 'Terminal Setup';

  @override
  String get setupSubtitle => 'Connect this terminal to the Ruderbar backend.';

  @override
  String get terminalIdLabel => 'Terminal ID';

  @override
  String get terminalIdRequired => 'Terminal ID is required';

  @override
  String get apiUrlLabel => 'API URL';

  @override
  String get apiUrlRequired => 'API URL is required';

  @override
  String get apiUrlInvalid =>
      'Enter a valid URL (e.g. https://club.example.com/api)';

  @override
  String get apiTokenLabel => 'API Token';

  @override
  String get apiTokenRequired => 'API Token is required';

  @override
  String get saveAndConnect => 'Save & Connect';

  @override
  String connectionFailed(String error) {
    return 'Connection failed: $error';
  }

  @override
  String get cartEmpty => 'Your cart is empty';

  @override
  String get cartTotal => 'Total';

  @override
  String cartNewBalance(String balance) {
    return 'New Balance: $balance';
  }

  @override
  String cartEachPrice(String price) {
    return '$price each';
  }

  @override
  String get checkout => 'Checkout';

  @override
  String get memberNotSelected => 'Member not selected';

  @override
  String get checkoutSuccess => 'Transaction successful!';

  @override
  String checkoutNewBalance(String balance) {
    return 'New Balance: $balance';
  }

  @override
  String redirectingIn(int seconds) {
    String _temp0 = intl.Intl.pluralLogic(
      seconds,
      locale: localeName,
      other: 'seconds',
      one: 'second',
    );
    return 'Redirecting in $seconds $_temp0...';
  }

  @override
  String get memberDetails => 'Member Details';

  @override
  String get firstName => 'First Name';

  @override
  String get lastName => 'Last Name';

  @override
  String get accountStatus => 'Account Status';

  @override
  String get memberActive => 'Active';

  @override
  String get memberInactive => 'Inactive';

  @override
  String get sepaYes => 'Yes';

  @override
  String get sepaNo => 'No';

  @override
  String get noMemberSelected => 'No member selected';

  @override
  String welcomeMember(String name) {
    return 'Welcome, $name';
  }

  @override
  String get balance => 'Balance';

  @override
  String get viewDetails => 'Details';

  @override
  String get logout => 'Log out';

  @override
  String get errorTitle => 'Error';

  @override
  String get dismiss => 'Dismiss';

  @override
  String get retry => 'Retry';

  @override
  String get categoryDefault => 'Category';

  @override
  String get productDefault => 'Product';

  @override
  String get noProducts => 'No products available';

  @override
  String get noCategories => 'No categories available';

  @override
  String get noProductsInCategory => 'No products in this category';

  @override
  String get statusOnline => 'Online';

  @override
  String get statusOffline => 'Offline';

  @override
  String get statusError => 'Error';

  @override
  String get never => 'Never';

  @override
  String get lastSync => 'Last sync';

  @override
  String get lastTransactionSync => 'Last transaction sync';

  @override
  String get retryCount => 'Retry count';

  @override
  String get errorDetails => 'Error details';

  @override
  String get loading => 'Loading...';

  @override
  String get cancel => 'Cancel';

  @override
  String get save => 'Save';

  @override
  String get continueShopping => 'Continue Shopping';

  @override
  String get scanCard => 'Scan Card';

  @override
  String get rfidErrorUnknownCard => 'Unknown card';

  @override
  String get rfidErrorAccountInactive => 'Account inactive';

  @override
  String get rfidErrorSepaMissing => 'SEPA mandate missing';

  @override
  String get rfidErrorDatabaseError => 'Database error';

  @override
  String get preferredLanguage => 'Preferred Language';

  @override
  String get recentTransactions => 'Recent Transactions';

  @override
  String get loadingTransactions => 'Loading transactions...';

  @override
  String get errorLoadingTransactions => 'Error loading transactions';

  @override
  String get offlineMode => 'Offline Mode';

  @override
  String get transactionHistoryUnavailableOffline =>
      'Transaction history unavailable offline';

  @override
  String get noTransactions => 'No transactions yet';
}
