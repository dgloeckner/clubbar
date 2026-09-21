// The dialog's heartbeat, which is what tells the recovery service that a
// dispense still has an owner (#945).
import 'package:clubbar_terminal/models/cart_item.dart';
import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:clubbar_terminal/widgets/dispensing_progress_dialog.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';

class MockCartService extends Mock implements CartService {}

class MockConfigService extends Mock implements ConfigService {}

class MockDispenserClient extends Mock implements DispenserClient {}

void main() {
  late MockCartService cartService;
  late MockConfigService config;
  late MockDispenserClient client;

  setUp(() {
    cartService = MockCartService();
    config = MockConfigService();
    client = MockDispenserClient();

    when(() => config.dispenserBaseUrl).thenReturn('http://dispenser');
    when(() => config.dispenserApiKey).thenReturn('key');
    when(() => config.dispenserTimeoutMs).thenReturn(1000);
    when(() => config.dispenserPollIntervalMs).thenReturn(50);
    when(() => cartService.updateDispenserOperationState(
          dispenserTxId: any(named: 'dispenserTxId'),
          state: any(named: 'state'),
          transactionsCreated: any(named: 'transactionsCreated'),
          lastKnownDispensed: any(named: 'lastKnownDispensed'),
          pollingActive: any(named: 'pollingActive'),
          lastPolledAt: any(named: 'lastPolledAt'),
          acknowledged: any(named: 'acknowledged'),
        )).thenAnswer((_) async => (true, null));
  });

  Future<void> pumpDialog(WidgetTester tester) async {
    await tester.pumpWidget(
      Provider<ConfigService>.value(
        value: config,
        child: MaterialApp(
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
          ],
          supportedLocales: AppLocalizations.supportedLocales,
          home: DispensingProgressDialog(
            dispenserTxId: 'disp-heartbeat',
            tokenProducts: [
              CartItem(
                productId: 'prod-token',
                productName: 'Token',
                priceCents: 200,
                quantity: 2,
                language: 'de',
                requiresDispenser: true,
              ),
            ],
            cartService: cartService,
            client: client,
            onComplete: (_) {},
            onError: (_) {},
          ),
        ),
      ),
    );
  }

  testWidgets('a failed poll still refreshes last_polled_at', (tester) async {
    when(() => client.dispenseTokens(
            txId: any(named: 'txId'), quantity: any(named: 'quantity')))
        .thenAnswer((_) async => DispenseResult(
            txId: 'disp-heartbeat',
            state: 'dispensing',
            quantity: 2,
            dispensed: 0,
            countReliable: true,
          ));
    // The WiFi drops the moment the motor starts: every poll from here on
    // throws, and before #945 the timestamp simply aged while the dialog sat
    // there — which is how the recovery tick decided the row was abandoned.
    when(() => client.getStatus(any()))
        .thenThrow(DispenserException('Connection timeout'));

    await pumpDialog(tester);
    await tester.pump(); // let the POST settle
    clearInteractions(cartService);

    await tester.pump(const Duration(milliseconds: 60)); // one poll tick
    await tester.pump();

    verify(() => cartService.updateDispenserOperationState(
          dispenserTxId: 'disp-heartbeat',
          lastPolledAt: any(named: 'lastPolledAt'),
          acknowledged: any(named: 'acknowledged'),
        )).called(greaterThanOrEqualTo(1));

    // Leave no timer running into the next test.
    await tester.pumpWidget(const SizedBox());
    await tester.pump(const Duration(seconds: 1));
  });
}
