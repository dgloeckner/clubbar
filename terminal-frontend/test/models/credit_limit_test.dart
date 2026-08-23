import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/config/app_config.dart';
import 'package:clubbar_terminal/models/credit_limit.dart';

void main() {
  CreditLimitCheck check(int balance, int cart, {int limit = 10000}) =>
      CreditLimitCheck.evaluate(
        currentBalanceCents: balance,
        cartTotalCents: cart,
        limitCents: limit,
        warnThresholdPercent: 80,
      );

  group('CreditLimitCheck.evaluate', () {
    test('projected balance is current tab plus cart', () {
      expect(check(2500, 1100).projectedBalanceCents, 3600);
    });

    test('a small tab well inside the limit is ok', () {
      final result = check(1000, 500);
      expect(result.status, CreditLimitStatus.ok);
      expect(result.blocksCheckout, isFalse);
      expect(result.warnsMember, isFalse);
    });

    test('credit (negative balance) is never a limit problem', () {
      expect(check(-5000, 1000).status, CreditLimitStatus.ok);
    });

    group('warning band', () {
      test('just below the warning threshold stays ok', () {
        expect(check(7000, 999).status, CreditLimitStatus.ok);
      });

      test('exactly at the warning threshold warns', () {
        final result = check(7000, 1000);
        expect(result.status, CreditLimitStatus.approaching);
        expect(result.warnsMember, isTrue);
        expect(result.blocksCheckout, isFalse);
      });

      test('warnAtCents reports the threshold it used', () {
        expect(check(0, 0).warnAtCents, 8000);
      });
    });

    group('limit boundary', () {
      test('landing exactly on the limit is allowed', () {
        final result = check(9000, 1000);
        expect(result.projectedBalanceCents, 10000);
        expect(result.status, CreditLimitStatus.approaching);
        expect(result.blocksCheckout, isFalse);
      });

      test('one cent over the limit blocks checkout', () {
        final result = check(9000, 1001);
        expect(result.status, CreditLimitStatus.exceeded);
        expect(result.blocksCheckout, isTrue);
        expect(result.warnsMember, isTrue);
      });

      test('a member already over the limit is blocked by an empty cart too',
          () {
        expect(check(12000, 0).blocksCheckout, isTrue);
      });

      test('a member already over the limit is blocked with items', () {
        expect(check(12000, 500).blocksCheckout, isTrue);
      });
    });

    group('limit configuration', () {
      test('a non-positive limit disables enforcement entirely', () {
        expect(check(50000, 50000, limit: 0).status, CreditLimitStatus.ok);
        expect(check(50000, 50000, limit: -1).blocksCheckout, isFalse);
      });

      test('a custom limit is honoured', () {
        expect(check(4900, 200, limit: 5000).blocksCheckout, isTrue);
        expect(check(4800, 200, limit: 5000).blocksCheckout, isFalse);
      });
    });

  });

  /// The one place the override-or-default rule is expressed on this side
  /// (ADR-0047 rule 1). Its twin is `CreditLimitPolicy::forMember()` in PHP,
  /// and the cases below are the same ones its unit test pins — deliberately,
  /// because keeping the two comparable is what makes duplicating the rule
  /// safe for a terminal that has to decide with nothing reachable.
  group('CreditLimitPolicy.forMember', () {
    const policy = CreditLimitPolicy(
      defaultLimitCents: 10000,
      warnThresholdPercent: 80,
    );

    test('a member without an override follows the club', () {
      expect(policy.forMember(null), 10000);
    });

    test('raising the club default lifts a member who inherits', () {
      const raised = CreditLimitPolicy(
        defaultLimitCents: 15000,
        warnThresholdPercent: 80,
      );
      expect(raised.forMember(null), 15000);
    });

    test('an override wins over the club default', () {
      expect(policy.forMember(5000), 5000);
      expect(policy.forMember(20000), 20000);
    });

    /// The failure this feature most needs to avoid: an emptied field arriving
    /// as 0 would read as "unlimited", not as "back to the club default".
    test('zero and null are different answers', () {
      expect(policy.forMember(0), 0, reason: '0 means unlimited');
      expect(policy.forMember(null), 10000, reason: 'null means follow the club');
    });

    test('a member with an override is capped even when the club is not', () {
      const uncappedClub = CreditLimitPolicy(
        defaultLimitCents: 0,
        warnThresholdPercent: 80,
      );
      expect(uncappedClub.forMember(5000), 5000);
      expect(uncappedClub.forMember(null), 0);
    });

    test('the shipped policy is the constant it replaces', () {
      expect(CreditLimitPolicy.shipped.defaultLimitCents,
          AppConfig.balanceLimitCents);
      expect(CreditLimitPolicy.shipped.warnThresholdPercent,
          AppConfig.balanceWarnThresholdPercent);
    });
  });

  /// End to end through the seam: a resolved ceiling decides the verdict, and
  /// the band is always the club's share of whatever ceiling the member ends
  /// up with (ADR-0047 decision 4).
  group('a member checked against their own ceiling', () {
    const policy = CreditLimitPolicy(
      defaultLimitCents: 10000,
      warnThresholdPercent: 80,
    );

    CreditLimitCheck evaluate(int balance, int cart, int? override) =>
        CreditLimitCheck.evaluate(
          currentBalanceCents: balance,
          cartTotalCents: cart,
          limitCents: policy.forMember(override),
          warnThresholdPercent: policy.warnThresholdPercent,
        );

    test('a raised override lets them buy past the club default', () {
      expect(evaluate(9900, 500, 20000).blocksCheckout, isFalse);
      expect(evaluate(9900, 500, null).blocksCheckout, isTrue);
    });

    test('a lowered override blocks them below the club default', () {
      expect(evaluate(4900, 200, 5000).blocksCheckout, isTrue);
      expect(evaluate(4900, 200, null).blocksCheckout, isFalse);
    });

    test('a member with no ceiling is never blocked', () {
      expect(evaluate(500000, 100000, 0).blocksCheckout, isFalse);
      expect(evaluate(500000, 100000, 0).warnsMember, isFalse);
    });

    test('landing exactly on their own ceiling is still allowed', () {
      final result = evaluate(4800, 200, 5000);
      expect(result.projectedBalanceCents, 5000);
      expect(result.blocksCheckout, isFalse);
      expect(result.status, CreditLimitStatus.approaching);
    });

    test('the warning band is the club share of their own ceiling', () {
      expect(evaluate(0, 0, 5000).warnAtCents, 4000);
      expect(evaluate(0, 0, 20000).warnAtCents, 16000);
    });
  });
}
