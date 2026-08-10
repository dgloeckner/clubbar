import 'dart:convert';
import 'dart:io';

import 'package:path_provider/path_provider.dart';

import 'package:clubbar_terminal/services/rfid_reader_probe.dart';

// Thrown when config.json exists but cannot be parsed or is structurally invalid.
class ConfigParseException implements Exception {
  final String message;
  ConfigParseException(this.message);
  @override
  String toString() => 'ConfigParseException: $message';
}

/// Manages terminal configuration (ADR-0019).
///
/// Loads config from a JSON file at platform-specific path:
/// - macOS: ~/Library/Containers/de.clubbar.clubbarTerminal/Data/Library/Application Support/de.clubbar.clubbarTerminal/config.json
/// - Linux: ~/.local/share/de.clubbar.clubbar_terminal/config.json
/// - Windows: %APPDATA%\de.clubbar.clubbar_terminal\config.json
///
/// Environment variables override file values:
/// - TERMINAL_ID
/// - TERMINAL_API_URL
/// - TERMINAL_API_TOKEN
/// - TERMINAL_FULLSCREEN
/// - TERMINAL_DEMO_MODE
/// - TERMINAL_SOUNDS_ENABLED
/// - DISPENSER_ENABLED
/// - DISPENSER_BASE_URL
/// - DISPENSER_API_KEY
/// - RFID_READER_MONITOR
/// - RFID_READER_VENDOR_ID
/// - RFID_READER_PRODUCT_ID
/// - RFID_READER_NAME_PATTERN
/// - RFID_READER_POLL_INTERVAL_SECONDS
class ConfigService {
  static const String _configFileName = 'config.json';

  final String? _configDirOverride;
  String? _terminalId;
  String? _apiUrl;
  String? _apiToken;
  bool _seedTestData = false;
  bool _demoMode = false;
  bool _dispenserEnabled = false;
  String? _dispenserBaseUrl;
  String? _dispenserApiKey;
  int _dispenserTimeoutMs = 3000;
  int _dispenserPollIntervalMs = 250;
  bool _fullscreen = false;
  bool _soundsEnabled = true;
  Map<String, dynamic>? _fontSizes;
  bool _rfidReaderMonitor = true;
  String? _rfidReaderVendorId;
  String? _rfidReaderProductId;
  String? _rfidReaderNamePattern;
  int _rfidReaderPollIntervalSeconds = 5;

  ConfigService({String? configDir}) : _configDirOverride = configDir;

  bool get isConfigured =>
      _terminalId?.isNotEmpty == true &&
      _apiUrl?.isNotEmpty == true &&
      _apiToken?.isNotEmpty == true;

  String? get terminalId => _terminalId;
  String? get apiUrl => _apiUrl;
  String? get apiToken => _apiToken;
  bool get seedTestData => _seedTestData;
  bool get demoMode => _demoMode;
  bool get dispenserEnabled => _dispenserEnabled;
  String? get dispenserBaseUrl => _dispenserBaseUrl;
  String? get dispenserApiKey => _dispenserApiKey;
  int get dispenserTimeoutMs => _dispenserTimeoutMs;
  int get dispenserPollIntervalMs => _dispenserPollIntervalMs;
  bool get fullscreen => _fullscreen;
  bool get soundsEnabled => _soundsEnabled;
  Map<String, dynamic>? get fontSizes => _fontSizes;

  /// How the RFID reader is recognised among the machine's input devices.
  RfidReaderIdentity get rfidReaderIdentity => RfidReaderIdentity(
        vendorId: _rfidReaderVendorId,
        productId: _rfidReaderProductId,
        namePattern: _rfidReaderNamePattern,
      );

  /// Reader health monitoring runs only for a terminal that was told what its
  /// reader looks like (issue #35). Every other install keeps the previous
  /// behaviour, rather than being shown a status it cannot trust.
  bool get rfidReaderMonitoringEnabled =>
      _rfidReaderMonitor && rfidReaderIdentity.isSpecified;

  int get rfidReaderPollIntervalSeconds => _rfidReaderPollIntervalSeconds;

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

  /// Returns the absolute path where config.json is expected to exist.
  Future<String> getConfigFilePath() async {
    final file = await _getConfigFile();
    return file.path;
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
        _demoMode = json['demoMode'] as bool? ?? false;
        _fullscreen = json['fullscreen'] as bool? ?? false;
        _soundsEnabled = json['soundsEnabled'] as bool? ?? true;
        _fontSizes = json['fontSizes'] as Map<String, dynamic>?;

        // Dispenser config
        final dispenser = json['dispenser'] as Map<String, dynamic>?;
        if (dispenser != null) {
          _dispenserEnabled = dispenser['enabled'] as bool? ?? false;
          _dispenserBaseUrl = dispenser['baseUrl'] as String?;
          _dispenserApiKey = dispenser['apiKey'] as String?;
          _dispenserTimeoutMs = dispenser['timeoutMs'] as int? ?? 3000;
          _dispenserPollIntervalMs = dispenser['pollIntervalMs'] as int? ?? 250;
        }

        // RFID reader health monitoring (issue #35)
        final rfidReader = json['rfidReader'] as Map<String, dynamic>?;
        if (rfidReader != null) {
          _rfidReaderMonitor = rfidReader['monitor'] as bool? ?? true;
          _rfidReaderVendorId = rfidReader['vendorId'] as String?;
          _rfidReaderProductId = rfidReader['productId'] as String?;
          _rfidReaderNamePattern = rfidReader['namePattern'] as String?;
          _rfidReaderPollIntervalSeconds =
              rfidReader['pollIntervalSeconds'] as int? ?? 5;
        }
      } catch (e) {
        throw ConfigParseException(
          'Failed to parse ${configFile.path}: $e',
        );
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
    if (env.containsKey('TERMINAL_DEMO_MODE')) {
      _demoMode = env['TERMINAL_DEMO_MODE']?.toLowerCase() == 'true';
    }
    if (env.containsKey('TERMINAL_SOUNDS_ENABLED')) {
      _soundsEnabled = env['TERMINAL_SOUNDS_ENABLED']?.toLowerCase() == 'true';
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
    if (env.containsKey('RFID_READER_MONITOR')) {
      _rfidReaderMonitor = env['RFID_READER_MONITOR']?.toLowerCase() == 'true';
    }
    if (env.containsKey('RFID_READER_VENDOR_ID')) {
      _rfidReaderVendorId = env['RFID_READER_VENDOR_ID'];
    }
    if (env.containsKey('RFID_READER_PRODUCT_ID')) {
      _rfidReaderProductId = env['RFID_READER_PRODUCT_ID'];
    }
    if (env.containsKey('RFID_READER_NAME_PATTERN')) {
      _rfidReaderNamePattern = env['RFID_READER_NAME_PATTERN'];
    }
    final pollInterval =
        int.tryParse(env['RFID_READER_POLL_INTERVAL_SECONDS'] ?? '');
    if (pollInterval != null && pollInterval > 0) {
      _rfidReaderPollIntervalSeconds = pollInterval;
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
    _demoMode = false;
    _dispenserEnabled = false;
    _dispenserBaseUrl = null;
    _dispenserApiKey = null;
    _dispenserTimeoutMs = 3000;
    _dispenserPollIntervalMs = 250;
    _fullscreen = false;
    _soundsEnabled = true;
    _fontSizes = null;
    _rfidReaderMonitor = true;
    _rfidReaderVendorId = null;
    _rfidReaderProductId = null;
    _rfidReaderNamePattern = null;
    _rfidReaderPollIntervalSeconds = 5;

    final configFile = await _getConfigFile();
    if (configFile.existsSync()) {
      configFile.deleteSync();
    }
  }
}
