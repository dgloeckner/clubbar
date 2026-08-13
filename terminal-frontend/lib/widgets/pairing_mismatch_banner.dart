import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

/// Persistent warning shown while the terminal's backend instance_id does
/// not match the one it last synced with (ADR-0035, #380).
///
/// It cannot be dismissed and never clears itself: a mismatch means local
/// sales may or may not exist on whatever backend this now is, and the
/// terminal stays blocked — no push, no pull — until staff deliberately
/// confirm it is safe via the dialog's resume action.
class PairingMismatchBanner extends StatelessWidget {
  const PairingMismatchBanner({super.key});

  @override
  Widget build(BuildContext context) {
    final sync = context.watch<SyncProvider>();
    if (!sync.pairingMismatch) {
      return const SizedBox.shrink();
    }

    final l10n = AppLocalizations.of(context)!;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        key: const Key('pairing-mismatch-banner'),
        onTap: () => showPairingMismatchModal(context, sync),
        child: Container(
          width: double.infinity,
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.lg,
            vertical: AppSpacing.md,
          ),
          color: AppColors.dangerStrong,
          child: Row(
            children: [
              const Icon(Icons.warning_amber_rounded, color: Colors.white),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: Text(
                  l10n.pairingMismatchWarning,
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

/// The mismatch dialog: names the at-risk count and offers the one way out —
/// a deliberate, audited resume — rather than a plain dismiss.
void showPairingMismatchModal(BuildContext context, SyncProvider sync) {
  showDialog(
    context: context,
    builder: (context) => _PairingMismatchDialog(sync: sync),
  );
}

class _PairingMismatchDialog extends StatefulWidget {
  final SyncProvider sync;

  const _PairingMismatchDialog({required this.sync});

  @override
  State<_PairingMismatchDialog> createState() =>
      _PairingMismatchDialogState();
}

class _PairingMismatchDialogState extends State<_PairingMismatchDialog> {
  bool _resuming = false;
  bool _failed = false;

  Future<void> _resume() async {
    setState(() {
      _resuming = true;
      _failed = false;
    });

    await widget.sync.acknowledgePairingMismatch();

    if (!mounted) return;

    if (widget.sync.pairingMismatch) {
      setState(() {
        _resuming = false;
        _failed = true;
      });
    } else {
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;

    return AlertDialog(
      key: const Key('pairing-mismatch-dialog'),
      backgroundColor: AppColors.borderDark,
      title: Text(
        l10n.pairingMismatchTitle,
        style: const TextStyle(color: Colors.white),
      ),
      content: SizedBox(
        width: 480,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              l10n.pairingMismatchInstruction(
                  widget.sync.pairingMismatchTransactionCount),
              style: const TextStyle(color: AppColors.textSecondary),
            ),
            if (_failed) ...[
              const SizedBox(height: AppSpacing.md),
              Text(
                l10n.pairingMismatchResumeFailed,
                style: const TextStyle(color: AppColors.dangerStrong),
              ),
            ],
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(l10n.dismiss),
        ),
        FilledButton(
          key: const Key('pairing-mismatch-resume-button'),
          onPressed: _resuming ? null : _resume,
          child: Text(l10n.pairingMismatchResume),
        ),
      ],
    );
  }
}
