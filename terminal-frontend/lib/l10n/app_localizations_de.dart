// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for German (`de`).
class AppLocalizationsDe extends AppLocalizations {
  AppLocalizationsDe([String locale = 'de']) : super(locale);

  @override
  String get idleTitle => 'Durstig?';

  @override
  String get idleSubtitle => 'Halte deine Karte an den Scanner';

  @override
  String get demoScanCard => 'Demo: Karte scannen';

  @override
  String get setupTitle => 'Terminal-Einrichtung';

  @override
  String get setupSubtitle =>
      'Verbinde dieses Terminal mit dem Ruderbar-Backend.';

  @override
  String get terminalIdLabel => 'Terminal-ID';

  @override
  String get terminalIdRequired => 'Terminal-ID ist erforderlich';

  @override
  String get apiUrlLabel => 'API-URL';

  @override
  String get apiUrlRequired => 'API-URL ist erforderlich';

  @override
  String get apiUrlInvalid =>
      'Gib eine gültige URL ein (z.B. https://club.example.com/api)';

  @override
  String get apiTokenLabel => 'API-Token';

  @override
  String get apiTokenRequired => 'API-Token ist erforderlich';

  @override
  String get saveAndConnect => 'Speichern & Verbinden';

  @override
  String connectionFailed(String error) {
    return 'Verbindung fehlgeschlagen: $error';
  }

  @override
  String get cartEmpty => 'Dein Warenkorb ist leer';

  @override
  String get cartTotal => 'Gesamt';

  @override
  String cartNewBalance(String balance) {
    return 'Neuer Kontostand: $balance';
  }

  @override
  String cartEachPrice(String price) {
    return '$price pro Stück';
  }

  @override
  String get checkout => 'Bezahlen';

  @override
  String get memberNotSelected => 'Kein Mitglied ausgewählt';

  @override
  String get checkoutSuccess => 'Buchung erfolgreich!';

  @override
  String checkoutNewBalance(String balance) {
    return 'Neuer Kontostand: $balance';
  }

  @override
  String redirectingIn(int seconds) {
    String _temp0 = intl.Intl.pluralLogic(
      seconds,
      locale: localeName,
      other: 'Sekunden',
      one: 'Sekunde',
    );
    return 'Weiterleitung in $seconds $_temp0...';
  }

  @override
  String get memberDetails => 'Mitgliedsdetails';

  @override
  String get firstName => 'Vorname';

  @override
  String get lastName => 'Nachname';

  @override
  String get accountStatus => 'Kontostatus';

  @override
  String get memberActive => 'Aktiv';

  @override
  String get memberInactive => 'Inaktiv';

  @override
  String get sepaYes => 'Ja';

  @override
  String get sepaNo => 'Nein';

  @override
  String get noMemberSelected => 'Kein Mitglied ausgewählt';

  @override
  String welcomeMember(String name) {
    return 'Willkommen, $name';
  }

  @override
  String get balance => 'Kontostand';

  @override
  String get viewDetails => 'Details';

  @override
  String get logout => 'Abmelden';

  @override
  String get errorTitle => 'Fehler';

  @override
  String get dismiss => 'Schließen';

  @override
  String get retry => 'Erneut versuchen';

  @override
  String get categoryDefault => 'Kategorie';

  @override
  String get productDefault => 'Produkt';

  @override
  String get noProducts => 'Keine Produkte verfügbar';

  @override
  String get noCategories => 'Keine Kategorien verfügbar';

  @override
  String get noProductsInCategory => 'Keine Produkte in dieser Kategorie';

  @override
  String get statusOnline => 'Online';

  @override
  String get statusOffline => 'Offline';

  @override
  String get statusError => 'Fehler';

  @override
  String get never => 'Nie';

  @override
  String get lastSync => 'Letzte Synchronisation';

  @override
  String get lastTransactionSync => 'Letzte Transaktions-Sync';

  @override
  String get retryCount => 'Wiederholungsversuche';

  @override
  String get errorDetails => 'Fehlerdetails';

  @override
  String get loading => 'Laden...';

  @override
  String get cancel => 'Abbrechen';

  @override
  String get save => 'Speichern';

  @override
  String get continueShopping => 'Weiter einkaufen';

  @override
  String get scanCard => 'Karte scannen';

  @override
  String get rfidErrorUnknownCard => 'Unbekannte Karte';

  @override
  String get rfidErrorAccountInactive => 'Konto inaktiv';

  @override
  String get rfidErrorSepaMissing => 'SEPA-Mandat fehlt';

  @override
  String get rfidErrorDatabaseError => 'Datenbankfehler';

  @override
  String get preferredLanguage => 'Bevorzugte Sprache';

  @override
  String get recentTransactions => 'Letzte Transaktionen';

  @override
  String get loadingTransactions => 'Transaktionen werden geladen...';

  @override
  String get errorLoadingTransactions => 'Fehler beim Laden der Transaktionen';

  @override
  String get offlineMode => 'Offline-Modus';

  @override
  String get transactionHistoryUnavailableOffline =>
      'Transaktionshistorie offline nicht verfügbar';

  @override
  String get noTransactions => 'Noch keine Transaktionen';
}
