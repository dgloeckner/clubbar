import 'dart:io';
import 'package:flutter_test/flutter_test.dart';

/// Issue #298: the header clock requested `fontFamily: 'JetBrains Mono'`
/// while `pubspec.yaml` declared no fonts at all, so the family silently
/// fell back to the platform default — a fallback nobody decided on.
///
/// A widget test cannot tell a genuinely rendered glyph from the fallback
/// (both paint *something*), so this checks the two things that actually
/// caused the bug: the asset exists on disk, and pubspec.yaml declares it
/// for the exact family name the header requests.
void main() {
  group('JetBrains Mono clock font (#298)', () {
    test('the font asset is bundled with its license', () {
      expect(
        File('assets/fonts/JetBrainsMono-Medium.ttf').existsSync(),
        isTrue,
        reason: 'clubbar_header.dart requests this family — without the '
            'asset it silently falls back to the platform default',
      );
      expect(File('assets/fonts/OFL.txt').existsSync(), isTrue,
          reason: 'SIL OFL 1.1 requires the license to travel with the font');
    });

    test('pubspec.yaml declares the family the header requests', () {
      final pubspec = File('pubspec.yaml').readAsStringSync();

      expect(pubspec, contains('family: JetBrains Mono'));
      expect(pubspec, contains('assets/fonts/JetBrainsMono-Medium.ttf'));
    });
  });
}
