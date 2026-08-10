import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'dart:async';
import 'package:clubbar_terminal/config/app_config.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';
import 'package:clubbar_terminal/widgets/styled_components/price_display.dart';

class _SessionData {
  final int billedCents;
  final bool isPartial;
  final int? dispenserRequested;
  final int? dispenserActual;
  final int? originalTotalCents;

  const _SessionData({
    required this.billedCents,
    required this.isPartial,
    this.dispenserRequested,
    this.dispenserActual,
    this.originalTotalCents,
  });
}

class CheckoutConfirmationScreen extends StatefulWidget {
  final String sessionId;

  const CheckoutConfirmationScreen({
    required this.sessionId,
    super.key,
  });

  @override
  State<CheckoutConfirmationScreen> createState() =>
      _CheckoutConfirmationScreenState();
}

class _CheckoutConfirmationScreenState extends State<CheckoutConfirmationScreen>
    with SingleTickerProviderStateMixin {
  Timer? _autoLoopTimer;
  Timer? _countdownTimer;
  int _secondsRemaining = AppConfig.receiptAutoReturnDelay.inSeconds;
  late AnimationController _scaleController;
  late Animation<double> _scaleAnimation;
  late Future<_SessionData> _sessionDataFuture;
  bool _autoNavStarted = false;

  /// Whom the receipt was issued to, captured once at mount.
  ///
  /// A receipt is a finished transaction, so it must not follow live session
  /// state: a card scan on this screen ends the shown session and starts the
  /// next member's (ADR-0027 rule 9), and a receipt still watching
  /// [MembersProvider] would repaint with a cleared or foreign identity while
  /// it fades out. The balance is already final here — the cart screen awaits
  /// `refreshDeckel()` before navigating.
  late final String _memberName;
  late final int _billedToBalanceCents;
  late final String _locale;

  /// What the checkout actually billed, captured once at mount.
  ///
  /// The only amount left if the session lookup fails (#16): [CartProvider]
  /// empties the cart during checkout, so it records the bill instead.
  late final int _lastBilledCents;

  @override
  void initState() {
    super.initState();

    final selectedMember = context.read<MembersProvider>().selectedMember;
    _memberName = selectedMember != null
        ? '${selectedMember.firstName} ${selectedMember.lastName}'
        : 'Member';
    _locale = selectedMember?.preferredLanguage ?? 'de';
    _billedToBalanceCents = context.read<MembersProvider>().memberDeckel ?? 0;
    _lastBilledCents = context.read<CartProvider>().lastCheckoutTotalCents;

    // Initialize scale-in animation
    _scaleController = AnimationController(
      duration: const Duration(milliseconds: 300),
      vsync: this,
    );

    _scaleAnimation = Tween<double>(begin: 0.8, end: 1.0).animate(
      CurvedAnimation(parent: _scaleController, curve: Curves.easeOut),
    );

    _scaleController.forward();

    _sessionDataFuture = _loadSessionData();
  }

  Future<_SessionData> _loadSessionData() async {
    final repo = context.read<TransactionsRepository>();
    final billedCents = await repo.getSessionTotal(widget.sessionId);
    final dispenserRow = await repo.getSessionDispenserInfo(widget.sessionId);

    final isPartial = dispenserRow != null &&
        dispenserRow.dispenserActual != null &&
        dispenserRow.dispenserRequested != null &&
        dispenserRow.dispenserActual! < dispenserRow.dispenserRequested!;

    int? originalTotalCents;
    if (isPartial && dispenserRow != null && dispenserRow.unitPriceCents != null) {
      originalTotalCents =
          dispenserRow.dispenserRequested! * dispenserRow.unitPriceCents!;
    }

    return _SessionData(
      billedCents: billedCents,
      isPartial: isPartial,
      dispenserRequested: dispenserRow?.dispenserRequested,
      dispenserActual: dispenserRow?.dispenserActual,
      originalTotalCents: originalTotalCents,
    );
  }

  void _startAutoNav() {
    _countdownTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (mounted) {
        setState(() {
          _secondsRemaining--;
        });
      }
    });

    _autoLoopTimer = Timer(AppConfig.receiptAutoReturnDelay, () {
      if (mounted) {
        _endSessionAndReturnToIdle();
      }
    });
  }

  /// Ends the session and returns the terminal to idle.
  ///
  /// Checkout completion is one of the three session ends (ADR-0027), and
  /// [SessionController.endSession] can refuse one while a critical operation
  /// is in flight (rule 7) — navigating anyway would drop us on `/idle` with a
  /// member still selected, which the router immediately bounces to
  /// `/products`, resuming a session that was supposed to be over.
  void _endSessionAndReturnToIdle() {
    if (!context.read<SessionController>().endSession()) return;
    _cancelTimers();
    context.go('/idle');
  }

  /// Sends the member back to the product list with their session intact
  /// (ADR-0027 rule 10).
  ///
  /// The purchase is booked and the cart already empty, so this is a fresh
  /// round of shopping — not a session end, hence no [SessionController]
  /// teardown. `recordActivity()` restarts the inactivity timer, so the
  /// resumed session is governed by rule 6 again rather than by whatever was
  /// left running.
  void _continueShopping() {
    _cancelTimers();
    context.read<SessionController>().recordActivity();
    context.go('/products');
  }

  void _cancelTimers() {
    _autoLoopTimer?.cancel();
    _countdownTimer?.cancel();
  }

  @override
  void dispose() {
    _cancelTimers();
    _scaleController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final locale = _locale;

    return FutureBuilder<_SessionData>(
      future: _sessionDataFuture,
      builder: (context, snapshot) {
        if (snapshot.hasError) {
          // The purchase already succeeded — only the receipt details are
          // missing. Say so and let the member dismiss it themselves (#16);
          // bouncing to idle leaves them unsure whether they were charged.
          return _buildFallbackReceipt(l10n);
        }
        if (!snapshot.hasData) {
          return const Center(child: CircularProgressIndicator());
        }
        final data = snapshot.data!;

        // Trigger auto-nav on first load for non-partial sessions
        if (!_autoNavStarted && !data.isPartial) {
          _autoNavStarted = true;
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (mounted) _startAutoNav();
          });
        }

        return _receiptFrame(
          children: [
            // Icon: partial dispense gets warning icon
            ..._receiptHeader(
              icon: data.isPartial
                  ? Icons.warning_amber_rounded
                  : Icons.check_circle,
              iconColor: data.isPartial
                  ? hexToColor(AppColors.semanticWarning)
                  : hexToColor(AppColors.semanticSuccess),
              title: data.isPartial
                  ? l10n.checkoutPartialSuccess(data.dispenserActual ?? 0)
                  : l10n.checkoutSuccess,
            ),

            // For partial dispense: show crossed-out original amount above billed amount
            if (data.isPartial && data.originalTotalCents != null) ...[
              SizedBox(
                width: double.infinity,
                child: Text(
                  formatPrice(data.originalTotalCents!, locale),
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: hexToColor(AppColors.semanticInfo),
                    fontSize: AppFontSizes.xl,
                    fontWeight: FontWeight.w700,
                    decoration: TextDecoration.lineThrough,
                  ),
                ),
              ),
              const SizedBox(height: AppSpacing.xs),
            ],

            // Actual billed amount
            PriceDisplay(
              priceCents: data.billedCents,
              locale: locale,
              fontSize: PriceFontSize.large,
              fullWidth: true,
            ),
            const SizedBox(height: AppSpacing.lg),

            // The balance the purchase was booked against
            ..._receiptFooter(l10n),

            // Partial dispense: show confirm button; normal: countdown + actions
            if (data.isPartial) ...[
              ElevatedButton(
                onPressed: _endSessionAndReturnToIdle,
                child: Text(l10n.checkoutPartialConfirm),
              ),
            ] else ...[
              Text(
                l10n.redirectingIn(_secondsRemaining),
                style: TextStyle(
                  color: hexToColor(AppColors.textSecondary),
                  fontSize: AppFontSizes.base,
                ),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: AppSpacing.lg),
              _doneButton(l10n),
              const SizedBox(height: AppSpacing.sm),
              // For the member who is not done yet — buying a second round
              // should not cost them a card scan.
              TextButton(
                onPressed: _continueShopping,
                child: Text(
                  l10n.continueShopping,
                  style: TextStyle(
                    color: hexToColor(AppColors.textSecondary),
                    fontSize: AppFontSizes.lg,
                  ),
                ),
              ),
            ],
          ],
        );
      },
    );
  }

  /// The receipt shown when the session details cannot be loaded (#16).
  ///
  /// The money already moved, so this stays a receipt — success wording plus
  /// the amount the checkout billed — with a note about what is missing and
  /// no auto-navigation: the member decides when to leave. The terminal is
  /// not pinned by that; [SessionController]'s inactivity timer is running
  /// again by now (the cart screen ends its critical operation before it
  /// navigates here), so a walked-away session still times out per ADR-0027.
  Widget _buildFallbackReceipt(AppLocalizations l10n) {
    return _receiptFrame(
      children: [
        // The booking itself did go through — lead with that.
        ..._receiptHeader(
          icon: Icons.check_circle,
          iconColor: hexToColor(AppColors.semanticSuccess),
          title: l10n.checkoutSuccess,
        ),

        // Billed amount, absent only for a zero-total checkout
        if (_lastBilledCents > 0) ...[
          PriceDisplay(
            priceCents: _lastBilledCents,
            locale: _locale,
            fontSize: PriceFontSize.large,
            fullWidth: true,
          ),
          const SizedBox(height: AppSpacing.lg),
        ],

        // What is missing, so the thinner receipt is not a mystery
        Text(
          l10n.checkoutReceiptUnavailable,
          style: TextStyle(
            // Secondary, not muted: this explains why the receipt is thin, so
            // it is text the member has to be able to read (#41).
            color: hexToColor(AppColors.textSecondary),
            fontSize: AppFontSizes.base,
          ),
          textAlign: TextAlign.center,
        ),
        const SizedBox(height: AppSpacing.lg),

        // The balance the purchase was booked against
        ..._receiptFooter(l10n),

        // No countdown here: the unexplained bounce to idle is the bug (#16).
        // No "continue shopping" either — this branch means we could not read
        // the receipt back, which is the wrong moment to invite more spending.
        _doneButton(l10n),
      ],
    );
  }

  /// Icon, headline and member name — the top of every receipt variant.
  List<Widget> _receiptHeader({
    required IconData icon,
    required Color iconColor,
    required String title,
  }) {
    return [
      Icon(icon, size: 48, color: iconColor),
      const SizedBox(height: AppSpacing.lg),
      Text(
        title,
        style: TextStyle(
          color: hexToColor(AppColors.textPrimary),
          fontSize: AppFontSizes.xxxl,
          fontWeight: FontWeight.w700,
        ),
        textAlign: TextAlign.center,
      ),
      const SizedBox(height: AppSpacing.lg),
      Text(
        _memberName,
        style: TextStyle(
          color: hexToColor(AppColors.textSecondary),
          fontSize: AppFontSizes.lg,
        ),
        textAlign: TextAlign.center,
      ),
      const SizedBox(height: AppSpacing.lg),
    ];
  }

  /// The resulting balance — the bottom of every receipt.
  ///
  /// No session reference here (#25): a raw UUID means nothing to the member
  /// it is shown to, and the transaction is looked up from the local database
  /// or the backend when staff actually need it.
  List<Widget> _receiptFooter(AppLocalizations l10n) {
    return [
      Text(
        formatNewBalance(_billedToBalanceCents, l10n, _locale),
        style: TextStyle(
          color: balanceColor(_billedToBalanceCents),
          fontSize: AppFontSizes.lg,
        ),
        textAlign: TextAlign.center,
      ),
      const SizedBox(height: AppSpacing.lg),
    ];
  }

  /// The scale-in card every receipt variant is painted into.
  Widget _receiptFrame({required List<Widget> children}) {
    return Center(
      child: ScaleTransition(
        scale: _scaleAnimation,
        child: Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.lg,
            vertical: AppSpacing.xl,
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: children,
          ),
        ),
      ),
    );
  }

  /// Dismisses the receipt.
  ///
  /// Deliberately *not* the danger colour (#25): this ends a session that
  /// already succeeded, and a red button on a success screen reads as "cancel
  /// my purchase" — members avoided it and sat out the countdown instead.
  Widget _doneButton(AppLocalizations l10n) {
    return ElevatedButton(
      onPressed: _endSessionAndReturnToIdle,
      style: ElevatedButton.styleFrom(
        // Strong blue: white on #3b82f6 is 3.7:1 (#41).
        backgroundColor: hexToColor(AppColors.semanticPrimaryStrong),
        foregroundColor: Colors.white,
        padding: const EdgeInsets.symmetric(horizontal: 29, vertical: 17),
        textStyle: TextStyle(
          fontSize: AppFontSizes.lg * 1.2,
          fontWeight: FontWeight.w600,
        ),
      ),
      child: Text(l10n.checkoutDone),
    );
  }
}
