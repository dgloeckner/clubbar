import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/intl.dart' as intl;

import 'app_localizations_de.dart';
import 'app_localizations_en.dart';

// ignore_for_file: type=lint

/// Callers can lookup localized strings with an instance of AppLocalizations
/// returned by `AppLocalizations.of(context)`.
///
/// Applications need to include `AppLocalizations.delegate()` in their app's
/// `localizationDelegates` list, and the locales they support in the app's
/// `supportedLocales` list. For example:
///
/// ```dart
/// import 'l10n/app_localizations.dart';
///
/// return MaterialApp(
///   localizationsDelegates: AppLocalizations.localizationsDelegates,
///   supportedLocales: AppLocalizations.supportedLocales,
///   home: MyApplicationHome(),
/// );
/// ```
///
/// ## Update pubspec.yaml
///
/// Please make sure to update your pubspec.yaml to include the following
/// packages:
///
/// ```yaml
/// dependencies:
///   # Internationalization support.
///   flutter_localizations:
///     sdk: flutter
///   intl: any # Use the pinned version from flutter_localizations
///
///   # Rest of dependencies
/// ```
///
/// ## iOS Applications
///
/// iOS applications define key application metadata, including supported
/// locales, in an Info.plist file that is built into the application bundle.
/// To configure the locales supported by your app, you’ll need to edit this
/// file.
///
/// First, open your project’s ios/Runner.xcworkspace Xcode workspace file.
/// Then, in the Project Navigator, open the Info.plist file under the Runner
/// project’s Runner folder.
///
/// Next, select the Information Property List item, select Add Item from the
/// Editor menu, then select Localizations from the pop-up menu.
///
/// Select and expand the newly-created Localizations item then, for each
/// locale your application supports, add a new item and select the locale
/// you wish to add from the pop-up menu in the Value field. This list should
/// be consistent with the languages listed in the AppLocalizations.supportedLocales
/// property.
abstract class AppLocalizations {
  AppLocalizations(String locale)
    : localeName = intl.Intl.canonicalizedLocale(locale.toString());

  final String localeName;

  static AppLocalizations? of(BuildContext context) {
    return Localizations.of<AppLocalizations>(context, AppLocalizations);
  }

  static const LocalizationsDelegate<AppLocalizations> delegate =
      _AppLocalizationsDelegate();

  /// A list of this localizations delegate along with the default localizations
  /// delegates.
  ///
  /// Returns a list of localizations delegates containing this delegate along with
  /// GlobalMaterialLocalizations.delegate, GlobalCupertinoLocalizations.delegate,
  /// and GlobalWidgetsLocalizations.delegate.
  ///
  /// Additional delegates can be added by appending to this list in
  /// MaterialApp. This list does not have to be used at all if a custom list
  /// of delegates is preferred or required.
  static const List<LocalizationsDelegate<dynamic>> localizationsDelegates =
      <LocalizationsDelegate<dynamic>>[
        delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
      ];

  /// A list of this localizations delegate's supported locales.
  static const List<Locale> supportedLocales = <Locale>[
    Locale('de'),
    Locale('en'),
  ];

  /// Main title on idle/waiting screen
  ///
  /// In de, this message translates to:
  /// **'Durstig?'**
  String get idleTitle;

  /// Instruction to scan RFID card
  ///
  /// In de, this message translates to:
  /// **'Halte deinen Chip an den Scanner'**
  String get idleSubtitle;

  /// Demo button to simulate card scan
  ///
  /// In de, this message translates to:
  /// **'Demo: Chip scannen'**
  String get demoScanCard;

  /// Setup screen title
  ///
  /// In de, this message translates to:
  /// **'Terminal-Einrichtung'**
  String get setupTitle;

  /// Setup screen subtitle
  ///
  /// In de, this message translates to:
  /// **'Verbinde dieses Terminal mit dem Club Bar-Backend.'**
  String get setupSubtitle;

  /// Label for terminal ID field
  ///
  /// In de, this message translates to:
  /// **'Terminal-ID'**
  String get terminalIdLabel;

  /// Validation error for empty terminal ID
  ///
  /// In de, this message translates to:
  /// **'Terminal-ID ist erforderlich'**
  String get terminalIdRequired;

  /// Label for API URL field
  ///
  /// In de, this message translates to:
  /// **'API-URL'**
  String get apiUrlLabel;

  /// Validation error for empty API URL
  ///
  /// In de, this message translates to:
  /// **'API-URL ist erforderlich'**
  String get apiUrlRequired;

  /// Validation error for invalid API URL format
  ///
  /// In de, this message translates to:
  /// **'Gib eine gültige URL ein (z.B. https://club.example.com/api)'**
  String get apiUrlInvalid;

  /// Label for API token field
  ///
  /// In de, this message translates to:
  /// **'API-Token'**
  String get apiTokenLabel;

  /// Validation error for empty API token
  ///
  /// In de, this message translates to:
  /// **'API-Token ist erforderlich'**
  String get apiTokenRequired;

  /// Button to save settings and connect to backend
  ///
  /// In de, this message translates to:
  /// **'Speichern & Verbinden'**
  String get saveAndConnect;

  /// Error message when connection fails
  ///
  /// In de, this message translates to:
  /// **'Verbindung fehlgeschlagen: {error}'**
  String connectionFailed(String error);

  /// Message shown when cart is empty
  ///
  /// In de, this message translates to:
  /// **'Dein Warenkorb ist leer'**
  String get cartEmpty;

  /// Label for cart total
  ///
  /// In de, this message translates to:
  /// **'Gesamt'**
  String get cartTotal;

  /// Projected open tab after checkout
  ///
  /// In de, this message translates to:
  /// **'Neuer offener Betrag: {amount}'**
  String newBalanceOpenTab(String amount);

  /// Projected credit after checkout
  ///
  /// In de, this message translates to:
  /// **'Neues Guthaben: {amount}'**
  String newBalanceCredit(String amount);

  /// Unit price label
  ///
  /// In de, this message translates to:
  /// **'{price} pro Stück'**
  String cartEachPrice(String price);

  /// Checkout button label
  ///
  /// In de, this message translates to:
  /// **'Bezahlen'**
  String get checkout;

  /// Checkout button label while the checkout is in flight
  ///
  /// In de, this message translates to:
  /// **'Wird verarbeitet…'**
  String get checkoutProcessing;

  /// Checkout button label when the credit limit blocks the purchase
  ///
  /// In de, this message translates to:
  /// **'Limit erreicht'**
  String get checkoutBlockedByLimit;

  /// Cart banner headline when the projected tab exceeds the credit limit
  ///
  /// In de, this message translates to:
  /// **'Limit erreicht – bitte Artikel entfernen, um fortzufahren.'**
  String get creditLimitReached;

  /// Cart banner headline when the projected tab is inside the warning band
  ///
  /// In de, this message translates to:
  /// **'Du näherst dich deinem Limit.'**
  String get creditLimitApproaching;

  /// Credit limit banner: the member's tab before this cart
  ///
  /// In de, this message translates to:
  /// **'Aktueller Betrag: {amount}'**
  String creditLimitCurrent(String amount);

  /// Credit limit banner: what the cart would add
  ///
  /// In de, this message translates to:
  /// **'Warenkorb: {amount}'**
  String creditLimitCart(String amount);

  /// Credit limit banner: the configured ceiling
  ///
  /// In de, this message translates to:
  /// **'Maximum: {amount}'**
  String creditLimitMaximum(String amount);

  /// Success message after checkout
  ///
  /// In de, this message translates to:
  /// **'Buchung erfolgreich!'**
  String get checkoutSuccess;

  /// Title for partial dispense confirmation
  ///
  /// In de, this message translates to:
  /// **'Nur {count} {count, plural, =1{Token} other{Tokens}} ausgegeben'**
  String checkoutPartialSuccess(int count);

  /// Message explaining partial dispense
  ///
  /// In de, this message translates to:
  /// **'Du wurdest nur für {count} {count, plural, =1{Token} other{Tokens}} belastet.\nWir bitten um Entschuldigung.'**
  String checkoutPartialMessage(int count);

  /// Shows crossed-out original amount for partial dispense
  ///
  /// In de, this message translates to:
  /// **'(nicht {amount})'**
  String checkoutOriginalTotal(String amount);

  /// No description provided for @checkoutPartialConfirm.
  ///
  /// In de, this message translates to:
  /// **'Alles klar!'**
  String get checkoutPartialConfirm;

  /// Shown on the confirmation screen when the receipt details could not be loaded
  ///
  /// In de, this message translates to:
  /// **'Dein Kauf wurde gebucht — die Belegdaten konnten nicht geladen werden.'**
  String get checkoutReceiptUnavailable;

  /// Dismisses the confirmation screen after a successful purchase
  ///
  /// In de, this message translates to:
  /// **'Fertig'**
  String get checkoutDone;

  /// Countdown message before redirecting
  ///
  /// In de, this message translates to:
  /// **'Weiterleitung in {seconds} {seconds, plural, =1{Sekunde} other{Sekunden}}...'**
  String redirectingIn(int seconds);

  /// Member details screen title
  ///
  /// In de, this message translates to:
  /// **'Mitgliedsdetails'**
  String get memberDetails;

  /// Label for first name
  ///
  /// In de, this message translates to:
  /// **'Vorname'**
  String get firstName;

  /// Label for last name
  ///
  /// In de, this message translates to:
  /// **'Nachname'**
  String get lastName;

  /// Label for account status
  ///
  /// In de, this message translates to:
  /// **'Kontostatus'**
  String get accountStatus;

  /// Label for active member status
  ///
  /// In de, this message translates to:
  /// **'Aktiv'**
  String get memberActive;

  /// Label for inactive member status
  ///
  /// In de, this message translates to:
  /// **'Inaktiv'**
  String get memberInactive;

  /// Yes label for SEPA status
  ///
  /// In de, this message translates to:
  /// **'Ja'**
  String get sepaYes;

  /// No label for SEPA status
  ///
  /// In de, this message translates to:
  /// **'Nein'**
  String get sepaNo;

  /// Greeting message with member name
  ///
  /// In de, this message translates to:
  /// **'Willkommen, {name}'**
  String welcomeMember(String name);

  /// Member owes this amount (open tab)
  ///
  /// In de, this message translates to:
  /// **'Offener Betrag: {amount}'**
  String balanceOpenTab(String amount);

  /// Member has this amount in credit
  ///
  /// In de, this message translates to:
  /// **'Guthaben: {amount}'**
  String balanceCredit(String amount);

  /// Button to view member details
  ///
  /// In de, this message translates to:
  /// **'Details'**
  String get viewDetails;

  /// Logout button label
  ///
  /// In de, this message translates to:
  /// **'Abmelden'**
  String get logout;

  /// Error modal title
  ///
  /// In de, this message translates to:
  /// **'Fehler'**
  String get errorTitle;

  /// Dismiss button label
  ///
  /// In de, this message translates to:
  /// **'Schließen'**
  String get dismiss;

  /// Retry button label
  ///
  /// In de, this message translates to:
  /// **'Erneut versuchen'**
  String get retry;

  /// Default category name when translation missing
  ///
  /// In de, this message translates to:
  /// **'Kategorie'**
  String get categoryDefault;

  /// Default product name when translation missing
  ///
  /// In de, this message translates to:
  /// **'Produkt'**
  String get productDefault;

  /// Message when no products in category
  ///
  /// In de, this message translates to:
  /// **'Keine Produkte verfügbar'**
  String get noProducts;

  /// Message when no categories available
  ///
  /// In de, this message translates to:
  /// **'Keine Kategorien verfügbar'**
  String get noCategories;

  /// Message when selected category has no products
  ///
  /// In de, this message translates to:
  /// **'Keine Produkte in dieser Kategorie'**
  String get noProductsInCategory;

  /// Note on a product card whose token dispenser is offline or faulty
  ///
  /// In de, this message translates to:
  /// **'Token-Ausgabe zurzeit nicht verfügbar'**
  String get productUnavailableDispenserOffline;

  /// Connection status: online
  ///
  /// In de, this message translates to:
  /// **'Online'**
  String get statusOnline;

  /// Connection status: offline
  ///
  /// In de, this message translates to:
  /// **'Offline'**
  String get statusOffline;

  /// Connection status: error
  ///
  /// In de, this message translates to:
  /// **'Fehler'**
  String get statusError;

  /// Connection status: warning (e.g. dispenser offline)
  ///
  /// In de, this message translates to:
  /// **'Warnung'**
  String get statusWarning;

  /// Token dispenser hardware label
  ///
  /// In de, this message translates to:
  /// **'Ausgabegerät'**
  String get dispenser;

  /// Backend API endpoint label
  ///
  /// In de, this message translates to:
  /// **'Backend'**
  String get backendEndpoint;

  /// Dispenser hardware endpoint URL label
  ///
  /// In de, this message translates to:
  /// **'Ausgabegerät-URL'**
  String get dispenserEndpoint;

  /// Used for timestamps that never occurred
  ///
  /// In de, this message translates to:
  /// **'Nie'**
  String get never;

  /// Label for last sync timestamp
  ///
  /// In de, this message translates to:
  /// **'Letzte Synchronisation'**
  String get lastSync;

  /// Label for last transaction sync timestamp
  ///
  /// In de, this message translates to:
  /// **'Letzte Transaktions-Sync'**
  String get lastTransactionSync;

  /// Label for retry count
  ///
  /// In de, this message translates to:
  /// **'Wiederholungsversuche'**
  String get retryCount;

  /// Label for error details section
  ///
  /// In de, this message translates to:
  /// **'Fehlerdetails'**
  String get errorDetails;

  /// Loading indicator text
  ///
  /// In de, this message translates to:
  /// **'Laden...'**
  String get loading;

  /// Cancel button label
  ///
  /// In de, this message translates to:
  /// **'Abbrechen'**
  String get cancel;

  /// Save button label
  ///
  /// In de, this message translates to:
  /// **'Speichern'**
  String get save;

  /// Button to continue shopping
  ///
  /// In de, this message translates to:
  /// **'Weiter einkaufen'**
  String get continueShopping;

  /// Button to scan card
  ///
  /// In de, this message translates to:
  /// **'Chip scannen'**
  String get scanCard;

  /// Error when scanned card is not in database
  ///
  /// In de, this message translates to:
  /// **'Wir kennen diesen Chip nicht – bitte an der Bar anmelden'**
  String get rfidErrorUnknownCard;

  /// Error when member account is inactive
  ///
  /// In de, this message translates to:
  /// **'Dein Konto ist inaktiv – bitte beim Bar-Team melden'**
  String get rfidErrorAccountInactive;

  /// Error when member has no valid SEPA mandate
  ///
  /// In de, this message translates to:
  /// **'Lastschrift ist noch nicht eingerichtet – bitte beim Bar-Team melden'**
  String get rfidErrorSepaMissing;

  /// Error when database query fails
  ///
  /// In de, this message translates to:
  /// **'Etwas ist schiefgelaufen – bitte erneut versuchen oder beim Bar-Team melden'**
  String get rfidErrorDatabaseError;

  /// Label for preferred language setting
  ///
  /// In de, this message translates to:
  /// **'Bevorzugte Sprache'**
  String get preferredLanguage;

  /// Header for recent transactions list
  ///
  /// In de, this message translates to:
  /// **'Letzte Transaktionen'**
  String get recentTransactions;

  /// Loading message for transactions
  ///
  /// In de, this message translates to:
  /// **'Transaktionen werden geladen...'**
  String get loadingTransactions;

  /// Error message when transaction loading fails
  ///
  /// In de, this message translates to:
  /// **'Fehler beim Laden der Transaktionen'**
  String get errorLoadingTransactions;

  /// Title for offline mode
  ///
  /// In de, this message translates to:
  /// **'Offline-Modus'**
  String get offlineMode;

  /// Message when transaction history cannot be loaded offline
  ///
  /// In de, this message translates to:
  /// **'Transaktionshistorie offline nicht verfügbar'**
  String get transactionHistoryUnavailableOffline;

  /// Banner above the transaction list when offline and only locally recorded transactions can be shown
  ///
  /// In de, this message translates to:
  /// **'Offline — nur Käufe von diesem Terminal, ältere Historie nicht verfügbar'**
  String get offlineLocalTransactionsOnly;

  /// Message when member has no transactions yet
  ///
  /// In de, this message translates to:
  /// **'Noch keine Transaktionen'**
  String get noTransactions;

  /// Title for dispenser busy error dialog
  ///
  /// In de, this message translates to:
  /// **'Ausgabegerät belegt'**
  String get dispenserBusyTitle;

  /// Title for dispenser offline error dialog
  ///
  /// In de, this message translates to:
  /// **'Ausgabegerät nicht erreichbar'**
  String get dispenserOfflineTitle;

  /// Message explaining dispenser is busy
  ///
  /// In de, this message translates to:
  /// **'Ein anderer Kunde nutzt gerade das Token-Ausgabegerät.'**
  String get dispenserBusyMessage;

  /// Message explaining dispenser is offline
  ///
  /// In de, this message translates to:
  /// **'Das Token-Ausgabegerät antwortet nicht.'**
  String get dispenserOfflineMessage;

  /// Hint that user can still purchase non-token items
  ///
  /// In de, this message translates to:
  /// **'Du kannst andere Artikel ohne Token kaufen.'**
  String get dispenserBuyWithoutTokensHint;

  /// Button to cancel checkout and return to cart
  ///
  /// In de, this message translates to:
  /// **'Abbrechen & zurück zum Warenkorb'**
  String get dispenserCancelButton;

  /// Button to proceed with checkout excluding token products
  ///
  /// In de, this message translates to:
  /// **'Alle Produkte außer Tokens kaufen'**
  String get dispenserBuyWithoutTokensButton;

  /// Dispenser uptime label
  ///
  /// In de, this message translates to:
  /// **'Betriebszeit'**
  String get dispenserUptime;

  /// Firmware version label
  ///
  /// In de, this message translates to:
  /// **'Firmware'**
  String get dispenserFirmware;

  /// Machine state section title
  ///
  /// In de, this message translates to:
  /// **'Maschinenstatus'**
  String get dispenserMachineState;

  /// Total tokens dispensed metric label
  ///
  /// In de, this message translates to:
  /// **'Token ausgegeben'**
  String get dispenserDispensed;

  /// Total tokens requested metric label
  ///
  /// In de, this message translates to:
  /// **'Token angefordert'**
  String get dispenserSuccess;

  /// Jam count metric label
  ///
  /// In de, this message translates to:
  /// **'Staus'**
  String get dispenserJams;

  /// Failure count metric label
  ///
  /// In de, this message translates to:
  /// **'Fehler'**
  String get dispenserFailures;

  /// Network section title
  ///
  /// In de, this message translates to:
  /// **'Netzwerk'**
  String get dispenserNetwork;

  /// WiFi signal strength label
  ///
  /// In de, this message translates to:
  /// **'Signalstärke'**
  String get dispenserSignalStrength;

  /// Error history section title
  ///
  /// In de, this message translates to:
  /// **'Fehlerhistorie'**
  String get dispenserErrorHistory;

  /// Message when no errors in history
  ///
  /// In de, this message translates to:
  /// **'Keine Fehler aufgezeichnet'**
  String get dispenserNoErrors;

  /// Label for cleared/resolved errors
  ///
  /// In de, this message translates to:
  /// **'behoben'**
  String get dispenserErrorCleared;

  /// Progress dialog title while dispensing tokens
  ///
  /// In de, this message translates to:
  /// **'Sauna-Token werden ausgegeben...'**
  String get dispensingTokens;

  /// Please wait message during dispensing
  ///
  /// In de, this message translates to:
  /// **'Bitte warten...'**
  String get pleaseWait;

  /// Progress dialog title while initiating dispense request
  ///
  /// In de, this message translates to:
  /// **'Ausgabegerät wird gestartet...'**
  String get dispensingStarting;

  /// Status message while connecting to dispenser
  ///
  /// In de, this message translates to:
  /// **'Verbindung zum Ausgabegerät...'**
  String get dispensingConnecting;

  /// Title shown when dispensing failed
  ///
  /// In de, this message translates to:
  /// **'Ausgabe fehlgeschlagen'**
  String get dispensingFailed;

  /// Success message after dispensing, with token count
  ///
  /// In de, this message translates to:
  /// **'{count} {count, plural, =1{Token} other{Tokens}} erfolgreich ausgegeben!'**
  String dispensingSuccess(int count);

  /// Message for partial dispense explaining reduced charge
  ///
  /// In de, this message translates to:
  /// **'Keine Sorge! Du wirst nur für {count} {count, plural, =1{Token} other{Tokens}} belastet.'**
  String dispensingPartialCharged(int count);

  /// Warning to notify staff that dispenser may need refilling
  ///
  /// In de, this message translates to:
  /// **'Bitte Personal informieren – der Ausgabeautomat muss möglicherweise nachgefüllt werden.'**
  String get dispensingNeedsRefilling;

  /// Dispenser machine state: idle/ready
  ///
  /// In de, this message translates to:
  /// **'Bereit'**
  String get dispenserStateIdle;

  /// Dispenser machine state: currently dispensing
  ///
  /// In de, this message translates to:
  /// **'Ausgabe läuft'**
  String get dispenserStateDispensing;

  /// Dispenser machine state: dispensing completed
  ///
  /// In de, this message translates to:
  /// **'Fertig'**
  String get dispenserStateDone;

  /// Dispenser machine state: error
  ///
  /// In de, this message translates to:
  /// **'Fehler'**
  String get dispenserStateError;

  /// Dispenser machine state: transaction not found on hardware
  ///
  /// In de, this message translates to:
  /// **'Nicht gefunden'**
  String get dispenserStateNotFound;

  /// Dispenser machine state: offline/unreachable
  ///
  /// In de, this message translates to:
  /// **'Offline'**
  String get dispenserStateOffline;

  /// Dispenser machine state: unknown/unexpected value
  ///
  /// In de, this message translates to:
  /// **'Unbekannt'**
  String get dispenserStateUnknown;

  /// No description provided for @tabOverview.
  ///
  /// In de, this message translates to:
  /// **'Übersicht'**
  String get tabOverview;

  /// No description provided for @tabDispenserStatus.
  ///
  /// In de, this message translates to:
  /// **'Ausgabegerät-Status'**
  String get tabDispenserStatus;

  /// No description provided for @syncStatus.
  ///
  /// In de, this message translates to:
  /// **'Sync-Status'**
  String get syncStatus;

  /// No description provided for @successRate.
  ///
  /// In de, this message translates to:
  /// **'Erfolgsquote'**
  String get successRate;

  /// No description provided for @endpoints.
  ///
  /// In de, this message translates to:
  /// **'Endpunkte'**
  String get endpoints;

  /// No description provided for @dispenserNotAvailable.
  ///
  /// In de, this message translates to:
  /// **'Ausgabegerät nicht verfügbar'**
  String get dispenserNotAvailable;

  /// No description provided for @dispenserNetworkNotAvailable.
  ///
  /// In de, this message translates to:
  /// **'Netzwerkinfo nicht verfügbar'**
  String get dispenserNetworkNotAvailable;

  /// No description provided for @dispenserLocalTransactionLog.
  ///
  /// In de, this message translates to:
  /// **'Lokales Transaktionsprotokoll'**
  String get dispenserLocalTransactionLog;

  /// No description provided for @dispenserManualReconciliationRequired.
  ///
  /// In de, this message translates to:
  /// **'Manuelle Abstimmung erforderlich'**
  String get dispenserManualReconciliationRequired;

  /// No description provided for @dispenserInProgress.
  ///
  /// In de, this message translates to:
  /// **'In Bearbeitung'**
  String get dispenserInProgress;

  /// No description provided for @dispenserStatusOnline.
  ///
  /// In de, this message translates to:
  /// **'Online ({state})'**
  String dispenserStatusOnline(String state);

  /// No description provided for @sessionTimeoutWarningTitle.
  ///
  /// In de, this message translates to:
  /// **'Noch da?'**
  String get sessionTimeoutWarningTitle;

  /// No description provided for @sessionTimeoutWarningBody.
  ///
  /// In de, this message translates to:
  /// **'Du wirst in {seconds} s automatisch abgemeldet.'**
  String sessionTimeoutWarningBody(int seconds);

  /// No description provided for @sessionTimeoutContinue.
  ///
  /// In de, this message translates to:
  /// **'Weiter einkaufen'**
  String get sessionTimeoutContinue;

  /// ScanHintKey.alreadyLoggedIn — active member re-tapped their own card (ADR-0027 rule 4)
  ///
  /// In de, this message translates to:
  /// **'Du bist bereits angemeldet'**
  String get scanHintAlreadyLoggedIn;

  /// ScanHintKey.logOutFirst — foreign card tapped during an active session (ADR-0027 rule 3)
  ///
  /// In de, this message translates to:
  /// **'Bitte zuerst abmelden'**
  String get scanHintLogOutFirst;

  /// ScanHintKey.transactionInProgress — scan refused while a checkout or dispense is in flight (ADR-0027 rule 7)
  ///
  /// In de, this message translates to:
  /// **'Bitte warten – Bezahlung läuft'**
  String get scanHintTransactionInProgress;

  /// TerminalErrorKey.membersRefreshFailed — member cache refresh failed
  ///
  /// In de, this message translates to:
  /// **'Mitgliederdaten konnten nicht aktualisiert werden – bitte gleich noch einmal versuchen'**
  String get errorMembersRefreshFailed;

  /// TerminalErrorKey.productsRefreshFailed — product cache refresh failed
  ///
  /// In de, this message translates to:
  /// **'Die Produktliste konnte nicht aktualisiert werden – bitte gleich noch einmal versuchen'**
  String get errorProductsRefreshFailed;

  /// TerminalErrorKey.checkoutFailed — unexpected failure during checkout
  ///
  /// In de, this message translates to:
  /// **'Etwas ist schiefgelaufen – dir wurde nichts berechnet. Bitte erneut versuchen.'**
  String get errorCheckoutFailed;

  /// TerminalErrorKey.balanceLimitExceeded — checkout would push the tab past the credit limit
  ///
  /// In de, this message translates to:
  /// **'Dein Limit ist erreicht – bitte Artikel entfernen oder beim Bar-Team melden.'**
  String get errorBalanceLimitExceeded;

  /// TerminalErrorKey.checkoutCancelled — member cancelled the checkout
  ///
  /// In de, this message translates to:
  /// **'Bezahlung abgebrochen – dein Warenkorb bleibt unverändert.'**
  String get errorCheckoutCancelled;

  /// TerminalErrorKey.transactionCreateFailed — transaction could not be written locally
  ///
  /// In de, this message translates to:
  /// **'Dein Einkauf konnte nicht gespeichert werden – dir wurde nichts berechnet. Bitte erneut versuchen.'**
  String get errorTransactionCreateFailed;

  /// TerminalErrorKey.dispenserNotConfigured — token product in cart but dispenser disabled
  ///
  /// In de, this message translates to:
  /// **'Der Token-Automat ist nicht eingerichtet – bitte beim Bar-Team melden.'**
  String get errorDispenserNotConfigured;

  /// TerminalErrorKey.dispenserOperationFailed — dispenser bookkeeping failed
  ///
  /// In de, this message translates to:
  /// **'Die Token-Ausgabe hat nicht geklappt – bitte beim Bar-Team melden.'**
  String get errorDispenserOperationFailed;

  /// TerminalErrorKey.dispenserUnavailable — dispenser did not respond as expected
  ///
  /// In de, this message translates to:
  /// **'Der Token-Automat antwortet nicht – alles andere kannst du trotzdem kaufen.'**
  String get errorDispenserUnavailable;

  /// TerminalErrorKey.dispenserNoTokensDispensed — dispense finished with zero tokens; checkout aborted
  ///
  /// In de, this message translates to:
  /// **'Es sind keine Token herausgekommen – dir wurde nichts berechnet. Dein Warenkorb bleibt erhalten; bitte beim Bar-Team melden.'**
  String get errorDispenserNoTokensDispensed;

  /// TerminalErrorKey.backendUnreachable — backend health check failed
  ///
  /// In de, this message translates to:
  /// **'Keine Verbindung zum Server – deine Einkäufe werden hier gespeichert und später übertragen.'**
  String get errorBackendUnreachable;

  /// TerminalErrorKey.syncFailed — a sync cycle did not complete
  ///
  /// In de, this message translates to:
  /// **'Synchronisierung fehlgeschlagen – deine Einkäufe werden hier gespeichert und später übertragen.'**
  String get errorSyncFailed;

  /// TerminalErrorKey.transactionSyncFailed — non-fatal transaction upload failure
  ///
  /// In de, this message translates to:
  /// **'Einige Einkäufe sind noch nicht beim Server angekommen – bitte dem Bar-Team Bescheid geben.'**
  String get errorTransactionSyncFailed;

  /// TerminalErrorKey.noMemberSelected — a member-scoped view opened without a logged-in member
  ///
  /// In de, this message translates to:
  /// **'Es ist niemand angemeldet – bitte den Chip an den Scanner halten.'**
  String get errorNoMemberSelected;

  /// TerminalErrorKey.transactionHistoryFailed — transaction history could not be fetched
  ///
  /// In de, this message translates to:
  /// **'Deine Einkäufe konnten nicht geladen werden – bitte erneut versuchen.'**
  String get errorTransactionHistoryFailed;

  /// TerminalErrorKey.statusLoadFailed — status modal could not gather terminal state
  ///
  /// In de, this message translates to:
  /// **'Der Terminal-Status konnte nicht geladen werden – bitte erneut versuchen.'**
  String get errorStatusLoadFailed;
}

class _AppLocalizationsDelegate
    extends LocalizationsDelegate<AppLocalizations> {
  const _AppLocalizationsDelegate();

  @override
  Future<AppLocalizations> load(Locale locale) {
    return SynchronousFuture<AppLocalizations>(lookupAppLocalizations(locale));
  }

  @override
  bool isSupported(Locale locale) =>
      <String>['de', 'en'].contains(locale.languageCode);

  @override
  bool shouldReload(_AppLocalizationsDelegate old) => false;
}

AppLocalizations lookupAppLocalizations(Locale locale) {
  // Lookup logic when only language code is specified.
  switch (locale.languageCode) {
    case 'de':
      return AppLocalizationsDe();
    case 'en':
      return AppLocalizationsEn();
  }

  throw FlutterError(
    'AppLocalizations.delegate failed to load unsupported locale "$locale". This is likely '
    'an issue with the localizations generation tool. Please file an issue '
    'on GitHub with a reproducible sample app and the gen-l10n configuration '
    'that was used.',
  );
}
