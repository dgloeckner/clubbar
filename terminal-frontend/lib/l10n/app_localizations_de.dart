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
  String checkoutPartialSuccess(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'Tokens',
      one: 'Token',
    );
    return 'Nur $count $_temp0 ausgegeben';
  }

  @override
  String checkoutPartialMessage(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'Tokens',
      one: 'Token',
    );
    return 'Du wurdest nur für $count $_temp0 belastet.\nWir bitten um Entschuldigung.';
  }

  @override
  String checkoutOriginalTotal(String amount) {
    return '(nicht $amount)';
  }

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
  String get statusWarning => 'Warnung';

  @override
  String get dispenser => 'Ausgabegerät';

  @override
  String get backendEndpoint => 'Backend';

  @override
  String get dispenserEndpoint => 'Ausgabegerät-URL';

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

  @override
  String get dispenserBusyTitle => 'Ausgabegerät belegt';

  @override
  String get dispenserOfflineTitle => 'Ausgabegerät nicht erreichbar';

  @override
  String get dispenserBusyMessage =>
      'Ein anderer Kunde nutzt gerade das Token-Ausgabegerät.';

  @override
  String get dispenserOfflineMessage =>
      'Das Token-Ausgabegerät antwortet nicht.';

  @override
  String get dispenserBuyWithoutTokensHint =>
      'Sie können andere Artikel ohne Tokens kaufen.';

  @override
  String get dispenserCancelButton => 'Abbrechen & zurück zum Warenkorb';

  @override
  String get dispenserBuyWithoutTokensButton =>
      'Alle Produkte außer Tokens kaufen';

  @override
  String get dispenserUptime => 'Betriebszeit';

  @override
  String get dispenserFirmware => 'Firmware';

  @override
  String get dispenserMachineState => 'Maschinenstatus';

  @override
  String get dispenserDispensed => 'Token ausgegeben';

  @override
  String get dispenserSuccess => 'Token angefordert';

  @override
  String get dispenserJams => 'Staus';

  @override
  String get dispenserFailures => 'Fehler';

  @override
  String get dispenserNetwork => 'Netzwerk';

  @override
  String get dispenserSignalStrength => 'Signalstärke';

  @override
  String get dispenserErrorHistory => 'Fehlerhistorie';

  @override
  String get dispenserNoErrors => 'Keine Fehler aufgezeichnet';

  @override
  String get dispenserErrorCleared => 'behoben';

  @override
  String get dispensingTokens => 'Sauna-Token werden ausgegeben...';

  @override
  String get pleaseWait => 'Bitte warten...';

  @override
  String get dispensingStarting => 'Ausgabegerät wird gestartet...';

  @override
  String get dispensingConnecting => 'Verbindung zum Ausgabegerät...';

  @override
  String get dispensingFailed => 'Ausgabe fehlgeschlagen';

  @override
  String dispensingSuccess(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'Tokens',
      one: 'Token',
    );
    return '$count $_temp0 erfolgreich ausgegeben!';
  }

  @override
  String dispensingPartialCharged(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'Tokens',
      one: 'Token',
    );
    return 'Keine Sorge! Du wirst nur für $count $_temp0 belastet.';
  }

  @override
  String get dispensingNeedsRefilling =>
      'Bitte Personal informieren – der Ausgabeautomat muss möglicherweise nachgefüllt werden.';

  @override
  String get dispenserStateIdle => 'Bereit';

  @override
  String get dispenserStateDispensing => 'Ausgabe läuft';

  @override
  String get dispenserStateDone => 'Fertig';

  @override
  String get dispenserStateError => 'Fehler';

  @override
  String get dispenserStateNotFound => 'Nicht gefunden';

  @override
  String get dispenserStateOffline => 'Offline';

  @override
  String get dispenserStateUnknown => 'Unbekannt';

  @override
  String get tabOverview => 'Übersicht';

  @override
  String get tabDispenserStatus => 'Ausgabegerät-Status';

  @override
  String get syncStatus => 'Sync-Status';

  @override
  String get successRate => 'Erfolgsquote';

  @override
  String get endpoints => 'Endpunkte';

  @override
  String get dispenserNotAvailable => 'Ausgabegerät nicht verfügbar';

  @override
  String get dispenserNetworkNotAvailable => 'Netzwerkinfo nicht verfügbar';

  @override
  String get dispenserLocalTransactionLog => 'Lokales Transaktionsprotokoll';

  @override
  String get dispenserManualReconciliationRequired =>
      'Manuelle Abstimmung erforderlich';

  @override
  String get dispenserInProgress => 'In Bearbeitung';

  @override
  String dispenserStatusOnline(String state) {
    return 'Online ($state)';
  }

  @override
  String statusLoadError(String error) {
    return 'Status konnte nicht geladen werden: $error';
  }
}
