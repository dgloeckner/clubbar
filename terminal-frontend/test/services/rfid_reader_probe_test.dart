import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/services/rfid_reader_probe.dart';

/// A trimmed-down `/proc/bus/input/devices`, in the kernel's own layout: an
/// internal keyboard, the RFID reader, and a mouse. Only the lines the probe
/// reads are kept verbatim in shape.
const _devicesWithReader = '''
I: Bus=0011 Vendor=0001 Product=0001 Version=ab41
N: Name="AT Translated Set 2 keyboard"
P: Phys=isa0060/serio0/input0
H: Handlers=sysrq kbd event0 leds
B: EV=120013

I: Bus=0003 Vendor=ffff Product=0035 Version=0100
N: Name="Sycreader RFID Technology Co., Ltd USB Reader"
P: Phys=usb-0000:00:14.0-1/input0
H: Handlers=sysrq kbd event3
B: EV=120013

I: Bus=0003 Vendor=046d Product=c52b Version=0111
N: Name="Logitech USB Receiver"
P: Phys=usb-0000:00:14.0-2/input1
H: Handlers=mouse0 event4
B: EV=17
''';

/// The same machine after the reader was unplugged: its block is simply gone.
const _devicesWithoutReader = '''
I: Bus=0011 Vendor=0001 Product=0001 Version=ab41
N: Name="AT Translated Set 2 keyboard"
P: Phys=isa0060/serio0/input0
H: Handlers=sysrq kbd event0 leds
B: EV=120013

I: Bus=0003 Vendor=046d Product=c52b Version=0111
N: Name="Logitech USB Receiver"
P: Phys=usb-0000:00:14.0-2/input1
H: Handlers=mouse0 event4
B: EV=17
''';

void main() {
  group('RfidReaderIdentity', () {
    test('is unspecified when nothing is configured', () {
      expect(const RfidReaderIdentity().isSpecified, isFalse);
      expect(
        const RfidReaderIdentity(vendorId: '  ', namePattern: '').isSpecified,
        isFalse,
      );
      expect(const RfidReaderIdentity(vendorId: 'ffff').isSpecified, isTrue);
    });

    test('matches ids regardless of case, padding and 0x prefix', () {
      const identity = RfidReaderIdentity(vendorId: '0xFFFF', productId: '35');

      expect(
        identity.matches(vendorId: 'ffff', productId: '0035', name: 'reader'),
        isTrue,
      );
    });

    test('requires every configured criterion to match', () {
      const identity = RfidReaderIdentity(
        vendorId: 'ffff',
        productId: '0035',
        namePattern: 'RFID',
      );

      expect(
        identity.matches(
          vendorId: 'ffff',
          productId: '0035',
          name: 'Sycreader RFID USB Reader',
        ),
        isTrue,
      );
      // Right ids, wrong device name.
      expect(
        identity.matches(
          vendorId: 'ffff',
          productId: '0035',
          name: 'Generic Keyboard',
        ),
        isFalse,
      );
      // Right name, wrong product.
      expect(
        identity.matches(
          vendorId: 'ffff',
          productId: '0099',
          name: 'Sycreader RFID USB Reader',
        ),
        isFalse,
      );
    });

    test('name matching is case-insensitive', () {
      const identity = RfidReaderIdentity(namePattern: 'usb reader');

      expect(
        identity.matches(
          vendorId: 'ffff',
          productId: '0035',
          name: 'Sycreader RFID Technology Co., Ltd USB Reader',
        ),
        isTrue,
      );
    });

    test('an unspecified identity matches nothing', () {
      expect(
        const RfidReaderIdentity()
            .matches(vendorId: 'ffff', productId: '0035', name: 'anything'),
        isFalse,
      );
    });
  });

  group('InputDevicesRfidReaderProbe', () {
    late Directory tempDir;
    late String devicesPath;

    setUp(() {
      tempDir = Directory.systemTemp.createTempSync('rfid_probe_test_');
      devicesPath = '${tempDir.path}/devices';
    });

    tearDown(() {
      if (tempDir.existsSync()) tempDir.deleteSync(recursive: true);
    });

    void writeDevices(String content) =>
        File(devicesPath).writeAsStringSync(content);

    InputDevicesRfidReaderProbe probeFor(RfidReaderIdentity identity) =>
        InputDevicesRfidReaderProbe(
          identity: identity,
          devicesPath: devicesPath,
        );

    test('finds the reader by vendor and product id', () async {
      writeDevices(_devicesWithReader);

      final probe = probeFor(
        const RfidReaderIdentity(vendorId: 'ffff', productId: '0035'),
      );

      expect(await probe.isReaderPresent(), isTrue);
    });

    test('finds the reader by name pattern alone', () async {
      writeDevices(_devicesWithReader);

      final probe = probeFor(const RfidReaderIdentity(namePattern: 'RFID'));

      expect(await probe.isReaderPresent(), isTrue);
    });

    test('reports absence once the reader block is gone', () async {
      writeDevices(_devicesWithoutReader);

      final probe = probeFor(
        const RfidReaderIdentity(vendorId: 'ffff', productId: '0035'),
      );

      expect(await probe.isReaderPresent(), isFalse);
    });

    test('does not confuse another device with the reader', () async {
      writeDevices(_devicesWithReader);

      // The name belongs to the reader's block, the ids to the mouse's — a
      // probe that matched across blocks would wrongly report the reader.
      final probe = probeFor(
        const RfidReaderIdentity(
          vendorId: '046d',
          productId: 'c52b',
          namePattern: 'RFID',
        ),
      );

      expect(await probe.isReaderPresent(), isFalse);
    });

    test('matches a reader listed last, with no trailing blank line', () async {
      writeDevices(
        'I: Bus=0003 Vendor=ffff Product=0035 Version=0100\n'
        'N: Name="USB Reader"\n'
        'H: Handlers=kbd event3',
      );

      final probe = probeFor(const RfidReaderIdentity(vendorId: 'ffff'));

      expect(await probe.isReaderPresent(), isTrue);
    });

    test('is unknown when the device list does not exist', () async {
      // No file written: a machine without procfs (macOS dev box) must not be
      // told its reader is missing.
      final probe = probeFor(const RfidReaderIdentity(vendorId: 'ffff'));

      expect(await probe.isReaderPresent(), isNull);
    });

    test('is unknown when no reader identity is configured', () async {
      writeDevices(_devicesWithReader);

      final probe = probeFor(const RfidReaderIdentity());

      expect(await probe.isReaderPresent(), isNull);
    });
  });
}
