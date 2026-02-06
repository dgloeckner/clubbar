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
  /// **'Halte deine Karte an den Scanner'**
  String get idleSubtitle;

  /// Demo button to simulate card scan
  ///
  /// In de, this message translates to:
  /// **'Demo: Karte scannen'**
  String get demoScanCard;

  /// Setup screen title
  ///
  /// In de, this message translates to:
  /// **'Terminal-Einrichtung'**
  String get setupTitle;

  /// Setup screen subtitle
  ///
  /// In de, this message translates to:
  /// **'Verbinde dieses Terminal mit dem Ruderbar-Backend.'**
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

  /// Shows new balance after checkout
  ///
  /// In de, this message translates to:
  /// **'Neuer Kontostand: {balance}'**
  String cartNewBalance(String balance);

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

  /// Error when trying to checkout without member
  ///
  /// In de, this message translates to:
  /// **'Kein Mitglied ausgewählt'**
  String get memberNotSelected;

  /// Success message after checkout
  ///
  /// In de, this message translates to:
  /// **'Buchung erfolgreich!'**
  String get checkoutSuccess;

  /// Shows new balance on confirmation screen
  ///
  /// In de, this message translates to:
  /// **'Neuer Kontostand: {balance}'**
  String checkoutNewBalance(String balance);

  /// Countdown message before redirecting
  ///
  /// In de, this message translates to:
  /// **'Weiterleitung in {seconds} {seconds, plural, =1{Sekunde} other{Sekunden}}...'**
  String redirectingIn(int seconds);

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

  /// Message when no member is selected
  ///
  /// In de, this message translates to:
  /// **'Kein Mitglied ausgewählt'**
  String get noMemberSelected;

  /// Greeting message with member name
  ///
  /// In de, this message translates to:
  /// **'Willkommen, {name}'**
  String welcomeMember(String name);

  /// Label for member balance
  ///
  /// In de, this message translates to:
  /// **'Kontostand'**
  String get balance;

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
  /// **'Karte scannen'**
  String get scanCard;
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
