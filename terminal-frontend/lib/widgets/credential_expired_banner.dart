import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/sync_service.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

/// Persistent warning shown while the backend refuses this terminal's
/// credential (#106, #395, #890).
///
/// A terminal token now lives a year (ADR-0036), so when one does run out it
/// has been a year since anybody thought about it, and the first symptom used
/// to be sales that quietly failed to upload — a bar that looks like it is
/// working and is not. That is the benign case. The same banner also covers
/// the hostile one: a revoked token or a deactivated terminal, which is what
/// an admin's response to a stolen or decommissioned device produces. Both
/// say so in the one sentence staff can act on, worded for which it is — an
/// expired token is fixed with a routine rotation, a revoked one means
/// contacting the club about why it was pulled.
///
/// Unlike [PairingMismatchBanner] there is no resume action. A pairing mismatch
/// is a judgement about data continuity that staff at the bar can make; a
/// refused credential is simply gone or withdrawn, and no amount of confirming
/// brings it back. It clears on its own on the first successful sync after the
/// credential is fixed here.
class CredentialExpiredBanner extends StatelessWidget {
  const CredentialExpiredBanner({super.key});

  @override
  Widget build(BuildContext context) {
    final sync = context.watch<SyncProvider>();
    final reason = sync.credentialRefusal;
    if (reason == null) {
      return const SizedBox.shrink();
    }

    final l10n = AppLocalizations.of(context)!;
    final warning = reason == CredentialRefusalReason.revoked
        ? l10n.credentialRevokedWarning
        : l10n.credentialExpiredWarning;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        key: const Key('credential-expired-banner'),
        onTap: () => showCredentialExpiredModal(context, reason),
        child: Container(
          width: double.infinity,
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.lg,
            vertical: AppSpacing.md,
          ),
          color: AppColors.dangerStrong,
          child: Row(
            children: [
              Icon(
                reason == CredentialRefusalReason.revoked
                    ? Icons.block
                    : Icons.lock_clock,
                color: Colors.white,
              ),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: Text(
                  warning,
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
void showCredentialExpiredModal(
  BuildContext context,
  CredentialRefusalReason reason,
) {
  showDialog(
    context: context,
    builder: (context) {
      final l10n = AppLocalizations.of(context)!;
      final revoked = reason == CredentialRefusalReason.revoked;

      return AlertDialog(
        key: const Key('credential-expired-dialog'),
        backgroundColor: AppColors.borderDark,
        title: Text(
          revoked ? l10n.credentialRevokedTitle : l10n.credentialExpiredTitle,
          style: const TextStyle(color: Colors.white),
        ),
        content: SizedBox(
          width: 480,
          child: Text(
            revoked
                ? l10n.credentialRevokedInstruction
                : l10n.credentialExpiredInstruction,
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
