import 'dart:convert';
import 'dart:io';

import 'package:path_provider/path_provider.dart';

/// Manages terminal configuration (ADR-0019).
///
/// Loads config from a JSON file at platform-specific path:
/// - macOS: ~/Library/Application Support/frgs-terminal/config.json
/// - Linux: ~/.config/frgs-terminal/config.json
/// - Windows: %APPDATA%\frgs-terminal\config.json
///
/// Environment variables override file values:
/// - TERMINAL_ID
/// - TERMINAL_API_URL
/// - TERMINAL_API_TOKEN
class ConfigService {
  static const String _configFileName = 'config.json';

  final String? _configDirOverride;
  String? _terminalId;
  String? _apiUrl;
  String? _apiToken;
  bool _seedTestData = false;

  ConfigService({String? configDir}) : _configDirOverride = configDir;

  bool get isConfigured =>
      _terminalId?.isNotEmpty == true &&
      _apiUrl?.isNotEmpty == true &&
      _apiToken?.isNotEmpty == true;

  String? get terminalId => _terminalId;
  String? get apiUrl => _apiUrl;
  String? get apiToken => _apiToken;
  bool get seedTestData => _seedTestData;

  Future<String> _getConfigDir() async {
    if (_configDirOverride != null) {
      return _configDirOverride;
    }
    final appSupportDir = await getApplicationSupportDirectory();
    return appSupportDir.path;
  }

  Future<File> _getConfigFile() async {
    final dir = await _getConfigDir();
    return File('$dir/$_configFileName');
  }

  /// Load configuration from file, then apply env var overrides.
  Future<void> load() async {
    final configFile = await _getConfigFile();

    if (configFile.existsSync()) {
      try {
        final contents = configFile.readAsStringSync();
        final json = jsonDecode(contents) as Map<String, dynamic>;
        _terminalId = json['terminalId'] as String?;
        _apiUrl = json['apiUrl'] as String?;
        _apiToken = json['apiToken'] as String?;
        _seedTestData = json['seedTestData'] as bool? ?? false;
      } catch (_) {
        // Corrupt file — leave fields null
        _terminalId = null;
        _apiUrl = null;
        _apiToken = null;
      }
    }

    // Environment variable overrides (per ADR-0019)
    final env = Platform.environment;
    if (env.containsKey('TERMINAL_ID')) {
      _terminalId = env['TERMINAL_ID'];
    }
    if (env.containsKey('TERMINAL_API_URL')) {
      _apiUrl = env['TERMINAL_API_URL'];
    }
    if (env.containsKey('TERMINAL_API_TOKEN')) {
      _apiToken = env['TERMINAL_API_TOKEN'];
    }
    if (env.containsKey('TERMINAL_SEED_TEST_DATA')) {
      _seedTestData = env['TERMINAL_SEED_TEST_DATA']?.toLowerCase() == 'true';
    }
  }

  /// Save configuration to file.
  Future<void> save({
    required String terminalId,
    required String apiUrl,
    required String apiToken,
  }) async {
    _terminalId = terminalId;
    _apiUrl = apiUrl;
    _apiToken = apiToken;

    final configFile = await _getConfigFile();
    final dir = configFile.parent;
    if (!dir.existsSync()) {
      dir.createSync(recursive: true);
    }

    final json = jsonEncode({
      'terminalId': terminalId,
      'apiUrl': apiUrl,
      'apiToken': apiToken,
    });

    configFile.writeAsStringSync(json);

    // Set file permissions to 600 (owner read/write only) on Unix
    if (!Platform.isWindows) {
      await Process.run('chmod', ['600', configFile.path]);
    }
  }

  /// Returns the path to the logs directory, creating it if needed.
  Future<String> getLogsDir() async {
    final baseDir = await _getConfigDir();
    final logsDir = Directory('$baseDir/logs');
    if (!logsDir.existsSync()) {
      logsDir.createSync(recursive: true);
    }
    return logsDir.path;
  }

  /// Delete config file and reset in-memory state.
  Future<void> clear() async {
    _terminalId = null;
    _apiUrl = null;
    _apiToken = null;

    final configFile = await _getConfigFile();
    if (configFile.existsSync()) {
      configFile.deleteSync();
    }
  }
}
