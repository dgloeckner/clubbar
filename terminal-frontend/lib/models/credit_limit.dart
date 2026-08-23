import 'package:flutter/foundation.dart';

import 'package:clubbar_terminal/config/app_config.dart';

/// Where a projected tab sits relative to the terminal's credit limit.
enum CreditLimitStatus {
  /// Comfortably inside the limit — say nothing.
  ok,

  /// Inside the limit but in the warning band — tell the member, let them buy.
  approaching,

  /// Past the limit — checkout is blocked until the cart shrinks or the tab
  /// is settled.
  exceeded,
}

/// One verdict on "may this member buy this cart?" (UC-T11 E3, UC-T12).
///
/// Pure and self-contained on purpose: the same verdict drives the cart
/// screen's banner, the checkout button's enabled state and the service-side
/// block, so those three can never disagree about where the line is. The
/// caller supplies the money; nothing here reads the database or the clock.
///
/// Sign convention follows the rest of the terminal (see `AppMoney`):
/// positive cents mean the member owes money, negative mean credit. Credit is
/// therefore never a limit problem.
@immutable
class CreditLimitCheck {
  /// The member's effective tab *before* this cart (their Deckel, including
  /// transactions not yet synced to the backend).
  final int currentBalanceCents;

  /// What the cart in front of them would add.
  final int cartTotalCents;

  /// The configured ceiling. Zero or less means "no limit enforced".
  final int limitCents;

  /// The tab from which the member is warned. Equals [limitCents] when
  /// enforcement is off, which keeps [warnsMember] false in that case.
  final int warnAtCents;

  const CreditLimitCheck._({
    required this.currentBalanceCents,
    required this.cartTotalCents,
    required this.limitCents,
    required this.warnAtCents,
  });

  /// Evaluate a cart against an **already-resolved** ceiling.
  ///
  /// [limitCents] and [warnThresholdPercent] are required, and that is the
  /// point (ADR-0046): they used to default to [AppConfig], which meant any
  /// call site could silently enforce a compiled-in constant instead of the
  /// club's configured ceiling. Resolve through [CreditLimitPolicy.forMember]
  /// and pass the result — a caller that forgets now fails to compile rather
  /// than quietly refusing the wrong member.
  factory CreditLimitCheck.evaluate({
    required int currentBalanceCents,
    required int cartTotalCents,
    required int limitCents,
    required int warnThresholdPercent,
  }) {
    final enforced = limitCents > 0;
    return CreditLimitCheck._(
      currentBalanceCents: currentBalanceCents,
      cartTotalCents: cartTotalCents,
      limitCents: limitCents,
      warnAtCents:
          enforced ? (limitCents * warnThresholdPercent) ~/ 100 : limitCents,
    );
  }

  /// The tab the member would carry after paying for this cart.
  int get projectedBalanceCents => currentBalanceCents + cartTotalCents;

  CreditLimitStatus get status {
    if (limitCents <= 0) return CreditLimitStatus.ok;
    if (projectedBalanceCents > limitCents) return CreditLimitStatus.exceeded;
    if (projectedBalanceCents >= warnAtCents) {
      return CreditLimitStatus.approaching;
    }
    return CreditLimitStatus.ok;
  }

  /// Landing exactly on the limit is allowed — only going past it is not
  /// (UC-T12 edge cases).
  bool get blocksCheckout => status == CreditLimitStatus.exceeded;

  /// Whether the member should be told about the limit at all.
  bool get warnsMember => status != CreditLimitStatus.ok;
}

/// The club's settings, and the one place the override-or-default rule is
/// expressed on this side of the wire (ADR-0046 rule 1).
///
/// Its twin is `CreditLimitPolicy::forMember()` in the backend. That
/// duplication is deliberate and is accepted *because it is one line on each
/// side*: this terminal decides at checkout with no backend reachable, so it
/// cannot ask. If this method ever grows a second condition, the two copies
/// stop being trivially comparable — which is why a per-member warning band,
/// per-category limits and time-boxed limits are all non-goals of the epic.
///
/// The club default and the member's override arrive on **separate** channels:
/// the default from `GET /sync/config`, the override on the member's own row
/// from `/sync/members`. A backend-resolved figure was rejected because
/// `/sync/members` is a delta on `updated_at` — a club setting touches no
/// member row, so the new default would arrive one member at a time, whenever
/// each next changed for some unrelated reason.
@immutable
class CreditLimitPolicy {
  /// The ceiling that applies to a member who has none of their own, in cents.
  /// Zero means the club caps nobody.
  final int defaultLimitCents;

  /// Share of the effective ceiling from which a member is warned but still
  /// served. Club-wide: an override sets a ceiling, never a band.
  final int warnThresholdPercent;

  const CreditLimitPolicy({
    required this.defaultLimitCents,
    required this.warnThresholdPercent,
  });

  /// What this build enforces before its first successful `/sync/config` poll.
  static const CreditLimitPolicy shipped = CreditLimitPolicy(
    defaultLimitCents: AppConfig.balanceLimitCents,
    warnThresholdPercent: AppConfig.balanceWarnThresholdPercent,
  );

  /// The ceiling that applies to one member, in cents.
  ///
  /// `null` means inherit — the member was never singled out and moves with
  /// the club default, which is what a club-level default is for. `0` is a
  /// different answer: a deliberate "no ceiling for this member", which
  /// survives a change to the club default rather than following it.
  int forMember(int? overrideCents) => overrideCents ?? defaultLimitCents;

  /// Evaluate a cart for one member, resolving their ceiling first — the
  /// single call every screen, widget and service goes through, so the banner,
  /// the Buy button and the service-side refusal cannot disagree.
  CreditLimitCheck evaluate({
    required int? memberLimitCents,
    required int currentBalanceCents,
    required int cartTotalCents,
  }) =>
      CreditLimitCheck.evaluate(
        currentBalanceCents: currentBalanceCents,
        cartTotalCents: cartTotalCents,
        limitCents: forMember(memberLimitCents),
        warnThresholdPercent: warnThresholdPercent,
      );

  @override
  bool operator ==(Object other) =>
      other is CreditLimitPolicy &&
      other.defaultLimitCents == defaultLimitCents &&
      other.warnThresholdPercent == warnThresholdPercent;

  @override
  int get hashCode => Object.hash(defaultLimitCents, warnThresholdPercent);

  @override
  String toString() =>
      'CreditLimitPolicy(default: $defaultLimitCents, warn: $warnThresholdPercent%)';
}
