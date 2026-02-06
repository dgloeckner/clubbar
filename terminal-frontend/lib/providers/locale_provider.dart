import 'package:flutter/material.dart';

/// Manages the app's current locale based on member preference.
///
/// Follows the pattern from admin frontend where locale is determined
/// by the authenticated member's `preferredLanguage` setting.
class LocaleProvider extends ChangeNotifier {
  Locale _locale = const Locale('de'); // Default: German

  Locale get locale => _locale;

  /// Supported locales (matches admin frontend)
  static const List<Locale> supportedLocales = [
    Locale('de'),
    Locale('en'),
  ];

  /// Update locale when member is selected/changed.
  /// Called from MembersProvider when a member is selected.
  void setLocaleFromMember(String? preferredLanguage) {
    final langCode = preferredLanguage ?? 'de';

    // Validate language code is supported
    if (['de', 'en'].contains(langCode)) {
      final newLocale = Locale(langCode);
      if (_locale != newLocale) {
        _locale = newLocale;
        notifyListeners();
      }
    }
  }

  /// Reset to default locale (e.g., when member logs out)
  void resetToDefault() {
    if (_locale != const Locale('de')) {
      _locale = const Locale('de');
      notifyListeners();
    }
  }
}
