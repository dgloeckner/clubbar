import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/services/cart_service.dart';
import 'package:ruderbar_terminal/services/config_service.dart';
import 'package:ruderbar_terminal/services/dispenser_client.dart';

/// Progress dialog shown while dispensing tokens
///
/// Shows real-time progress as tokens are dispensed, polls for completion,
/// then calls onComplete or onError based on result.
///
/// Tracks polling state for crash recovery: sets polling_active=1 on init,
/// updates state on each poll, sets polling_active=0 on dispose.
class DispensingProgressDialog extends StatefulWidget {
  final String dispenserTxId;
  final List<CartItem> tokenProducts;
  final CartService cartService;
  final Function(DispenseResult) onComplete;
  final Function(DispenserException) onError;

  const DispensingProgressDialog({
    required this.dispenserTxId,
    required this.tokenProducts,
    required this.cartService,
    required this.onComplete,
    required this.onError,
    super.key,
  });

  @override
  State<DispensingProgressDialog> createState() =>
      _DispensingProgressDialogState();
}

class _DispensingProgressDialogState extends State<DispensingProgressDialog> {
  late DispenserClient _client;
  String _txId = '';
  int _quantity = 0;
  int _dispensed = 0;
  String _state = 'starting';

  @override
  void initState() {
    super.initState();

    // Get ConfigService from context
    final config = context.read<ConfigService>();
    _client = DispenserClient(
      baseUrl: config.dispenserBaseUrl!,
      apiKey: config.dispenserApiKey!,
      timeoutMs: config.dispenserTimeoutMs,
    );

    // Calculate total tokens from all cart items
    _quantity =
        widget.tokenProducts.fold(0, (sum, item) => sum + item.quantity);

    // Mark polling as active (prevents recovery service interference)
    _setPollingActive(true);

    _startDispense();
  }

  Future<void> _setPollingActive(bool active) async {
    await widget.cartService.updateDispenserOperationState(
      dispenserTxId: widget.dispenserTxId,
      pollingActive: active ? 1 : 0,
      lastPolledAt: DateTime.now().toUtc().toIso8601String(),
    );
  }

  Future<void> _startDispense() async {
    try {
      _txId = widget.dispenserTxId; // Use passed txId instead of generating
      final result =
          await _client.dispenseTokens(txId: _txId, quantity: _quantity);

      if (mounted) {
        setState(() {
          _state = result.state;
          _dispensed = result.dispensed;
        });

        _pollStatus();
      }
    } on DispenserBusyException catch (e) {
      if (mounted) {
        widget.onError(e);
        Navigator.of(context).pop();
      }
    } on DispenserException catch (e) {
      if (mounted) {
        widget.onError(e);
        Navigator.of(context).pop();
      }
    }
  }

  Future<void> _pollStatus() async {
    final config = context.read<ConfigService>();
    final pollInterval =
        Duration(milliseconds: config.dispenserPollIntervalMs);

    while (_state == 'dispensing') {
      await Future.delayed(pollInterval);

      try {
        final result = await _client.getStatus(_txId);

        if (mounted) {
          setState(() {
            _state = result.state;
            _dispensed = result.dispensed;
          });

          // Update tracking state (for recovery service monitoring)
          await widget.cartService.updateDispenserOperationState(
            dispenserTxId: widget.dispenserTxId,
            state: result.state,
            lastKnownDispensed: result.dispensed,
            lastPolledAt: DateTime.now().toUtc().toIso8601String(),
          );

          if (_state == 'done' || _state == 'error') {
            widget.onComplete(result);
            // Wait 5 seconds before closing so user can read success message
            await Future.delayed(const Duration(seconds: 5));
            if (mounted) {
              Navigator.of(context).pop();
            }
            break;
          }
        }
      } catch (e) {
        if (mounted) {
          widget.onError(DispenserException('Polling failed: $e'));
          Navigator.of(context).pop();
        }
        break;
      }
    }
  }

  @override
  void dispose() {
    // Mark polling as inactive when dialog closes
    _setPollingActive(false);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final isComplete = _state == 'done' || _state == 'error';

    // SUCCESS = any tokens dispensed (even if state is "error" due to jam)
    final isSuccess = _dispensed > 0;
    final isPartial = isSuccess && _dispensed < _quantity;

    // ERROR = completed but zero tokens dispensed
    final isError = isComplete && _dispensed == 0;

    return Dialog(
      child: Padding(
        padding: const EdgeInsets.all(24.0),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (isComplete) ...[
              // Show result icon - GREEN checkmark for any success (full or partial)
              Icon(
                isError ? Icons.error_outline : Icons.check_circle,
                size: 48,
                color: isError ? Colors.red : Colors.green,
              ),
              const SizedBox(height: 16),
              // Show result message - POSITIVE framing even for partial
              Text(
                isError
                    ? 'Dispensing Failed'
                    : 'Successfully dispensed $_dispensed ${_dispensed == 1 ? 'token' : 'tokens'}!',
                style: Theme.of(context).textTheme.titleLarge,
                textAlign: TextAlign.center,
              ),
              if (isPartial) ...[
                const SizedBox(height: 8),
                Text(
                  'No worries! You\'ll only be charged for $_dispensed ${_dispensed == 1 ? 'token' : 'tokens'}.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.grey[700]),
                ),
                const SizedBox(height: 8),
                Text(
                  'Please notify staff - the dispenser may need refilling.',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Colors.orange[700],
                    fontWeight: FontWeight.w600,
                  ),
                ),              ],
            ] else ...[
              // Show dispensing in progress
              Text(
                l10n.dispensingTokens,
                style: Theme.of(context).textTheme.titleLarge,
              ),
            ],
            const SizedBox(height: 24),
            _buildProgressIndicator(),
            if (!isComplete) ...[
              const SizedBox(height: 16),
              const CircularProgressIndicator(),
              const SizedBox(height: 16),
              Text(l10n.pleaseWait),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildProgressIndicator() {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: List.generate(_quantity, (index) {
        final dispensed = index < _dispensed;
        return Padding(
          padding: const EdgeInsets.symmetric(horizontal: 4.0),
          child: Text(
            dispensed ? '●' : '○',
            style: TextStyle(
              fontSize: 24,
              color: dispensed ? Colors.green : Colors.grey,
            ),
          ),
        );
      }),
    );
  }
}
