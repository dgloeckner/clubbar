import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';

class MemberDetailsScreen extends StatelessWidget {
  const MemberDetailsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;

    return Consumer<MembersProvider>(
      builder: (context, membersProvider, child) {
        final member = membersProvider.selectedMember;

        if (member == null) {
          return Center(
            child: Text(l10n.noMemberSelected),
          );
        }

        // Body content only - MainLayout provides Scaffold and header
        return SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  l10n.memberDetails,
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
                const SizedBox(height: 24),
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        _buildDetailRow(l10n.firstName, member.firstName ?? 'N/A'),
                        const SizedBox(height: 12),
                        _buildDetailRow(l10n.lastName, member.lastName ?? 'N/A'),
                        const SizedBox(height: 12),
                        _buildDetailRow(l10n.accountStatus, member.isActive == 1 ? l10n.memberActive : l10n.memberInactive),
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
