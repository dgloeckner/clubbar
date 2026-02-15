import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/providers/locale_provider.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:ruderbar_terminal/widgets/styled_components/member_info_card.dart';

class MemberDetailsPage extends StatelessWidget {
  const MemberDetailsPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xff0a1628),
      appBar: AppBar(
        backgroundColor: const Color(0xff0f1d32),
        title: const Text(
          'Member Info',
          style: TextStyle(
            color: Color(0xfff1f5f9),
            fontSize: AppFontSizes.xl,
            fontWeight: FontWeight.w600,
          ),
        ),
        leading: IconButton(
          icon: const Icon(Icons.arrow_back, color: Color(0xfff1f5f9)),
          onPressed: () => Navigator.of(context).pop(),
        ),
      ),
      body: SafeArea(
        child: Consumer2<MembersProvider, LocaleProvider>(
          builder: (context, membersProvider, localeProvider, child) {
            final member = membersProvider.selectedMember;

            if (member == null) {
              return const Center(
                child: Text(
                  'No member selected',
                  style: TextStyle(color: Color(0xff94a3b8)),
                ),
              );
            }

            return SingleChildScrollView(
              padding: const EdgeInsets.all(AppSpacing.lg),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Member card
                  MemberInfoCard(
                    member: member,
                    balanceCents: 0, // Balance not available in member data
                    locale: localeProvider.locale.languageCode,
                  ),
                  const SizedBox(height: AppSpacing.xl),

                  // Additional info section
                  Text(
                    'Account Information',
                    style: const TextStyle(
                      color: Color(0xfff1f5f9),
                      fontSize: AppFontSizes.lg,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: AppSpacing.lg),

                  // Account status
                  Container(
                    padding: const EdgeInsets.all(AppSpacing.lg),
                    decoration: BoxDecoration(
                      color: const Color(0xff1a2744),
                      borderRadius: BorderRadius.circular(AppBorderRadius.lg),
                      border: Border.all(
                        color: const Color(0xff334155),
                        width: 1,
                      ),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        _buildInfoRow(
                          'Status',
                          member.isActive == 1 ? 'Active' : 'Inactive',
                          member.isActive == 1
                              ? const Color(0xff22c55e)
                              : const Color(0xfff97316),
                        ),
                        const SizedBox(height: AppSpacing.md),
                        _buildInfoRow(
                          'SEPA Valid',
                          member.isSepaValid == 1 ? 'Yes' : 'No',
                          member.isSepaValid == 1
                              ? const Color(0xff22c55e)
                              : const Color(0xfff97316),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  Widget _buildInfoRow(String label, String value, Color valueColor) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(
          label,
          style: const TextStyle(
            color: Color(0xff94a3b8),
            fontSize: AppFontSizes.base,
            fontWeight: FontWeight.w500,
          ),
        ),
        Text(
          value,
          style: TextStyle(
            color: valueColor,
            fontSize: AppFontSizes.base,
            fontWeight: FontWeight.w600,
          ),
        ),
      ],
    );
  }
}
