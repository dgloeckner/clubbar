import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/services/cart_service.dart';
import 'package:ruderbar_terminal/services/config_service.dart';
import 'package:ruderbar_terminal/services/dispenser_client.dart';

/// States for the dispensing state machine
enum DispensingState {
  idle,       // Not started
  requesting, // POST /dispense in progress
  dispensing, // Polling for status
  done,       // Completed successfully
  error,      // Failed
}

/// Progress dialog shown while dispensing tokens (background state machine)
///
/// Architecture:
/// - Background worker manages state (requesting → dispensing → done/error)
/// - UI updates reactively based on state
/// - All HTTP operations run in background (non-blocking)
/// - Timeouts: 30s for POST, 10s per token for polling
/// - Retries on network errors
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
  late int _quantity;

  // State machine
  DispensingState _state = DispensingState.idle;
  int _dispensed = 0;

  // Background worker
  Timer? _pollingTimer;
  Timer? _timeoutTimer;
  int _retryCount = 0;

  // Configuration
  static const int _maxRequestTimeout = 30; // 30 seconds for POST /dispense
  static const int _timeoutPerToken = 10;   // 10 seconds per token for polling
  static const int _maxRetries = 3;
  static const int _retryDelayMs = 1000;

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
    _quantity = widget.tokenProducts.fold(0, (sum, item) => sum + item.quantity);

    // Mark polling as active (prevents recovery service interference)
    _setPollingActive(true);

    // Start background state machine
    _startStateMachine();
  }

  @override
  void dispose() {
    _pollingTimer?.cancel();
    _timeoutTimer?.cancel();
    _setPollingActive(false);
    super.dispose();
  }

  Future<void> _setPollingActive(bool active) async {
    await widget.cartService.updateDispenserOperationState(
      dispenserTxId: widget.dispenserTxId,
      pollingActive: active ? 1 : 0,
      lastPolledAt: DateTime.now().toUtc().toIso8601String(),
    );
  }

  /// Start the background state machine
  void _startStateMachine() {
    _transitionTo(DispensingState.requesting);
    _startDispenseRequest();
  }

  /// Transition to new state
  void _transitionTo(DispensingState newState) {
    if (!mounted) return;
    setState(() {
      _state = newState;
    });
  }

  /// Step 1: POST /dispense (with retries and timeout)
  Future<void> _startDispenseRequest() async {
    // Set timeout for the requesting phase (30 seconds)
    _timeoutTimer = Timer(Duration(seconds: _maxRequestTimeout), () {
      if (_state == DispensingState.requesting) {
        _handleError(DispenserException('Request timeout after $_maxRequestTimeout seconds'));
      }
    });

    await _tryDispenseRequest();
  }

  Future<void> _tryDispenseRequest() async {
    try {
      final result = await _client.dispenseTokens(
        txId: widget.dispenserTxId,
        quantity: _quantity,
      );

      // Cancel timeout timer
      _timeoutTimer?.cancel();

      if (!mounted) return;

      // Update state from response
      setState(() {
        _dispensed = result.dispensed;
      });

      // Update tracking
      await widget.cartService.updateDispenserOperationState(
        dispenserTxId: widget.dispenserTxId,
        state: result.state,
        lastKnownDispensed: result.dispensed,
        lastPolledAt: DateTime.now().toUtc().toIso8601String(),
      );

      // Transition to dispensing state and start polling
      _transitionTo(DispensingState.dispensing);
      _startPolling();

    } on DispenserBusyException catch (e) {
      _timeoutTimer?.cancel();
      _handleError(e);
    } on DispenserNotFoundException catch (e) {
      _timeoutTimer?.cancel();
      _handleError(e);
    } on DispenserException catch (e) {
      // Network error - retry if under limit
      if (_retryCount < _maxRetries) {
        _retryCount++;
        print('Dispense request failed, retry $_retryCount/$_maxRetries: ${e.message}');
        await Future.delayed(Duration(milliseconds: _retryDelayMs));
        if (mounted && _state == DispensingState.requesting) {
          await _tryDispenseRequest();
        }
      } else {
        _timeoutTimer?.cancel();
        _handleError(DispenserException('Request failed after $_maxRetries retries: ${e.message}'));
      }
    }
  }

  /// Step 2: Poll for status (with timeout based on token count)
  void _startPolling() {
    // Set timeout for polling phase (10 seconds per token)
    final pollingTimeout = _quantity * _timeoutPerToken;
    _timeoutTimer?.cancel();
    _timeoutTimer = Timer(Duration(seconds: pollingTimeout), () {
      if (_state == DispensingState.dispensing) {
        // Timeout - but if we dispensed some tokens, that's partial success
        if (_dispensed > 0) {
          _handlePartialSuccess();
        } else {
          _handleError(DispenserException('Polling timeout after $pollingTimeout seconds'));
        }
      }
    });

    // Start periodic polling
    final config = context.read<ConfigService>();
    final pollInterval = Duration(milliseconds: config.dispenserPollIntervalMs);

    _pollingTimer = Timer.periodic(pollInterval, (_) => _poll());
  }

  Future<void> _poll() async {
    if (_state != DispensingState.dispensing) {
      _pollingTimer?.cancel();
      return;
    }

    try {
      final result = await _client.getStatus(widget.dispenserTxId);

      if (!mounted) return;

      // Update state
      setState(() {
        _dispensed = result.dispensed;
      });

      // Update tracking
      await widget.cartService.updateDispenserOperationState(
        dispenserTxId: widget.dispenserTxId,
        state: result.state,
        lastKnownDispensed: result.dispensed,
        lastPolledAt: DateTime.now().toUtc().toIso8601String(),
      );

      // Check if completed
      if (result.state == 'done') {
        _pollingTimer?.cancel();
        _timeoutTimer?.cancel();
        _handleSuccess(result);
      } else if (result.state == 'error') {
        _pollingTimer?.cancel();
        _timeoutTimer?.cancel();
        // Dispenser reports error - but if we got some tokens, partial success
        if (_dispensed > 0) {
          _handlePartialSuccess();
        } else {
          _handleError(DispenserException('Dispenser reported error'));
        }
      }
      // else state is still "dispensing", keep polling

    } on DispenserNotFoundException catch (e) {
      _pollingTimer?.cancel();
      _timeoutTimer?.cancel();
      _handleError(e);
    } on DispenserException catch (e) {
      // Network error during polling - keep trying (timeout will catch it)
      print('Polling error: ${e.message}');
    }
  }

  void _handleSuccess(DispenseResult result) {
    _transitionTo(DispensingState.done);
    widget.onComplete(result);

    // Auto-close after 5 seconds
    Future.delayed(const Duration(seconds: 5), () {
      if (mounted) {
        Navigator.of(context).pop();
      }
    });
  }

  void _handlePartialSuccess() {
    // Create a partial success result
    final result = DispenseResult(
      txId: widget.dispenserTxId,
      state: 'done', // Mark as done even though partial
      quantity: _quantity,
      dispensed: _dispensed,
    );
    _transitionTo(DispensingState.done);
    widget.onComplete(result);

    // Auto-close after 5 seconds
    Future.delayed(const Duration(seconds: 5), () {
      if (mounted) {
        Navigator.of(context).pop();
      }
    });
  }

  void _handleError(DispenserException error) {
    if (!mounted) return;

    setState(() {
      _state = DispensingState.error;
    });

    widget.onError(error);
    Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final isComplete = _state == DispensingState.done;
    final isError = _state == DispensingState.error;

    // SUCCESS = any tokens dispensed (even if state is "error" due to jam)
    final isSuccess = _dispensed > 0;
    final isPartial = isSuccess && _dispensed < _quantity;

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
                ),
              ],
            ] else ...[
              // Show dispensing in progress
              Text(
                _state == DispensingState.requesting
                    ? 'Starting dispenser...'
                    : l10n.dispensingTokens,
                style: Theme.of(context).textTheme.titleLarge,
              ),
            ],
            const SizedBox(height: 24),
            _buildProgressIndicator(),
            if (!isComplete) ...[
              const SizedBox(height: 16),
              const CircularProgressIndicator(),
              const SizedBox(height: 16),
              Text(
                _state == DispensingState.requesting
                    ? 'Connecting to dispenser...'
                    : l10n.pleaseWait,
              ),
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
