import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'dart:async';
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
  int _secondsRemaining = 30;
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

    _autoLoopTimer = Timer(const Duration(seconds: 30), () {
      if (mounted) {
        _performNavigation();
      }
    });
  }

  void _performNavigation() {
    // Checkout completion is one of the three session ends (ADR-0027).
    context.read<SessionController>().endSession();
    context.go('/idle');
  }

  @override
  void dispose() {
    _autoLoopTimer?.cancel();
    _countdownTimer?.cancel();
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

            // Session reference ID, then the balance it was booked against
            ..._receiptFooter(l10n),

            // Partial dispense: show confirm button; normal: show countdown + logout
            if (data.isPartial) ...[
              ElevatedButton(
                onPressed: _performNavigation,
                child: Text(l10n.checkoutPartialConfirm),
              ),
            ] else ...[
              Text(
                l10n.redirectingIn(_secondsRemaining),
                style: TextStyle(
                  color: hexToColor(AppColors.textMuted),
                  fontSize: AppFontSizes.base,
                ),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: AppSpacing.lg),
              _logoutButton(l10n),
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
            color: hexToColor(AppColors.textMuted),
            fontSize: AppFontSizes.base,
          ),
          textAlign: TextAlign.center,
        ),
        const SizedBox(height: AppSpacing.lg),

        // Session reference ID — what the bar staff need to look it up
        ..._receiptFooter(l10n),

        // No countdown here: the unexplained bounce to idle is the bug (#16).
        _logoutButton(l10n),
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

  /// Session reference and resulting balance — the bottom of every receipt.
  List<Widget> _receiptFooter(AppLocalizations l10n) {
    return [
      Text(
        widget.sessionId,
        style: TextStyle(
          color: hexToColor(AppColors.textMuted),
          fontSize: AppFontSizes.sm,
          fontFamily: 'monospace',
        ),
        textAlign: TextAlign.center,
      ),
      const SizedBox(height: AppSpacing.lg),
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

  Widget _logoutButton(AppLocalizations l10n) {
    return ElevatedButton(
      onPressed: _performNavigation,
      style: ElevatedButton.styleFrom(
        backgroundColor: const Color(0xffDC2626),
        foregroundColor: Colors.white,
        padding: const EdgeInsets.symmetric(horizontal: 29, vertical: 17),
        textStyle: TextStyle(
          fontSize: AppFontSizes.lg * 1.2,
          fontWeight: FontWeight.w600,
        ),
      ),
      child: Text(l10n.logout),
    );
  }
}
