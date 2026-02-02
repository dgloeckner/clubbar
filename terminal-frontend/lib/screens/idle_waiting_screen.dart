import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/providers/rfid_provider.dart';

class IdleWaitingScreen extends StatelessWidget {
  const IdleWaitingScreen({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return Consumer<MembersProvider>(
      builder: (context, membersProvider, child) {
        final error = membersProvider.lastError;

        if (error != null) {
          return Center(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(
                  Icons.error_outline,
                  size: 80,
                  color: Theme.of(context).colorScheme.error,
                ),
                const SizedBox(height: 24),
                Text(
                  error,
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                    color: Theme.of(context).colorScheme.error,
                  ),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 24),
                ElevatedButton(
                  onPressed: () {
                    membersProvider.clearError();
                  },
                  child: const Text('Try Again'),
                ),
              ],
            ),
          );
        }

        return Center(
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(
                Icons.nfc,
                size: 80,
                color: Theme.of(context).colorScheme.primary,
              ),
              const SizedBox(height: 24),
              Text(
                'Scan Member Card',
                style: Theme.of(context).textTheme.headlineSmall,
              ),
              const SizedBox(height: 16),
              Text(
                'Hold your card near the reader',
                style: Theme.of(context).textTheme.bodyMedium,
              ),
              const SizedBox(height: 32),
              // Test button for mock RFID detection (development only)
              ElevatedButton.icon(
                onPressed: () async {
                  final rfidProvider = context.read<RfidProvider>();
                  await rfidProvider.simulateCardDetection();
                },
                icon: const Icon(Icons.touch_app),
                label: const Text('Simulate Card Scan'),
              ),
            ],
          ),
        );
      },
    );
  }
}
