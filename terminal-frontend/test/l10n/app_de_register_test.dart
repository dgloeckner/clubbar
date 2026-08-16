import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  group('app_de.arb register', () {
    test('no German string slips back into the formal Sie-form', () {
      final raw = File('lib/l10n/app_de.arb').readAsStringSync();
      final entries = jsonDecode(raw) as Map<String, dynamic>;
      final formal = RegExp(r'\b(Sie|Ihnen|Ihre[nmrs]?)\b');

      // "@"-prefixed keys are ARB metadata (descriptions, section markers),
      // not translatable copy — only the actual strings need checking.
      for (final entry in entries.entries) {
        if (entry.key.startsWith('@')) continue;
        final value = entry.value;
        if (value is! String) continue;

        expect(
          formal.hasMatch(value),
          isFalse,
          reason: "the German string '${entry.key}' is in the Sie-form: $value",
        );
      }
    });
  });
}
