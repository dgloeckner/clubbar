import 'package:clubbar_terminal/utils/icon_family.dart';

/// How the receipt says goodbye (#929, move 2).
///
/// A bartender does not say "Buchung erfolgreich"; they say *Prost* to a
/// beer, *Guten Appetit* to a Bratwurst and *Gute Erholung* to a sauna
/// session. The receipt has everything it needs to do the same — the icon
/// family the Getränkewart already picked — so this needs no new field on
/// the product, no server round trip and no configuration.
enum DeckelSendOff {
  /// Something alcoholic-shaped was bought: beer, wine or cider.
  prost,

  /// Food, and only food.
  appetit,

  /// The sauna, and only the sauna.
  erholung,

  /// Everything else, and every mixture that is not one of the above:
  /// a water, a coffee, or a Bratwurst *and* a sauna token together.
  bisBald,
}

/// The send-off for a receipt whose lines carry [iconNames].
///
/// Reads as the bar does:
///
/// 1. **Anything you clink** — beer, wine or cider anywhere on the receipt —
///    wins outright. A round with a beer in it is a round, whatever else
///    rode along.
/// 2. Otherwise, a receipt that is *entirely* food gets [appetit] and one
///    that is *entirely* sauna gets [erholung].
/// 3. Anything else — a water, a mixture of food and sauna, an empty
///    receipt — gets [bisBald].
///
/// A wrong guess costs nothing, which is why the fallback is a pleasantry
/// and not an apology.
DeckelSendOff sendOffFor(Iterable<String?> iconNames) {
  final families = iconNames.map(productIconFamily).toList();
  if (families.isEmpty) return DeckelSendOff.bisBald;

  const clinkable = {
    ProductIconFamily.beer,
    ProductIconFamily.wine,
    ProductIconFamily.cider,
  };
  if (families.any(clinkable.contains)) return DeckelSendOff.prost;

  if (families.every((f) => f == ProductIconFamily.food)) {
    return DeckelSendOff.appetit;
  }
  if (families.every((f) => f == ProductIconFamily.sauna)) {
    return DeckelSendOff.erholung;
  }
  return DeckelSendOff.bisBald;
}
