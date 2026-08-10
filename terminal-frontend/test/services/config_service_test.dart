import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/services/config_service.dart';

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
      // A fresh install without an explicit config must not ship silent
      // (issue #37): a terminal deployed with no config.json at all gets
      // audio feedback by default.
      test('defaults to true when not in config', () async {
        File('${tempDir.path}/config.json').writeAsStringSync(
          jsonEncode({'terminalId': 'T1', 'apiUrl': 'http://x', 'apiToken': 'tok'}),
        );
        await configService.load();
        expect(configService.soundsEnabled, isTrue);
      });

      test('defaults to true with no config file at all', () async {
        await configService.load();
        expect(configService.soundsEnabled, isTrue);
      });

      test('reads true from config', () async {
        File('${tempDir.path}/config.json').writeAsStringSync(
          jsonEncode({'terminalId': 'T1', 'apiUrl': 'http://x', 'apiToken': 'tok', 'soundsEnabled': true}),
        );
        await configService.load();
        expect(configService.soundsEnabled, isTrue);
      });

      test('reads false from config — a site can opt back into silence',
          () async {
        File('${tempDir.path}/config.json').writeAsStringSync(
          jsonEncode({'terminalId': 'T1', 'apiUrl': 'http://x', 'apiToken': 'tok', 'soundsEnabled': false}),
        );
        await configService.load();
        expect(configService.soundsEnabled, isFalse);
      });

      test('clear resets soundsEnabled to the true default', () async {
        File('${tempDir.path}/config.json').writeAsStringSync(
          jsonEncode({'terminalId': 'T1', 'apiUrl': 'http://x', 'apiToken': 'tok', 'soundsEnabled': false}),
        );
        await configService.load();
        expect(configService.soundsEnabled, isFalse);

        await configService.clear();

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

    group('rfid reader monitoring', () {
      Future<void> loadWith(Map<String, dynamic> extra) async {
        File('${tempDir.path}/config.json').writeAsStringSync(jsonEncode({
          'terminalId': 'Test-Terminal',
          'apiUrl': 'https://test.example.com/api',
          'apiToken': 'b' * 64,
          ...extra,
        }));
        await configService.load();
      }

      test('is off when the reader is not described', () async {
        await loadWith({});

        // Every terminal that predates issue #35 keeps its old behaviour rather
        // than being shown a reader status nothing can back up.
        expect(configService.rfidReaderMonitoringEnabled, isFalse);
        expect(configService.rfidReaderIdentity.isSpecified, isFalse);
        expect(configService.rfidReaderPollIntervalSeconds, 5);
      });

      test('loads the reader identity and poll interval', () async {
        await loadWith({
          'rfidReader': {
            'vendorId': 'ffff',
            'productId': '0035',
            'namePattern': 'USB Reader',
            'pollIntervalSeconds': 10,
          },
        });

        expect(configService.rfidReaderMonitoringEnabled, isTrue);
        expect(configService.rfidReaderIdentity.vendorId, 'ffff');
        expect(configService.rfidReaderIdentity.productId, '0035');
        expect(configService.rfidReaderIdentity.namePattern, 'USB Reader');
        expect(configService.rfidReaderPollIntervalSeconds, 10);
      });

      test('a described reader is monitored by default', () async {
        await loadWith({
          'rfidReader': {'vendorId': 'ffff'},
        });

        expect(configService.rfidReaderMonitoringEnabled, isTrue);
      });

      test('monitor:false turns it off even for a described reader', () async {
        await loadWith({
          'rfidReader': {'monitor': false, 'vendorId': 'ffff'},
        });

        expect(configService.rfidReaderMonitoringEnabled, isFalse);
        expect(configService.rfidReaderIdentity.vendorId, 'ffff');
      });

      test('clear resets reader config to defaults', () async {
        await loadWith({
          'rfidReader': {'vendorId': 'ffff', 'pollIntervalSeconds': 30},
        });
        expect(configService.rfidReaderMonitoringEnabled, isTrue);

        await configService.clear();

        expect(configService.rfidReaderMonitoringEnabled, isFalse);
        expect(configService.rfidReaderIdentity.isSpecified, isFalse);
        expect(configService.rfidReaderPollIntervalSeconds, 5);
      });
    });
  });
}
