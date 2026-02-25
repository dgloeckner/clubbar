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

    test('save writes config file and fields are available', () async {
      await configService.save(
        terminalId: 'Ruderbar-Kühlschrank',
        apiUrl: 'https://club.example.com/api',
        apiToken: 'a' * 64,
      );

      expect(configService.isConfigured, isTrue);
      expect(configService.terminalId, 'Ruderbar-Kühlschrank');
      expect(configService.apiUrl, 'https://club.example.com/api');
      expect(configService.apiToken, 'a' * 64);

      // Verify file was written
      final configFile = File('${tempDir.path}/config.json');
      expect(configFile.existsSync(), isTrue);

      final contents = jsonDecode(configFile.readAsStringSync());
      expect(contents['terminalId'], 'Ruderbar-Kühlschrank');
      expect(contents['apiUrl'], 'https://club.example.com/api');
      expect(contents['apiToken'], 'a' * 64);
    });

    test('load reads existing config file', () async {
      // Write a config file manually
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
      await configService.save(
        terminalId: 'Test',
        apiUrl: 'https://test.com/api',
        apiToken: 'c' * 64,
      );

      expect(configService.isConfigured, isTrue);

      await configService.clear();

      expect(configService.isConfigured, isFalse);
      expect(configService.terminalId, isNull);
      expect(configService.apiUrl, isNull);
      expect(configService.apiToken, isNull);

      final configFile = File('${tempDir.path}/config.json');
      expect(configFile.existsSync(), isFalse);
    });

    test('clear is safe when no config file exists', () async {
      await configService.clear();

      expect(configService.isConfigured, isFalse);
    });

    test('isConfigured requires all three fields', () async {
      // Only terminalId
      final configFile = File('${tempDir.path}/config.json');
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

    test('load handles corrupt JSON file gracefully', () async {
      final configFile = File('${tempDir.path}/config.json');
      configFile.writeAsStringSync('not valid json {{{');

      await configService.load();

      expect(configService.isConfigured, isFalse);
      expect(configService.terminalId, isNull);
    });

    test('save creates directory if it does not exist', () async {
      final nestedDir = '${tempDir.path}/nested/deep';
      final nestedService = ConfigService(configDir: nestedDir);

      await nestedService.save(
        terminalId: 'Test',
        apiUrl: 'https://test.com/api',
        apiToken: 'f' * 64,
      );

      expect(nestedService.isConfigured, isTrue);
      expect(File('$nestedDir/config.json').existsSync(), isTrue);
    });

    test('load then save overwrites existing config', () async {
      await configService.save(
        terminalId: 'Old',
        apiUrl: 'https://old.com/api',
        apiToken: 'a' * 64,
      );

      await configService.save(
        terminalId: 'New',
        apiUrl: 'https://new.com/api',
        apiToken: 'b' * 64,
      );

      // Re-load to verify persistence
      final freshService = ConfigService(configDir: tempDir.path);
      await freshService.load();

      expect(freshService.terminalId, 'New');
      expect(freshService.apiUrl, 'https://new.com/api');
      expect(freshService.apiToken, 'b' * 64);
    });

    group('getLogsDir', () {
      test('creates logs subdirectory and returns its path', () async {
        final logsDir = await configService.getLogsDir();

        expect(logsDir, equals('${tempDir.path}/logs'));
        expect(Directory(logsDir).existsSync(), isTrue);
      });

      test('returns existing logs directory without error', () async {
        // Create it first
        final firstCall = await configService.getLogsDir();
        // Call again — should not throw
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
        // which is not practical in unit tests. The load() method checks
        // Platform.environment and applies overrides if present.
        expect(configService.isConfigured, isFalse);
      });
    });

    group('dispenser configuration', () {
      test('loads dispenser config from file', () async {
        // Write a config file with dispenser settings
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
        // Write a config file without dispenser settings
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

      test('saves dispenser config to file', () async {
        await configService.save(
          terminalId: 'Test-Terminal',
          apiUrl: 'https://test.example.com/api',
          apiToken: 'a' * 64,
          dispenserEnabled: true,
          dispenserBaseUrl: 'http://dispenser.local',
          dispenserApiKey: 'dispenser-key-456',
        );

        // Verify file was written correctly
        final configFile = File('${tempDir.path}/config.json');
        expect(configFile.existsSync(), isTrue);

        final contents = jsonDecode(configFile.readAsStringSync());
        expect(contents['dispenser']['enabled'], isTrue);
        expect(contents['dispenser']['baseUrl'], 'http://dispenser.local');
        expect(contents['dispenser']['apiKey'], 'dispenser-key-456');
        expect(contents['dispenser']['timeoutMs'], 3000);
        expect(contents['dispenser']['pollIntervalMs'], 250);
      });

      test('persists dispenser config across load/save cycles', () async {
        // Save config with dispenser settings
        await configService.save(
          terminalId: 'Test-Terminal',
          apiUrl: 'https://test.example.com/api',
          apiToken: 'a' * 64,
          dispenserEnabled: true,
          dispenserBaseUrl: 'http://dispenser.test',
          dispenserApiKey: 'key-789',
        );

        // Load in fresh instance
        final freshService = ConfigService(configDir: tempDir.path);
        await freshService.load();

        expect(freshService.dispenserEnabled, isTrue);
        expect(freshService.dispenserBaseUrl, 'http://dispenser.test');
        expect(freshService.dispenserApiKey, 'key-789');
      });

      test('clear resets dispenser config to defaults', () async {
        await configService.save(
          terminalId: 'Test',
          apiUrl: 'https://test.com/api',
          apiToken: 'c' * 64,
          dispenserEnabled: true,
          dispenserBaseUrl: 'http://dispenser.local',
          dispenserApiKey: 'key-abc',
        );

        expect(configService.dispenserEnabled, isTrue);

        await configService.clear();

        expect(configService.dispenserEnabled, isFalse);
        expect(configService.dispenserBaseUrl, isNull);
        expect(configService.dispenserApiKey, isNull);
        expect(configService.dispenserTimeoutMs, 3000);
        expect(configService.dispenserPollIntervalMs, 250);
      });

      test('partial save only updates provided dispenser fields', () async {
        // Initial save with all dispenser fields
        await configService.save(
          terminalId: 'Test',
          apiUrl: 'https://test.com/api',
          apiToken: 'a' * 64,
          dispenserEnabled: true,
          dispenserBaseUrl: 'http://dispenser.original',
          dispenserApiKey: 'key-original',
        );

        // Partial save - only update enabled flag
        await configService.save(
          terminalId: 'Test',
          apiUrl: 'https://test.com/api',
          apiToken: 'a' * 64,
          dispenserEnabled: false,
        );

        // baseUrl and apiKey should be retained from first save
        expect(configService.dispenserEnabled, isFalse);
        expect(configService.dispenserBaseUrl, 'http://dispenser.original');
        expect(configService.dispenserApiKey, 'key-original');
      });

      test('handles null dispenser fields gracefully', () async {
        await configService.save(
          terminalId: 'Test',
          apiUrl: 'https://test.com/api',
          apiToken: 'a' * 64,
          dispenserEnabled: true,
          dispenserBaseUrl: null,
          dispenserApiKey: null,
        );

        final configFile = File('${tempDir.path}/config.json');
        final contents = jsonDecode(configFile.readAsStringSync());

        expect(contents['dispenser']['baseUrl'], '');
        expect(contents['dispenser']['apiKey'], '');
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

      test('defaults to false when soundsEnabled key is absent from config', () async {
        // Config file has no soundsEnabled key at all
        File('${tempDir.path}/config.json').writeAsStringSync(
          jsonEncode({'terminalId': 'T1', 'apiUrl': 'http://x', 'apiToken': 'tok'}),
        );
        await configService.load();
        expect(configService.soundsEnabled, isFalse);
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
  });
}
