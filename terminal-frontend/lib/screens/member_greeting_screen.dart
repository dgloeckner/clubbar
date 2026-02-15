import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';

class MemberGreetingScreen extends StatelessWidget {
  const MemberGreetingScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Consumer<MembersProvider>(
      builder: (context, membersProvider, child) {
        final member = membersProvider.selectedMember;

        if (member == null) {
          // Show error or idle state
          return _buildNoMemberState(context, membersProvider);
        }

        // Show member greeting
        return _buildMemberGreeting(context, member);
      },
    );
  }

  Widget _buildMemberGreeting(BuildContext context, MembersCacheData member) {
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Text(
          'Welcome, ${member.firstName ?? "Member"}',
          style: Theme.of(context).textTheme.headlineSmall,
        ),
        const SizedBox(height: 8),
        Text(
          member.lastName ?? '',
          style: Theme.of(context).textTheme.titleMedium,
        ),
        const SizedBox(height: 32),
        SizedBox(
          width: 200,
          child: ElevatedButton(
            onPressed: () {
              // Navigate to products screen
            },
            child: const Text('Continue Shopping'),
          ),
        ),
      ],
    );
  }

  Widget _buildNoMemberState(BuildContext context, MembersProvider provider) {
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        if (provider.lastError != null)
          Text(
            provider.lastError!,
            style: Theme.of(context).textTheme.bodyLarge?.copyWith(
              color: Colors.red,
            ),
            textAlign: TextAlign.center,
          ),
        const SizedBox(height: 24),
        SizedBox(
          width: 200,
          child: ElevatedButton(
            onPressed: () {
              // Scan card (handled by parent)
            },
            child: const Text('Scan Card'),
          ),
        ),
      ],
    );
  }
}
