import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/services/config_service.dart';
import 'package:ruderbar_terminal/services/dispenser_client.dart';

/// Progress dialog shown while dispensing tokens
///
/// Shows real-time progress as tokens are dispensed, polls for completion,
/// then calls onComplete or onError based on result.
class DispensingProgressDialog extends StatefulWidget {
  final List<CartItem> tokenProducts;
  final Function(DispenseResult) onComplete;
  final Function(DispenserException) onError;

  const DispensingProgressDialog({
    required this.tokenProducts,
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

    _startDispense();
  }

  Future<void> _startDispense() async {
    try {
      _txId = _client.generateTxId();
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
      }
    } on DispenserException catch (e) {
      if (mounted) {
        widget.onError(e);
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
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final isComplete = _state == 'done' || _state == 'error';
    final isSuccess = _state == 'done';
    final isPartial = isSuccess && _dispensed < _quantity;
    final isError = _state == 'error';

    return Dialog(
      child: Padding(
        padding: const EdgeInsets.all(24.0),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (isComplete) ...[
              // Show result icon
              Icon(
                isError
                    ? Icons.error_outline
                    : isPartial
                        ? Icons.warning
                        : Icons.check_circle,
                size: 48,
                color: isError
                    ? Colors.red
                    : isPartial
                        ? Colors.orange
                        : Colors.green,
              ),
              const SizedBox(height: 16),
              // Show result message
              Text(
                isError
                    ? 'Dispensing Failed'
                    : isPartial
                        ? 'Only $_dispensed of $_quantity tokens dispensed'
                        : 'Successfully dispensed $_quantity tokens!',
                style: Theme.of(context).textTheme.titleLarge,
                textAlign: TextAlign.center,
              ),
              if (isPartial) ...[
                const SizedBox(height: 8),
                Text(
                  'You will be charged for $_dispensed tokens only.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.grey[700]),
                ),
              ],
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
