import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/utils/deckel_sendoff.dart';
import 'package:clubbar_terminal/utils/icon_family.dart';

/// Issue #929, move 2: the receipt says what a bartender says, derived from
/// the icon family the Getränkewart already picked. No new field, no lookup,
/// and a wrong guess costs nothing — which is why the fallback is a
/// pleasantry rather than an apology.
void main() {
  group('productIconFamily', () {
    test('canonical beer names, alcohol-free included', () {
      for (final name in [
        'beer-pils',
        'beer-weizen',
        'beer-weizen-new',
        'beer-radler',
        'beer-alcohol-free',
      ]) {
        expect(productIconFamily(name), ProductIconFamily.beer, reason: name);
      }
    });

    test('legacy spellings land in the same families', () {
      expect(productIconFamily('PilsIcon'), ProductIconFamily.beer);
      expect(productIconFamily('RotweinIcon'), ProductIconFamily.wine);
      expect(productIconFamily('BembelIcon'), ProductIconFamily.cider);
      expect(productIconFamily('BretzelIcon'), ProductIconFamily.food);
      expect(productIconFamily('SaunaTokenIcon'), ProductIconFamily.sauna);
    });

    test('Apfelschorle is a soft drink, not a cider', () {
      // It shares the fruit and nothing else — a member handed "Prost" for a
      // spritzer would be told the terminal thinks they are drinking.
      expect(productIconFamily('spritzer-apple'), ProductIconFamily.other);
      expect(productIconFamily('juice-apple'), ProductIconFamily.other);
    });

    test('water, coffee and soda are nobody in particular', () {
      for (final name in ['water', 'water-small', 'coffee', 'soda']) {
        expect(productIconFamily(name), ProductIconFamily.other, reason: name);
      }
    });

    test('an unknown or absent name is other, not a guess', () {
      expect(productIconFamily('no-such-icon'), ProductIconFamily.other);
      expect(productIconFamily(null), ProductIconFamily.other);
    });
  });

  group('sendOffFor', () {
    test('beer, wine and cider each get Prost', () {
      expect(sendOffFor(['beer-pils']), DeckelSendOff.prost);
      expect(sendOffFor(['wine-red']), DeckelSendOff.prost);
      expect(sendOffFor(['cider-apfelwein']), DeckelSendOff.prost);
    });

    test('food alone gets Guten Appetit', () {
      expect(
        sendOffFor(['food-bretzel', 'food-bratwurst']),
        DeckelSendOff.appetit,
      );
    });

    test('sauna alone gets Gute Erholung', () {
      expect(
        sendOffFor(['sauna-session', 'sauna-towel']),
        DeckelSendOff.erholung,
      );
    });

    test('anything you clink wins the mixed receipt', () {
      // The round Jana actually buys: two Helles, two Wasser, a Brezel.
      expect(
        sendOffFor(['beer-pils', 'water', 'food-bretzel']),
        DeckelSendOff.prost,
      );
      expect(
        sendOffFor(['sauna-token', 'wine-white']),
        DeckelSendOff.prost,
      );
    });

    test('a mixture with nothing to clink falls back to Bis bald', () {
      // Food and sauna together is neither "enjoy your meal" nor "enjoy the
      // sauna", so it is neither.
      expect(
        sendOffFor(['food-bretzel', 'sauna-token']),
        DeckelSendOff.bisBald,
      );
      expect(sendOffFor(['water']), DeckelSendOff.bisBald);
      expect(sendOffFor(['coffee', 'food-crisps']), DeckelSendOff.bisBald);
    });

    test('unknown icons and an empty receipt are Bis bald', () {
      expect(sendOffFor(['no-such-icon']), DeckelSendOff.bisBald);
      expect(sendOffFor([null]), DeckelSendOff.bisBald);
      expect(sendOffFor(const <String?>[]), DeckelSendOff.bisBald);
    });
  });
}
