import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

/// Persistent warning shown while the backend refuses this terminal's token as
/// expired (#106, #395).
///
/// A terminal token now lives a year (ADR-0036), so when one does run out it
/// has been a year since anybody thought about it, and the first symptom used
/// to be sales that quietly failed to upload — a bar that looks like it is
/// working and is not. This says so instead, in the one sentence staff can act
/// on: it is not something they can fix, an administrator has to issue a new
/// token.
///
/// Unlike [PairingMismatchBanner] there is no resume action. A pairing mismatch
/// is a judgement about data continuity that staff at the bar can make; an
/// expired credential is simply gone, and no amount of confirming brings it
/// back. It clears on its own on the first successful sync after the new token
/// is entered here.
class CredentialExpiredBanner extends StatelessWidget {
  const CredentialExpiredBanner({super.key});

  @override
  Widget build(BuildContext context) {
    final sync = context.watch<SyncProvider>();
    if (!sync.credentialExpired) {
      return const SizedBox.shrink();
    }

    final l10n = AppLocalizations.of(context)!;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        key: const Key('credential-expired-banner'),
        onTap: () => showCredentialExpiredModal(context),
        child: Container(
          width: double.infinity,
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.lg,
            vertical: AppSpacing.md,
          ),
          color: AppColors.dangerStrong,
          child: Row(
            children: [
              const Icon(Icons.lock_clock, color: Colors.white),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: Text(
                  l10n.credentialExpiredWarning,
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: AppFontSizes.lg,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The detail behind the banner: what happened, and the exact thing to ask an
/// administrator for. Dismiss only — there is nothing here staff can authorise.
void showCredentialExpiredModal(BuildContext context) {
  showDialog(
    context: context,
    builder: (context) {
      final l10n = AppLocalizations.of(context)!;

      return AlertDialog(
        key: const Key('credential-expired-dialog'),
        backgroundColor: AppColors.borderDark,
        title: Text(
          l10n.credentialExpiredTitle,
          style: const TextStyle(color: Colors.white),
        ),
        content: SizedBox(
          width: 480,
          child: Text(
            l10n.credentialExpiredInstruction,
            style: const TextStyle(color: AppColors.textSecondary),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: Text(l10n.dismiss),
          ),
        ],
      );
    },
  );
}
