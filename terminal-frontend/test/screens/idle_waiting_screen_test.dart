import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/rfid_provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/screens/idle_waiting_screen.dart';

class MockRfidProvider extends Mock implements RfidProvider {}
class MockMembersProvider extends Mock implements MembersProvider {}

void main() {
  group('IdleWaitingScreen', () {
    late MockRfidProvider mockRfidProvider;
    late MockMembersProvider mockMembersProvider;

    setUp(() {
      mockRfidProvider = MockRfidProvider();
      mockMembersProvider = MockMembersProvider();
    });

    testWidgets('displays scan prompt message', (WidgetTester tester) async {
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);
      when(() => mockMembersProvider.lastError).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
            providers: [
              ChangeNotifierProvider<RfidProvider>.value(value: mockRfidProvider),
              ChangeNotifierProvider<MembersProvider>.value(value: mockMembersProvider),
            ],
            child: const Scaffold(body: IdleWaitingScreen()),
          ),
        ),
      );

      expect(find.text('Scan Member Card'), findsOneWidget);
    });

    testWidgets('displays error message when member not found', (WidgetTester tester) async {
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);
      when(() => mockMembersProvider.lastError).thenReturn('Member not found');
      when(() => mockMembersProvider.clearError()).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
            providers: [
              ChangeNotifierProvider<RfidProvider>.value(value: mockRfidProvider),
              ChangeNotifierProvider<MembersProvider>.value(value: mockMembersProvider),
            ],
            child: const Scaffold(body: IdleWaitingScreen()),
          ),
        ),
      );

      expect(find.text('Member not found'), findsOneWidget);
      expect(find.text('Try Again'), findsOneWidget);
    });
  });
}
