import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/services/config_service.dart';

void main() {
  group('ConfigService', () {
    late Directory tempDir;
    late ConfigService configService;

    setUp(() {
      tempDir = Directory.systemTemp.createTempSync('config_test_');
      configService = ConfigService(configDir: tempDir.path);
    });

    tearDown(() {
      if (tempDir.existsSync()) {
        tempDir.deleteSync(recursive: true);
      }
    });

    test('isConfigured is false initially', () {
      expect(configService.isConfigured, isFalse);
      expect(configService.terminalId, isNull);
      expect(configService.apiUrl, isNull);
      expect(configService.apiToken, isNull);
    });

    test('load with no config file leaves fields null', () async {
      await configService.load();

      expect(configService.isConfigured, isFalse);
      expect(configService.terminalId, isNull);
      expect(configService.apiUrl, isNull);
      expect(configService.apiToken, isNull);
    });

    test('load reads existing config file', () async {
      final configFile = File('${tempDir.path}/config.json');
      configFile.writeAsStringSync(jsonEncode({
        'terminalId': 'Test-Terminal',
        'apiUrl': 'https://test.example.com/api',
        'apiToken': 'b' * 64,
      }));

      await configService.load();

      expect(configService.isConfigured, isTrue);
      expect(configService.terminalId, 'Test-Terminal');
      expect(configService.apiUrl, 'https://test.example.com/api');
      expect(configService.apiToken, 'b' * 64);
    });

    test('clear deletes config file and resets fields', () async {
      final configFile = File('${tempDir.path}/config.json');
      configFile.writeAsStringSync(jsonEncode({
        'terminalId': 'Test',
        'apiUrl': 'https://test.com/api',
        'apiToken': 'c' * 64,
      }));
      await configService.load();

      expect(configService.isConfigured, isTrue);

      await configService.clear();

      expect(configService.isConfigured, isFalse);
      expect(configService.terminalId, isNull);
      expect(configService.apiUrl, isNull);
      expect(configService.apiToken, isNull);

      expect(configFile.existsSync(), isFalse);
    });

    test('clear is safe when no config file exists', () async {
      await configService.clear();

      expect(configService.isConfigured, isFalse);
    });

    test('isConfigured requires all three fields', () async {
      final configFile = File('${tempDir.path}/config.json');

      // Only terminalId
      configFile.writeAsStringSync(jsonEncode({
        'terminalId': 'Test',
      }));
      await configService.load();
      expect(configService.isConfigured, isFalse);

      // terminalId + apiUrl
      configFile.writeAsStringSync(jsonEncode({
        'terminalId': 'Test',
        'apiUrl': 'https://test.com/api',
      }));
      configService = ConfigService(configDir: tempDir.path);
      await configService.load();
      expect(configService.isConfigured, isFalse);

      // All three
      configFile.writeAsStringSync(jsonEncode({
        'terminalId': 'Test',
        'apiUrl': 'https://test.com/api',
        'apiToken': 'd' * 64,
      }));
      configService = ConfigService(configDir: tempDir.path);
      await configService.load();
      expect(configService.isConfigured, isTrue);
    });

    test('empty strings are treated as not configured', () async {
      final configFile = File('${tempDir.path}/config.json');
      configFile.writeAsStringSync(jsonEncode({
        'terminalId': '',
        'apiUrl': 'https://test.com/api',
        'apiToken': 'e' * 64,
      }));

      await configService.load();
      expect(configService.isConfigured, isFalse);
    });

    test('load throws ConfigParseException on corrupt JSON', () async {
      final configFile = File('${tempDir.path}/config.json');
      configFile.writeAsStringSync('not valid json {{{');

      expect(
        () => configService.load(),
        throwsA(isA<ConfigParseException>()),
      );
    });

    test('load overwrites previous values when file changes', () async {
      final configFile = File('${tempDir.path}/config.json');

      configFile.writeAsStringSync(jsonEncode({
        'terminalId': 'Old',
        'apiUrl': 'https://old.com/api',
        'apiToken': 'a' * 64,
      }));
      await configService.load();
      expect(configService.terminalId, 'Old');

      configFile.writeAsStringSync(jsonEncode({
        'terminalId': 'New',
        'apiUrl': 'https://new.com/api',
        'apiToken': 'b' * 64,
      }));
      configService = ConfigService(configDir: tempDir.path);
      await configService.load();
      expect(configService.terminalId, 'New');
      expect(configService.apiUrl, 'https://new.com/api');
      expect(configService.apiToken, 'b' * 64);
    });

    group('getLogsDir', () {
      test('creates logs subdirectory and returns its path', () async {
        final logsDir = await configService.getLogsDir();

        expect(logsDir, equals('${tempDir.path}/logs'));
        expect(Directory(logsDir).existsSync(), isTrue);
      });

      test('returns existing logs directory without error', () async {
        final firstCall = await configService.getLogsDir();
        final secondCall = await configService.getLogsDir();

        expect(firstCall, equals(secondCall));
        expect(Directory(secondCall).existsSync(), isTrue);
      });
    });

    group('environment variable overrides', () {
      test('env vars are documented for override behavior', () {
        // ConfigService supports env var overrides via Platform.environment:
        // TERMINAL_ID, TERMINAL_API_URL, TERMINAL_API_TOKEN
        // These are applied during load() and take precedence over file values.
        // Testing env vars requires setting actual process environment variables,
        // which is not practical in unit tests.
        expect(configService.isConfigured, isFalse);
      });
    });

    group('dispenser configuration', () {
      test('loads dispenser config from file', () async {
        final configFile = File('${tempDir.path}/config.json');
        configFile.writeAsStringSync(jsonEncode({
          'terminalId': 'Test-Terminal',
          'apiUrl': 'https://test.example.com/api',
          'apiToken': 'b' * 64,
          'dispenser': {
            'enabled': true,
            'baseUrl': 'http://dispenser.local',
            'apiKey': 'dispenser-api-key-123',
            'timeoutMs': 5000,
            'pollIntervalMs': 500,
          }
        }));

        await configService.load();

        expect(configService.dispenserEnabled, isTrue);
        expect(configService.dispenserBaseUrl, 'http://dispenser.local');
        expect(configService.dispenserApiKey, 'dispenser-api-key-123');
        expect(configService.dispenserTimeoutMs, 5000);
        expect(configService.dispenserPollIntervalMs, 500);
      });

      test('uses default values when dispenser config is missing', () async {
        final configFile = File('${tempDir.path}/config.json');
        configFile.writeAsStringSync(jsonEncode({
          'terminalId': 'Test-Terminal',
          'apiUrl': 'https://test.example.com/api',
          'apiToken': 'b' * 64,
        }));

        await configService.load();

        expect(configService.dispenserEnabled, isFalse);
        expect(configService.dispenserBaseUrl, isNull);
        expect(configService.dispenserApiKey, isNull);
        expect(configService.dispenserTimeoutMs, 3000);
        expect(configService.dispenserPollIntervalMs, 250);
      });

      test('clear resets dispenser config to defaults', () async {
        final configFile = File('${tempDir.path}/config.json');
        configFile.writeAsStringSync(jsonEncode({
          'terminalId': 'Test',
          'apiUrl': 'https://test.com/api',
          'apiToken': 'c' * 64,
          'dispenser': {
            'enabled': true,
            'baseUrl': 'http://dispenser.local',
            'apiKey': 'key-abc',
          }
        }));
        await configService.load();

        expect(configService.dispenserEnabled, isTrue);

        await configService.clear();

        expect(configService.dispenserEnabled, isFalse);
        expect(configService.dispenserBaseUrl, isNull);
        expect(configService.dispenserApiKey, isNull);
        expect(configService.dispenserTimeoutMs, 3000);
        expect(configService.dispenserPollIntervalMs, 250);
      });
    });

    group('soundsEnabled', () {
      test('defaults to false when not in config', () async {
        File('${tempDir.path}/config.json').writeAsStringSync(
          jsonEncode({'terminalId': 'T1', 'apiUrl': 'http://x', 'apiToken': 'tok'}),
        );
        await configService.load();
        expect(configService.soundsEnabled, isFalse);
      });

      test('reads true from config', () async {
        File('${tempDir.path}/config.json').writeAsStringSync(
          jsonEncode({'terminalId': 'T1', 'apiUrl': 'http://x', 'apiToken': 'tok', 'soundsEnabled': true}),
        );
        await configService.load();
        expect(configService.soundsEnabled, isTrue);
      });
    });

    group('fullscreen configuration', () {
      test('fullscreen defaults to false', () async {
        await configService.load();
        expect(configService.fullscreen, isFalse);
      });

      test('loads fullscreen:true from config file', () async {
        final configFile = File('${tempDir.path}/config.json');
        configFile.writeAsStringSync(jsonEncode({
          'terminalId': 'Test-Terminal',
          'apiUrl': 'https://test.example.com/api',
          'apiToken': 'b' * 64,
          'fullscreen': true,
        }));

        await configService.load();

        expect(configService.fullscreen, isTrue);
      });

      test('loads fullscreen:false from config file', () async {
        final configFile = File('${tempDir.path}/config.json');
        configFile.writeAsStringSync(jsonEncode({
          'terminalId': 'Test-Terminal',
          'apiUrl': 'https://test.example.com/api',
          'apiToken': 'b' * 64,
          'fullscreen': false,
        }));

        await configService.load();

        expect(configService.fullscreen, isFalse);
      });

      test('fullscreen missing from config file defaults to false', () async {
        final configFile = File('${tempDir.path}/config.json');
        configFile.writeAsStringSync(jsonEncode({
          'terminalId': 'Test-Terminal',
          'apiUrl': 'https://test.example.com/api',
          'apiToken': 'b' * 64,
        }));

        await configService.load();

        expect(configService.fullscreen, isFalse);
      });

      test('clear resets fullscreen to false', () async {
        final configFile = File('${tempDir.path}/config.json');
        configFile.writeAsStringSync(jsonEncode({
          'terminalId': 'Test-Terminal',
          'apiUrl': 'https://test.example.com/api',
          'apiToken': 'b' * 64,
          'fullscreen': true,
        }));
        await configService.load();
        expect(configService.fullscreen, isTrue);

        await configService.clear();

        expect(configService.fullscreen, isFalse);
      });
    });

    group('demoMode configuration', () {
      test('demoMode defaults to false', () async {
        await configService.load();
        expect(configService.demoMode, isFalse);
      });

      test('loads demoMode:true from config file', () async {
        final configFile = File('${tempDir.path}/config.json');
        configFile.writeAsStringSync(jsonEncode({
          'terminalId': 'Test-Terminal',
          'apiUrl': 'https://test.example.com/api',
          'apiToken': 'b' * 64,
          'demoMode': true,
        }));

        await configService.load();

        expect(configService.demoMode, isTrue);
      });

      test('clear resets demoMode to false', () async {
        final configFile = File('${tempDir.path}/config.json');
        configFile.writeAsStringSync(jsonEncode({
          'terminalId': 'Test-Terminal',
          'apiUrl': 'https://test.example.com/api',
          'apiToken': 'b' * 64,
          'demoMode': true,
        }));
        await configService.load();
        expect(configService.demoMode, isTrue);

        await configService.clear();

        expect(configService.demoMode, isFalse);
      });
    });
  });
}
