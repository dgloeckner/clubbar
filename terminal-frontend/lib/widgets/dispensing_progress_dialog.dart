import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/models/cart_item.dart';
import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/dispense_session.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:clubbar_terminal/services/sound_service.dart';

/// Progress dialog shown while tokens are dispensed.
///
/// It owns no state machine of its own any more: [DispenseSession] runs the
/// POST, the serial polling and the outcome, and this widget draws its
/// [DispensePhase] and token count and plays the sound cue. #946 moved that
/// logic out precisely so it could be tested against a real dispenser without
/// a widget tree — `flow_test/` drives the very same class this dialog drives.
///
/// The dialog therefore never decides what the dispense *was*: it hands
/// `onComplete` whatever the device last reported. A polling timeout is not a
/// completed dispense, and saying so here is what keeps the tracking row alive
/// for reconciliation.
class DispensingProgressDialog extends StatefulWidget {
  final String dispenserTxId;
  final List<CartItem> tokenProducts;
  final CartService cartService;
  final Function(DispenseResult) onComplete;
  final Function(DispenserException) onError;

  /// The client to talk to the dispenser with. The app has exactly one and
  /// passes it in (`main.dart` builds it, `CartProvider` hands it on); a test
  /// passes a mock. It is never closed here — the recovery and health services
  /// share it.
  final DispenserClient client;

  const DispensingProgressDialog({
    required this.dispenserTxId,
    required this.tokenProducts,
    required this.cartService,
    required this.client,
    required this.onComplete,
    required this.onError,
    super.key,
  });

  @override
  State<DispensingProgressDialog> createState() =>
      _DispensingProgressDialogState();
}

class _DispensingProgressDialogState extends State<DispensingProgressDialog> {
  late final DispenseSession _session;
  late final int _quantity;

  @override
  void initState() {
    super.initState();

    final config = context.read<ConfigService>();
    _quantity = widget.tokenProducts.fold(0, (sum, item) => sum + item.quantity);

    _session = DispenseSession(
      client: widget.client,
      cartService: widget.cartService,
      txId: widget.dispenserTxId,
      quantity: _quantity,
      pollInterval: Duration(milliseconds: config.dispenserPollIntervalMs),
    )..addListener(_onSessionChanged);

    unawaited(_run());
  }

  @override
  void dispose() {
    _session.removeListener(_onSessionChanged);
    _session.abandon();
    _session.dispose();
    super.dispose();
  }

  void _onSessionChanged() {
    if (mounted) setState(() {});
  }

  Future<void> _run() async {
    final result = await _session.run();
    if (!mounted) return;

    if (result == null) {
      widget.onError(_session.error ?? DispenserException('Dispense failed'));
      Navigator.of(context).pop();
      return;
    }

    _playCompletionSound();
    widget.onComplete(result);

    // Auto-close after 5 seconds
    Future.delayed(const Duration(seconds: 5), () {
      if (mounted) {
        Navigator.of(context).pop();
      }
    });
  }

  /// Sound cue for the dispense outcome, played the moment it is known — not
  /// after the dialog's 5-second auto-close delay, since that is exactly the
  /// window in which a member walks away believing they got everything they
  /// paid for (issue #37).
  void _playCompletionSound() {
    if (!mounted) return;
    context.read<SoundService>().play(
          _session.dispensed < _quantity
              ? SoundEvent.dispensePartial
              : SoundEvent.dispenseSuccess,
        );
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final dispensed = _session.dispensed;
    // The completion screen is only ever reached with at least one token: a
    // dispense that produced none ends in `failed`, and the dialog pops
    // straight away for `onError` to speak for it. So there is no failure
    // face to draw here — a jam that still produced tokens is a partial
    // success, and says so.
    final isComplete = _session.phase == DispensePhase.finished;
    final isPartial = dispensed > 0 && dispensed < _quantity;

    return Dialog(
      child: Padding(
        padding: const EdgeInsets.all(24.0),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (isComplete) ...[
              // Show result icon - GREEN checkmark for any success (full or partial)
              const Icon(
                Icons.check_circle,
                size: 48,
                color: Colors.green,
              ),
              const SizedBox(height: 16),
              // Show result message - POSITIVE framing even for partial
              Text(
                l10n.dispensingSuccess(dispensed),
                style: Theme.of(context).textTheme.titleLarge,
                textAlign: TextAlign.center,
              ),
              if (isPartial) ...[
                const SizedBox(height: 8),
                Text(
                  l10n.dispensingPartialCharged(dispensed),
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.grey[700]),
                ),
                const SizedBox(height: 8),
                Text(
                  l10n.dispensingNeedsRefilling,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Colors.orange[700],
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ] else ...[
              // Show dispensing in progress
              Text(
                _session.phase == DispensePhase.requesting
                    ? l10n.dispensingStarting
                    : l10n.dispensingTokens,
                style: Theme.of(context).textTheme.titleLarge,
              ),
            ],
            const SizedBox(height: 24),
            _buildProgressIndicator(dispensed),
            if (!isComplete) ...[
              const SizedBox(height: 16),
              const CircularProgressIndicator(),
              const SizedBox(height: 16),
              Text(
                _session.phase == DispensePhase.requesting
                    ? l10n.dispensingConnecting
                    : l10n.pleaseWait,
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildProgressIndicator(int dispensed) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: List.generate(_quantity, (index) {
        final isOut = index < dispensed;
        return Padding(
          padding: const EdgeInsets.symmetric(horizontal: 4.0),
          child: Text(
            isOut ? '●' : '○',
            style: TextStyle(
              fontSize: 24,
              color: isOut ? Colors.green : Colors.grey,
            ),
          ),
        );
      }),
    );
  }
}
