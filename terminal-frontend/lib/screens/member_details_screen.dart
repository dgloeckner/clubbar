import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';

class MemberDetailsScreen extends StatelessWidget {
  const MemberDetailsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Consumer<MembersProvider>(
      builder: (context, membersProvider, child) {
        final member = membersProvider.selectedMember;

        if (member == null) {
          return const Center(
            child: Text('No member selected'),
          );
        }

        // Body content only - MainLayout provides Scaffold and header
        return SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Member Details',
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
                const SizedBox(height: 24),
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        _buildDetailRow('First Name', member.firstName ?? 'N/A'),
                        const SizedBox(height: 12),
                        _buildDetailRow('Last Name', member.lastName ?? 'N/A'),
                        const SizedBox(height: 12),
                        _buildDetailRow('Account Status', member.isActive == 1 ? 'Active' : 'Inactive'),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          );
      },
    );
  }

  Widget _buildDetailRow(String label, String value) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: const TextStyle(fontWeight: FontWeight.bold)),
        Text(value),
      ],
    );
  }
}
