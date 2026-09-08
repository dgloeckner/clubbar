/// The one canonical spelling of an RFID card UID, and the parsing that gets
/// every reader dialect to it.
///
/// Card lookup is an exact string match ([MembersRepository.findByCardUid]), so
/// the *spelling* a UID arrives in decides whether a valid member gets in. That
/// would be harmless if a chip had one spelling. It does not — the same 4-byte
/// chip reaches the terminal as any of:
///
/// | Reader output | What it is |
/// |---|---|
/// | `001EB4CB`    | uppercase hex — the canonical form |
/// | `001eb4cb`    | lowercase hex (issue #18) |
/// | `00:1E:B4:CB` | grouped by byte, also `-`, `.` or spaces |
/// | `0x001EB4CB`  | prefixed, as a diagnostic tool prints it |
/// | `1EB4CB`      | leading zero byte dropped |
/// | `0002012363`  | the same value in decimal, zero-padded to 10 digits |
/// | `CBB41E00`    | least-significant byte first |
///
/// Store whatever arrived and the club that replaces a broken reader with a
/// differently configured one finds that **no member card matches any more**:
/// every UID has to be re-entered by hand, and nothing in the failure says why
/// — each card simply reads as unknown. So the input is parsed, reduced to one
/// canonical form, and only that is ever compared or stored.
///
/// Canonical is **uppercase hex, no separators, whole bytes, four to ten of
/// them**: `001EB4CB`.
///
/// ## What the string cannot tell you
///
/// Two of the dialects above are not decidable from the value alone:
///
/// - `12345678` is a valid 4-byte hex UID *and* a valid decimal one.
/// - A byte-reversed UID is a perfectly well-formed UID.
///
/// Neither is guessed. Both are answered by [CardUidFormat] — the terminal's
/// configured reader profile (`rfidReader.uidFormat` in `config.json`) — which
/// is the only place that actually knows what the hardware in this clubhouse is
/// set to. Replacing a reader is then a one-line config change instead of
/// re-registering every card.
///
/// ## Why this side pads a short UID and the backend does not
///
/// A scan of `1EB4CB` is padded here to `001EB4CB`; the same value typed into
/// the admin member form is refused as too short (`App\Shared\Utils\CardUid`).
/// That is deliberate, not drift. Input here comes from a reader, which has no
/// fingers: a short scan is a suppressed leading zero and nothing else, and a
/// padded value that belongs to nobody simply reads as an unknown card, which
/// costs nothing. Input to the member form comes from a volunteer, where `ABCD`
/// is somebody who stopped typing — and a form that quietly accepted it would
/// file a member under a UID no card carries.
library;

/// How a reader spells the UID's *value*.
enum CardUidEncoding {
  /// Hexadecimal, the overwhelmingly common case and the default.
  hex,

  /// Decimal — the classic 125 kHz / EM4100 keyboard-wedge output, typically
  /// zero-padded to the 10 digits a 32-bit value needs.
  ///
  /// Applied only to an all-digit input: a reader set to decimal still prints
  /// hex for a card whose UID contains `A`–`F`, and reading `1EB4CB` as decimal
  /// is not possible anyway.
  decimal,
}

/// Which end of the UID a reader sends first.
enum CardUidByteOrder {
  /// Most significant byte first — how a UID is printed on the card.
  msbFirst,

  /// Least significant byte first. Some readers report the bytes in the order
  /// they came off the air rather than the order they are printed in.
  lsbFirst,
}

/// A reader's output dialect: how it encodes the UID and in which byte order.
///
/// Configured per terminal, because it is a property of the *hardware*, not of
/// the card. See `ConfigService.rfidCardUidFormat`.
class CardUidFormat {
  final CardUidEncoding encoding;
  final CardUidByteOrder byteOrder;

  const CardUidFormat({
    this.encoding = CardUidEncoding.hex,
    this.byteOrder = CardUidByteOrder.msbFirst,
  });

  /// Uppercase hex, most significant byte first — what the vast majority of
  /// 13.56 MHz readers emit, and the format every stored UID is already in.
  static const hex = CardUidFormat();

  /// Hex with the bytes the other way round.
  static const hexReversed =
      CardUidFormat(byteOrder: CardUidByteOrder.lsbFirst);

  /// Decimal, most significant byte first — the usual EM4100 wedge output.
  static const decimal = CardUidFormat(encoding: CardUidEncoding.decimal);

  /// Decimal with the bytes the other way round.
  static const decimalReversed = CardUidFormat(
    encoding: CardUidEncoding.decimal,
    byteOrder: CardUidByteOrder.lsbFirst,
  );

  static const _byName = <String, CardUidFormat>{
    'hex': hex,
    'hex-reversed': hexReversed,
    'decimal': decimal,
    'decimal-reversed': decimalReversed,
  };

  /// The names accepted in `config.json` and `RFID_READER_UID_FORMAT`.
  static List<String> get names => _byName.keys.toList(growable: false);

  /// The format called [name], or null for an unknown one.
  ///
  /// Null rather than a fallback to [hex]: a misspelled profile silently
  /// falling back is the failure this whole file exists to prevent, so the
  /// caller reports it instead.
  static CardUidFormat? tryParse(String? name) =>
      name == null ? null : _byName[name.trim().toLowerCase()];

  String get name => _byName.entries.firstWhere((e) => e.value == this).key;

  @override
  bool operator ==(Object other) =>
      other is CardUidFormat &&
      other.encoding == encoding &&
      other.byteOrder == byteOrder;

  @override
  int get hashCode => Object.hash(encoding, byteOrder);

  @override
  String toString() => name;
}

/// Fewest bytes a card UID has (ADR-0014's table starts at 4-byte Mifare), and
/// therefore the width a UID printed without its leading zero bytes is padded
/// back out to.
const _minBytes = 4;

final _hexOnly = RegExp(r'^[0-9A-F]+$');
final _digitsOnly = RegExp(r'^[0-9]+$');
final _hexPrefix = RegExp(r'^0X');
final _canonical = RegExp(r'^(?:[0-9A-F]{2}){4,10}$');

/// Characters readers and diagnostic tools group bytes with. They carry no
/// information, so they are dropped rather than compared.
final _separators = RegExp(r'[\s:\-._]');

/// Reduce one reader's spelling of a card UID to the canonical form.
///
/// [format] is this terminal's reader profile and decides the two questions the
/// string cannot answer on its own — see the library doc.
///
/// **Call this exactly once per scan.** It is not idempotent under a decimal
/// profile: a decimal UID whose hex form happens to be all digits would be
/// converted a second time and land on a different card. [RfidProvider.handleCardScan]
/// is where every input path converges and is the one place that calls it;
/// the capture and the service above it pass the reader's characters through
/// untouched.
///
/// An input this cannot read as a UID at all — a stray keystroke, a
/// mistyped token — comes back trimmed and upper-cased but otherwise as it
/// arrived. It then matches nothing (which is the correct outcome) and still
/// appears verbatim in the scan log, which is what a reader dialect nobody has
/// seen yet has to be diagnosed from.
String normalizeCardUid(
  String cardUid, {
  CardUidFormat format = CardUidFormat.hex,
}) {
  final cleaned = cardUid
      .trim()
      .toUpperCase()
      .replaceAll(_separators, '')
      .replaceFirst(_hexPrefix, '');

  if (cleaned.isEmpty) return '';

  // The bytes *as the reader sent them*, before any reordering.
  String bytes;
  if (format.encoding == CardUidEncoding.decimal &&
      _digitsOnly.hasMatch(cleaned)) {
    final value = BigInt.tryParse(cleaned);
    if (value == null) return cleaned;
    // A decimal reader sends a *number*, which carries no width of its own, so
    // it is given the narrowest a card UID has. Done here rather than at the
    // end because for a reversed reader that width is what decides which end
    // the zero bytes were on: chip `CBB41E00` reaches a reversed decimal reader
    // as the value 0x001EB4CB, and only a value already widened to four bytes
    // reverses back to the chip rather than to `00CBB41E`.
    bytes = _padToWholeBytes(value.toRadixString(16).toUpperCase())
        .padLeft(_minBytes * 2, '0');
  } else if (_hexOnly.hasMatch(cleaned)) {
    bytes = _padToWholeBytes(cleaned);
  } else {
    return cleaned;
  }

  if (format.byteOrder == CardUidByteOrder.lsbFirst) {
    bytes = _reverseBytes(bytes);
  }

  // Put back the leading zero *bytes* a reader dropped: `1EB4CB` and
  // `001EB4CB` are the same chip, so padding is what makes them meet rather
  // than sit beside each other. After the reversal, because for a reversed
  // reader the value's leading zeros are the bytes it sent last.
  return bytes.padLeft(_minBytes * 2, '0');
}

/// Pad an odd digit count out to whole bytes — an odd count is half a written
/// byte, which is a leading zero somebody dropped.
///
/// Only to whole bytes, never to [_minBytes]: for a reversed reader the bytes
/// it sent are not yet in the order the value is written in, so widening here
/// would put the padding on the wrong end.
String _padToWholeBytes(String hex) => hex.length.isOdd ? '0$hex' : hex;

/// [normalizeCardUid] for the cache and API shapes where a member may carry no
/// card at all (an anonymized member keeps its booking history but loses its
/// UID). "No card" stays null rather than becoming an empty UID that a scan
/// could accidentally match.
///
/// Takes no [CardUidFormat] on purpose: this side reads values that came from
/// the backend, which stores only the canonical form. A reader profile applies
/// to what the *reader* typed, and applying one here would rewrite correct
/// stored UIDs into UIDs no card has.
String? normalizeCardUidOrNull(String? cardUid) =>
    cardUid == null ? null : normalizeCardUid(cardUid);

/// Whether [value] is already exactly what may be stored and compared.
bool isCanonicalCardUid(String value) => _canonical.hasMatch(value);

/// `001EB4CB` -> `CBB41E00`.
String _reverseBytes(String hex) {
  final buffer = StringBuffer();
  for (var i = hex.length - 2; i >= 0; i -= 2) {
    buffer.write(hex.substring(i, i + 2));
  }
  return buffer.toString();
}
