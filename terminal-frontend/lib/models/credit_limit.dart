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

  /// Evaluate a cart against the limit.
  ///
  /// [limitCents] and [warnThresholdPercent] default to [AppConfig] — the seam
  /// where a per-member or backend-supplied limit will land later.
  factory CreditLimitCheck.evaluate({
    required int currentBalanceCents,
    required int cartTotalCents,
    int limitCents = AppConfig.balanceLimitCents,
    int warnThresholdPercent = AppConfig.balanceWarnThresholdPercent,
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
