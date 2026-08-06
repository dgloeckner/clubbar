import 'package:flutter_test/flutter_test.dart';
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

    test('defaults come from AppConfig when not overridden', () {
      final result = CreditLimitCheck.evaluate(
        currentBalanceCents: 0,
        cartTotalCents: 10001,
      );
      expect(result.limitCents, 10000);
      expect(result.blocksCheckout, isTrue);
    });
  });
}
