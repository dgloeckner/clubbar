import 'dart:convert';
import 'dart:io';

import 'package:path_provider/path_provider.dart';

import 'package:clubbar_terminal/config/app_config.dart';
import 'package:clubbar_terminal/models/credit_limit.dart';
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

  /// Header title shown when `config.json` carries no `displayName` (#297).
  static const String defaultDisplayName = 'Club Bar';

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
  bool _screenBlankingEnabled = false;
  int _screenBlankingTimeoutSeconds = 300;
  String _screenBlankingMode = 'output-power';
  String? _screenBlankingOutput;
  bool _soundsEnabled = true;
  String? _displayName;
  String? _backendDisplayName;
  CreditLimitPolicy _creditLimitPolicy = CreditLimitPolicy.shipped;
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

  /// Blank the screen after a spell with no input (#763).
  ///
  /// Opt-in for the same reason [fullscreen] is: a kiosk wants it, and a
  /// development machine does not want its screen going black mid-work.
  bool get screenBlankingEnabled => _screenBlankingEnabled;

  Duration get screenBlankingTimeout =>
      Duration(seconds: _screenBlankingTimeoutSeconds);

  /// `output-power` puts the panel itself to sleep and is what a terminal
  /// should use. `overlay` only paints black — the fallback for a panel that
  /// ignores signal loss, and what the terminal did before #763.
  ///
  /// Powering the output down needs to be told *which* output ([screenBlankingOutput]);
  /// without one there is nothing to name on the command line, so this falls
  /// back to painting rather than guessing at an output name.
  bool get screenBlankingPowersOutput =>
      _screenBlankingMode == 'output-power' &&
      (_screenBlankingOutput?.isNotEmpty ?? false);

  /// The Wayland output to switch, e.g. `HDMI-A-1`. Device-specific, so it is
  /// configured rather than discovered; `wlopm` with no arguments lists them.
  String? get screenBlankingOutput => _screenBlankingOutput;

  /// The name shown in the terminal header (ADR-0034).
  ///
  /// Precedence, highest first:
  /// 1. An explicit `config.json` `displayName` (#297) — a per-terminal
  ///    override so a fork is not needed to show anything but "Club Bar",
  ///    e.g. during a themed event. Always wins when set.
  /// 2. The org-wide `instance_name` reported by the backend's `/health`
  ///    endpoint and fed in via [setBackendDisplayName] on each sync cycle.
  /// 3. The stock [defaultDisplayName] fallback, for an unconfigured
  ///    terminal that has neither a local override nor a reachable backend.
  String get displayName =>
      _displayName ?? _backendDisplayName ?? defaultDisplayName;
  Map<String, dynamic>? get fontSizes => _fontSizes;

  /// The club's credit ceiling and warning band (ADR-0047).
  ///
  /// Cached, not asked for: the terminal blocks a checkout in front of the
  /// member with no backend reachable, so the policy has to be here already.
  /// It is persisted to `config.json` by [setCreditLimitPolicy], which is what
  /// makes it survive a restart on a terminal that boots offline.
  ///
  /// Before the first successful `/sync/config` poll on a fresh install this
  /// is [CreditLimitPolicy.shipped] — the values the backend seeds its own
  /// configuration with, so an untouched club sees no change.
  CreditLimitPolicy get creditLimitPolicy => _creditLimitPolicy;

  /// Records the club policy learned from `GET /sync/config` and writes it to
  /// `config.json`.
  ///
  /// Persisting rather than holding it in memory is the whole point: a
  /// terminal that boots with the network down must still enforce what the
  /// club last said, not the constant this build happened to ship with. A
  /// failed poll never reaches here, so the cached policy simply stays — the
  /// same graceful-degradation rule ADR-0023 sets for balances.
  ///
  /// The rest of `config.json` is read and written back untouched: it holds
  /// this terminal's credentials, and this method must not be able to lose
  /// them.
  Future<void> setCreditLimitPolicy(CreditLimitPolicy policy) async {
    _creditLimitPolicy = policy;

    final configFile = await _getConfigFile();
    Map<String, dynamic> json = {};
    if (configFile.existsSync()) {
      try {
        json = jsonDecode(configFile.readAsStringSync()) as Map<String, dynamic>;
      } catch (_) {
        // An unparseable file is not made worse by refusing to write a cache
        // value into it. load() is where a broken config is reported.
        return;
      }
    }

    json['creditLimit'] = {
      'defaultLimitCents': policy.defaultLimitCents,
      'warnThresholdPercent': policy.warnThresholdPercent,
    };

    configFile.parent.createSync(recursive: true);
    configFile.writeAsStringSync(const JsonEncoder.withIndent('  ').convert(json));
  }

  /// Records the org-wide instance name learned from the backend's `/health`
  /// response (ADR-0034), so [displayName] can fall back to it.
  ///
  /// Does not persist to `config.json` and never overrides an explicit local
  /// `displayName` override — see [displayName]'s precedence. Pass null to
  /// clear a previously learned name (e.g. after `clear()`).
  void setBackendDisplayName(String? name) {
    _backendDisplayName = name;
  }

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

  /// The directory `config.json` lives in.
  ///
  /// Also where the app and the Pi's updater exchange their two files
  /// (ADR-0054): `status.json` out, `update-state.json` in. They belong beside
  /// the configuration because that is the one directory both the app and the
  /// kiosk user's update timer already own — the bundle under
  /// `/opt/clubbar-terminal/` is replaced wholesale by an update, so nothing
  /// that has to survive one may live there.
  Future<String> getDataDirectoryPath() => _getConfigDir();

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
        _displayName = json['displayName'] as String?;
        _fontSizes = json['fontSizes'] as Map<String, dynamic>?;

        // The club policy this terminal last synced (ADR-0047). Absent on a
        // fresh install, which is what the shipped seed values are for.
        final creditLimit = json['creditLimit'] as Map<String, dynamic>?;
        if (creditLimit != null) {
          _creditLimitPolicy = CreditLimitPolicy(
            defaultLimitCents: creditLimit['defaultLimitCents'] as int? ??
                AppConfig.balanceLimitCents,
            warnThresholdPercent: creditLimit['warnThresholdPercent'] as int? ??
                AppConfig.balanceWarnThresholdPercent,
          );
        }

        // Dispenser config
        final dispenser = json['dispenser'] as Map<String, dynamic>?;
        if (dispenser != null) {
          _dispenserEnabled = dispenser['enabled'] as bool? ?? false;
          _dispenserBaseUrl = dispenser['baseUrl'] as String?;
          _dispenserApiKey = dispenser['apiKey'] as String?;
          _dispenserTimeoutMs = dispenser['timeoutMs'] as int? ?? 3000;
          _dispenserPollIntervalMs = dispenser['pollIntervalMs'] as int? ?? 250;
        }

        // Screen blanking (#763)
        final blanking = json['screenBlanking'] as Map<String, dynamic>?;
        if (blanking != null) {
          _screenBlankingEnabled = blanking['enabled'] as bool? ?? false;
          _screenBlankingTimeoutSeconds =
              blanking['timeoutSeconds'] as int? ?? 300;
          _screenBlankingMode =
              blanking['mode'] as String? ?? 'output-power';
          _screenBlankingOutput = blanking['output'] as String?;
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
    if (env.containsKey('TERMINAL_SCREEN_BLANKING')) {
      _screenBlankingEnabled =
          env['TERMINAL_SCREEN_BLANKING']?.toLowerCase() == 'true';
    }
    if (env.containsKey('TERMINAL_SCREEN_BLANKING_TIMEOUT')) {
      _screenBlankingTimeoutSeconds =
          int.tryParse(env['TERMINAL_SCREEN_BLANKING_TIMEOUT'] ?? '') ??
              _screenBlankingTimeoutSeconds;
    }
    if (env.containsKey('TERMINAL_SCREEN_BLANKING_MODE')) {
      _screenBlankingMode =
          env['TERMINAL_SCREEN_BLANKING_MODE'] ?? _screenBlankingMode;
    }
    if (env.containsKey('TERMINAL_SCREEN_BLANKING_OUTPUT')) {
      _screenBlankingOutput = env['TERMINAL_SCREEN_BLANKING_OUTPUT'];
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
    _screenBlankingEnabled = false;
    _screenBlankingTimeoutSeconds = 300;
    _screenBlankingMode = 'output-power';
    _screenBlankingOutput = null;
    _soundsEnabled = true;
    _displayName = null;
    _backendDisplayName = null;
    _creditLimitPolicy = CreditLimitPolicy.shipped;
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
