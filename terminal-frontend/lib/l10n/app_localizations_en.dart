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
  String get idleSubtitle => 'Hold your token to the scanner';

  @override
  String get demoScanCard => 'Demo: Scan Token';

  @override
  String get readerDisconnectedTitle => 'Scanner not connected';

  @override
  String get readerDisconnectedSubtitle => 'Please inform the staff';

  @override
  String get setupTitle => 'Terminal Setup';

  @override
  String get setupSubtitle => 'Connect this terminal to the Club Bar backend.';

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
  String newBalanceOpenTab(String amount) {
    return 'New open tab: $amount';
  }

  @override
  String newBalanceCredit(String amount) {
    return 'New credit: $amount';
  }

  @override
  String cartEachPrice(String price) {
    return '$price each';
  }

  @override
  String get checkout => 'Checkout';

  @override
  String get checkoutProcessing => 'Processing…';

  @override
  String get checkoutBlockedByLimit => 'Limit reached';

  @override
  String get creditLimitReached =>
      'Balance limit reached — remove items to continue.';

  @override
  String get creditLimitApproaching => 'You are getting close to your limit.';

  @override
  String creditLimitCurrent(String amount) {
    return 'Current balance: $amount';
  }

  @override
  String creditLimitCart(String amount) {
    return 'Cart total: $amount';
  }

  @override
  String creditLimitMaximum(String amount) {
    return 'Maximum allowed: $amount';
  }

  @override
  String get checkoutSuccess => 'Transaction successful!';

  @override
  String checkoutPartialSuccess(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'tokens',
      one: 'token',
    );
    return 'Only $count $_temp0 dispensed';
  }

  @override
  String checkoutPartialMessage(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'tokens',
      one: 'token',
    );
    return 'You have been charged for $count $_temp0 only.\nSorry for the inconvenience.';
  }

  @override
  String checkoutOriginalTotal(String amount) {
    return '(not $amount)';
  }

  @override
  String get checkoutPartialConfirm => 'All good!';

  @override
  String get checkoutReceiptUnavailable =>
      'Your purchase was recorded — the receipt details could not be loaded.';

  @override
  String get checkoutDone => 'Done';

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
  String balanceOpenTab(String amount) {
    return 'Open tab: $amount';
  }

  @override
  String balanceCredit(String amount) {
    return 'Credit: $amount';
  }

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
  String get noProducts => 'No products available';

  @override
  String get noCategories => 'No categories available';

  @override
  String get noProductsInCategory => 'No products in this category';

  @override
  String get productUnavailableDispenserOffline =>
      'Token dispenser currently unavailable';

  @override
  String get statusOnline => 'Online';

  @override
  String get statusOffline => 'Offline';

  @override
  String get statusError => 'Error';

  @override
  String get statusWarning => 'Warning';

  @override
  String get statusReaderOk => 'Reader OK';

  @override
  String get statusReaderMissing => 'Reader missing';

  @override
  String get cardReader => 'Card reader';

  @override
  String get cardReaderConnected => 'Connected';

  @override
  String get cardReaderDisconnected => 'Not connected';

  @override
  String get cardReaderNotMonitored => 'Not monitored';

  @override
  String get cardReaderLastSeen => 'Last detected';

  @override
  String get dispenser => 'Dispenser';

  @override
  String get backendEndpoint => 'Backend';

  @override
  String get dispenserEndpoint => 'Dispenser URL';

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
  String get continueShopping => 'Continue Shopping';

  @override
  String get rfidErrorUnknownCard =>
      'We don\'t know this chip — please register at the bar';

  @override
  String get rfidErrorAccountInactive =>
      'Your account is inactive — please see the bar staff';

  @override
  String get rfidErrorSepaMissing =>
      'Direct debit is not set up yet — please see the bar staff';

  @override
  String get rfidErrorDatabaseError =>
      'Something went wrong — please try again or see the bar staff';

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
  String get offlineLocalTransactionsOnly =>
      'Offline — showing recent purchases from this terminal; older history unavailable';

  @override
  String get noTransactions => 'No transactions yet';

  @override
  String get dispenserBusyTitle => 'Dispenser Busy';

  @override
  String get dispenserOfflineTitle => 'Cannot Connect to Dispenser';

  @override
  String get dispenserBusyMessage =>
      'Another customer is using the token dispenser.';

  @override
  String get dispenserOfflineMessage =>
      'The token dispenser is not responding.';

  @override
  String get dispenserBuyWithoutTokensHint =>
      'You can still purchase other items without tokens.';

  @override
  String get dispenserCancelButton => 'Cancel & Back to Cart';

  @override
  String get dispenserBuyWithoutTokensButton => 'Buy All Products But Tokens';

  @override
  String get dispenserUptime => 'Uptime';

  @override
  String get dispenserFirmware => 'Firmware';

  @override
  String get dispenserMachineState => 'Machine State';

  @override
  String get dispenserDispensed => 'Tokens Dispensed';

  @override
  String get dispenserSuccess => 'Tokens Requested';

  @override
  String get dispenserJams => 'Jams';

  @override
  String get dispenserFailures => 'Failures';

  @override
  String get dispenserNetwork => 'Network';

  @override
  String get dispenserSignalStrength => 'Signal Strength';

  @override
  String get dispenserErrorHistory => 'Error History';

  @override
  String get dispenserNoErrors => 'No errors recorded';

  @override
  String get dispenserErrorCleared => 'resolved';

  @override
  String get dispensingTokens => 'Dispensing Sauna Tokens...';

  @override
  String get pleaseWait => 'Please wait...';

  @override
  String get dispensingStarting => 'Starting dispenser...';

  @override
  String get dispensingConnecting => 'Connecting to dispenser...';

  @override
  String get dispensingFailed => 'Dispensing Failed';

  @override
  String dispensingSuccess(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'tokens',
      one: 'token',
    );
    return 'Successfully dispensed $count $_temp0!';
  }

  @override
  String dispensingPartialCharged(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'tokens',
      one: 'token',
    );
    return 'No worries! You\'ll only be charged for $count $_temp0.';
  }

  @override
  String get dispensingNeedsRefilling =>
      'Please notify staff - the dispenser may need refilling.';

  @override
  String get dispenserStateIdle => 'Idle';

  @override
  String get dispenserStateDispensing => 'Dispensing';

  @override
  String get dispenserStateDone => 'Done';

  @override
  String get dispenserStateError => 'Error';

  @override
  String get dispenserStateNotFound => 'Not Found';

  @override
  String get dispenserStateOffline => 'Offline';

  @override
  String get dispenserStateUnknown => 'Unknown';

  @override
  String get tabOverview => 'Overview';

  @override
  String get tabDispenserStatus => 'Dispenser Status';

  @override
  String get syncStatus => 'Sync Status';

  @override
  String get successRate => 'Success Rate';

  @override
  String get endpoints => 'Endpoints';

  @override
  String get dispenserNotAvailable => 'Dispenser not available';

  @override
  String get dispenserNetworkNotAvailable => 'Network info not available';

  @override
  String get dispenserLocalTransactionLog => 'Local Transaction Log';

  @override
  String get dispenserManualReconciliationRequired =>
      'Manual reconciliation required';

  @override
  String get dispenserInProgress => 'In progress';

  @override
  String dispenserStatusOnline(String state) {
    return 'Online ($state)';
  }

  @override
  String get sessionTimeoutWarningTitle => 'Still there?';

  @override
  String sessionTimeoutWarningBody(int seconds) {
    return 'You will be logged out automatically in $seconds s.';
  }

  @override
  String get sessionTimeoutContinue => 'Keep shopping';

  @override
  String get scanHintAlreadyLoggedIn => 'You are already logged in';

  @override
  String get scanHintLogOutFirst => 'Please log out first';

  @override
  String get scanHintTransactionInProgress =>
      'Please wait — transaction in progress';

  @override
  String get errorMembersRefreshFailed =>
      'Member data could not be updated — please try again in a moment';

  @override
  String get errorProductsRefreshFailed =>
      'The product list could not be updated — please try again in a moment';

  @override
  String get errorCheckoutFailed =>
      'Something went wrong — you have not been charged. Please try again.';

  @override
  String get errorBalanceLimitExceeded =>
      'Your limit is reached — please remove items or see the bar staff.';

  @override
  String get errorCheckoutCancelled =>
      'Checkout cancelled — your cart is unchanged.';

  @override
  String get errorTransactionCreateFailed =>
      'Your purchase could not be saved — you have not been charged. Please try again.';

  @override
  String get errorDispenserNotConfigured =>
      'The token dispenser is not set up — please see the bar staff.';

  @override
  String get errorDispenserOperationFailed =>
      'Token dispensing failed — please see the bar staff.';

  @override
  String get errorDispenserUnavailable =>
      'The token dispenser is not responding — you can still buy everything else.';

  @override
  String get errorDispenserNoTokensDispensed =>
      'No tokens came out — you have not been charged. Your cart is still there; please see the bar staff.';

  @override
  String get errorBackendUnreachable =>
      'No connection to the server — your purchases are saved here and sent later.';

  @override
  String get errorSyncFailed =>
      'Sync failed — your purchases are saved here and sent later.';

  @override
  String get errorTransactionSyncFailed =>
      'Some purchases have not reached the server yet — please tell the bar staff.';

  @override
  String get errorNoMemberSelected =>
      'Nobody is logged in — please hold your chip to the scanner.';

  @override
  String get errorTransactionHistoryFailed =>
      'Your purchases could not be loaded — please try again.';

  @override
  String get errorStatusLoadFailed =>
      'The terminal status could not be loaded — please try again.';

  @override
  String failedSalesWarning(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count sales were not saved — please report them to an admin',
      one: '1 sale was not saved — please report it to an admin',
    );
    return '$_temp0';
  }

  @override
  String get failedSalesTitle => 'Sales that were not saved';

  @override
  String get failedSalesInstruction =>
      'These sales never reached the server and will not be settled. Please report them to an admin.';

  @override
  String get failedSalesUnknownMember => 'Unknown member';
}
