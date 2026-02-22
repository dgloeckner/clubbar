import 'dart:convert';
import 'dart:io';

import 'package:path_provider/path_provider.dart';

/// Manages terminal configuration (ADR-0019).
///
/// Loads config from a JSON file at platform-specific path:
/// - macOS: ~/Library/Containers/com.example.ruderbar_terminal/Data/Library/Application Support/com.example.ruderbar_terminal/config.json
/// - Linux: ~/.local/share/com.example.ruderbar_terminal/config.json
/// - Windows: %APPDATA%\com.example.ruderbar_terminal\config.json
///
/// Environment variables override file values:
/// - TERMINAL_ID
/// - TERMINAL_API_URL
/// - TERMINAL_API_TOKEN
/// - DISPENSER_ENABLED
/// - DISPENSER_BASE_URL
/// - DISPENSER_API_KEY
class ConfigService {
  static const String _configFileName = 'config.json';

  final String? _configDirOverride;
  String? _terminalId;
  String? _apiUrl;
  String? _apiToken;
  bool _seedTestData = false;
  bool _dispenserEnabled = false;
  String? _dispenserBaseUrl;
  String? _dispenserApiKey;
  int _dispenserTimeoutMs = 3000;
  int _dispenserPollIntervalMs = 250;
  bool _fullscreen = false;

  ConfigService({String? configDir}) : _configDirOverride = configDir;

  bool get isConfigured =>
      _terminalId?.isNotEmpty == true &&
      _apiUrl?.isNotEmpty == true &&
      _apiToken?.isNotEmpty == true;

  String? get terminalId => _terminalId;
  String? get apiUrl => _apiUrl;
  String? get apiToken => _apiToken;
  bool get seedTestData => _seedTestData;
  bool get dispenserEnabled => _dispenserEnabled;
  String? get dispenserBaseUrl => _dispenserBaseUrl;
  String? get dispenserApiKey => _dispenserApiKey;
  int get dispenserTimeoutMs => _dispenserTimeoutMs;
  int get dispenserPollIntervalMs => _dispenserPollIntervalMs;
  bool get fullscreen => _fullscreen;

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
        _fullscreen = json['fullscreen'] as bool? ?? false;

        // Dispenser config
        final dispenser = json['dispenser'] as Map<String, dynamic>?;
        if (dispenser != null) {
          _dispenserEnabled = dispenser['enabled'] as bool? ?? false;
          _dispenserBaseUrl = dispenser['baseUrl'] as String?;
          _dispenserApiKey = dispenser['apiKey'] as String?;
          _dispenserTimeoutMs = dispenser['timeoutMs'] as int? ?? 3000;
          _dispenserPollIntervalMs = dispenser['pollIntervalMs'] as int? ?? 250;
        }
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
    if (env.containsKey('TERMINAL_FULLSCREEN')) {
      _fullscreen = env['TERMINAL_FULLSCREEN']?.toLowerCase() == 'true';
    }
    if (env.containsKey('DISPENSER_ENABLED')) {
      _dispenserEnabled = env['DISPENSER_ENABLED']?.toLowerCase() == 'true';
    }
    if (env.containsKey('DISPENSER_BASE_URL')) {
      _dispenserBaseUrl = env['DISPENSER_BASE_URL'];
    }
    if (env.containsKey('DISPENSER_API_KEY')) {
      _dispenserApiKey = env['DISPENSER_API_KEY'];
    }
  }

  /// Save configuration to file.
  Future<void> save({
    required String terminalId,
    required String apiUrl,
    required String apiToken,
    bool? dispenserEnabled,
    String? dispenserBaseUrl,
    String? dispenserApiKey,
  }) async {
    _terminalId = terminalId;
    _apiUrl = apiUrl;
    _apiToken = apiToken;

    // Update dispenser config if provided
    if (dispenserEnabled != null) _dispenserEnabled = dispenserEnabled;
    if (dispenserBaseUrl != null) _dispenserBaseUrl = dispenserBaseUrl;
    if (dispenserApiKey != null) _dispenserApiKey = dispenserApiKey;

    final configFile = await _getConfigFile();
    final dir = configFile.parent;
    if (!dir.existsSync()) {
      dir.createSync(recursive: true);
    }

    final json = jsonEncode({
      'terminalId': terminalId,
      'apiUrl': apiUrl,
      'apiToken': apiToken,
      'dispenser': {
        'enabled': _dispenserEnabled,
        'baseUrl': _dispenserBaseUrl ?? '',
        'apiKey': _dispenserApiKey ?? '',
        'timeoutMs': _dispenserTimeoutMs,
        'pollIntervalMs': _dispenserPollIntervalMs,
      }
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
    _dispenserEnabled = false;
    _dispenserBaseUrl = null;
    _dispenserApiKey = null;
    _dispenserTimeoutMs = 3000;
    _dispenserPollIntervalMs = 250;
    _fullscreen = false;

    final configFile = await _getConfigFile();
    if (configFile.existsSync()) {
      configFile.deleteSync();
    }
  }
}
