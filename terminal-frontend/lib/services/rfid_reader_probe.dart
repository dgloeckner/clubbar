import 'dart:convert';
import 'dart:io';

/// What identifies the RFID reader among the machine's input devices.
///
/// A keyboard-wedge reader is a USB HID keyboard and nothing about it says
/// "RFID" unless you know what to look for, so the terminal is *told* what to
/// look for rather than guessing: a USB vendor/product id, a substring of the
/// device name, or both. Guessing would be worse than not looking at all — a
/// wrong guess puts a "scanner not connected" screen on a perfectly healthy
/// terminal and stops the bar.
///
/// With nothing specified ([isSpecified] false) reader monitoring stays off and
/// the terminal behaves exactly as it did before issue #35.
class RfidReaderIdentity {
  /// USB vendor id as printed by the kernel, e.g. `ffff`. Case and a `0x`
  /// prefix are irrelevant — see [_normalizeId].
  final String? vendorId;

  /// USB product id as printed by the kernel, e.g. `0035`.
  final String? productId;

  /// Case-insensitive substring of the device name, e.g. `RFID`.
  final String? namePattern;

  const RfidReaderIdentity({
    this.vendorId,
    this.productId,
    this.namePattern,
  });

  static const RfidReaderIdentity unspecified = RfidReaderIdentity();

  /// True when at least one criterion is set. Without one there is nothing to
  /// match and monitoring must stay off.
  bool get isSpecified =>
      _clean(vendorId) != null ||
      _clean(productId) != null ||
      _clean(namePattern) != null;

  /// Whether an input device with these kernel-reported attributes is the
  /// configured reader. Every criterion that *is* set must match.
  bool matches({
    required String vendorId,
    required String productId,
    required String name,
  }) {
    if (!isSpecified) return false;

    final wantVendor = _normalizeId(this.vendorId);
    if (wantVendor != null && wantVendor != _normalizeId(vendorId)) {
      return false;
    }

    final wantProduct = _normalizeId(this.productId);
    if (wantProduct != null && wantProduct != _normalizeId(productId)) {
      return false;
    }

    final wantName = _clean(namePattern);
    if (wantName != null &&
        !name.toLowerCase().contains(wantName.toLowerCase())) {
      return false;
    }

    return true;
  }

  /// `0xFFFF`, `FFFF` and `ffff` all name the same device — compare them as one
  /// lower-case, zero-padded value.
  static String? _normalizeId(String? raw) {
    final value = _clean(raw);
    if (value == null) return null;
    final stripped = value.toLowerCase().startsWith('0x')
        ? value.substring(2)
        : value;
    return stripped.toLowerCase().padLeft(4, '0');
  }

  static String? _clean(String? raw) {
    final trimmed = raw?.trim();
    return (trimmed == null || trimmed.isEmpty) ? null : trimmed;
  }
}

/// Answers the one question the idle screen needs: is the reader plugged in?
abstract class RfidReaderProbe {
  /// `true`/`false` when presence could be determined, `null` when it could
  /// not — no reader configured, or a platform that cannot be asked.
  ///
  /// `null` is deliberately distinct from `false`: "we cannot tell" must leave
  /// the UI exactly as it was, while "not present" changes it.
  Future<bool?> isReaderPresent();
}

/// Reads the kernel's input-device list, the cheapest reliable presence check
/// on the Linux kiosk this terminal is deployed on (see `INSTALL.md`).
///
/// `/proc/bus/input/devices` is a plain-text list of blocks, one per input
/// device, each opening with the bus/vendor/product line and carrying a name:
///
/// ```
/// I: Bus=0003 Vendor=ffff Product=0035 Version=0100
/// N: Name="Sycreader RFID Technology Co., Ltd USB Reader"
/// H: Handlers=sysrq kbd event3
/// ```
///
/// Unplugging the reader removes its block; plugging it back adds it again,
/// which is what makes recovery work without a restart.
class InputDevicesRfidReaderProbe implements RfidReaderProbe {
  static const String defaultDevicesPath = '/proc/bus/input/devices';

  static final RegExp _idLine = RegExp(
    r'^I:.*\bVendor=([0-9a-fA-F]+).*\bProduct=([0-9a-fA-F]+)',
  );
  static final RegExp _nameLine = RegExp(r'^N:\s*Name="(.*)"\s*$');

  final RfidReaderIdentity identity;
  final String devicesPath;

  InputDevicesRfidReaderProbe({
    required this.identity,
    this.devicesPath = defaultDevicesPath,
  });

  @override
  Future<bool?> isReaderPresent() async {
    if (!identity.isSpecified) return null;

    final file = File(devicesPath);
    try {
      if (!await file.exists()) return null; // not Linux, or no procfs
      return _contains(await file.readAsString());
    } on IOException {
      // An unreadable device list says nothing about the reader; claiming it is
      // gone would blank the idle screen over a procfs hiccup.
      return null;
    }
  }

  /// Whether [devicesFileContent] lists a device matching [identity].
  ///
  /// Blocks are separated by blank lines, and the last one is not followed by
  /// one, so the candidate is evaluated both on the separator and at the end.
  bool _contains(String devicesFileContent) {
    String? vendor;
    String? product;
    var name = '';

    bool candidateMatches() {
      final currentVendor = vendor;
      final currentProduct = product;
      if (currentVendor == null || currentProduct == null) return false;
      return identity.matches(
        vendorId: currentVendor,
        productId: currentProduct,
        name: name,
      );
    }

    for (final line in const LineSplitter().convert(devicesFileContent)) {
      if (line.trim().isEmpty) {
        if (candidateMatches()) return true;
        vendor = null;
        product = null;
        name = '';
        continue;
      }

      final ids = _idLine.firstMatch(line);
      if (ids != null) {
        // A new block starts here even without a blank line before it.
        if (candidateMatches()) return true;
        vendor = ids.group(1);
        product = ids.group(2);
        name = '';
        continue;
      }

      final named = _nameLine.firstMatch(line);
      if (named != null) name = named.group(1)!;
    }

    return candidateMatches();
  }
}
