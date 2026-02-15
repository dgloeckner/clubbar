import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'dart:async';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';
import 'package:ruderbar_terminal/models/partial_dispense_info.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:ruderbar_terminal/utils/formatters.dart';
import 'package:ruderbar_terminal/widgets/styled_components/price_display.dart';

class CheckoutConfirmationScreen extends StatefulWidget {
  final String transactionId;
  

  const CheckoutConfirmationScreen({
    required this.transactionId,
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
  int _secondsRemaining = 3;
  late AnimationController _scaleController;
  late Animation<double> _scaleAnimation;

  @override
  void initState() {
    super.initState();

    // Initialize scale-in animation
    _scaleController = AnimationController(
      duration: const Duration(milliseconds: 300),
      vsync: this,
    );

    _scaleAnimation = Tween<double>(begin: 0.8, end: 1.0).animate(
      CurvedAnimation(parent: _scaleController, curve: Curves.easeOut),
    );

    _scaleController.forward();

    // Start countdown timer
    _countdownTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (mounted) {
        setState(() {
          _secondsRemaining--;
        });
      }
    });

    // Auto-navigate back to idle after 3 seconds
    _autoLoopTimer = Timer(const Duration(seconds: 3), () {
      if (mounted) {
        _performNavigation();
      }
    });
  }

  void _performNavigation() {
    context.read<CartProvider>().clearCart();
    context.read<MembersProvider>().clearSelectedMember();
    context.go('/idle');
  }

  @override
  void dispose() {
    _autoLoopTimer?.cancel();
    _countdownTimer?.cancel();
    _scaleController.dispose();
    super.dispose();
  }

  String _getBalanceDisplayText(int balanceCents, AppLocalizations l10n, String locale) {
    return l10n.checkoutNewBalance(formatPrice(balanceCents, locale));
  }

  Color _getBalanceColor(int balanceCents) {
    if (balanceCents > 0) {
      return hexToColor(AppColors.semanticSuccess); // Green
    } else if (balanceCents < 0) {
      return hexToColor(AppColors.semanticWarning); // Orange
    }
    return hexToColor(AppColors.textMuted); // Gray for zero
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final membersProvider = context.watch<MembersProvider>();
    final selectedMember = membersProvider.selectedMember;
    final locale = selectedMember?.preferredLanguage ?? 'de';
    final cartTotal = context.watch<CartProvider>().total;
    final memberName =
        selectedMember != null ? '${selectedMember.firstName} ${selectedMember.lastName}' : 'Member';

    // Deckel already includes the transaction (refreshed after checkout)
    final newBalance = membersProvider.memberDeckel ?? 0;

    // Check if this is a partial dispense
    final partialDispenseInfo = context.watch<CartProvider>().lastPartialDispenseInfo;
    final isPartial = partialDispenseInfo?.isPartial ?? false;

    // Body content only - MainLayout provides Scaffold and header
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
              children: [
                // Icon (warning for partial, checkmark for complete)
                Icon(
                  isPartial ? Icons.warning : Icons.check_circle,
                  size: 48,
                  color: isPartial
                      ? hexToColor(AppColors.semanticWarning)
                      : hexToColor(AppColors.semanticSuccess),
                ),
                const SizedBox(height: AppSpacing.lg),

                // Title (partial vs complete)
                Text(
                  isPartial
                      ? l10n.checkoutPartialSuccess(
                          partialDispenseInfo!.actualDispensed)
                      : l10n.checkoutSuccess,
                  style: TextStyle(
                    color: hexToColor(AppColors.textPrimary),
                    fontSize: AppFontSizes.xxxl,
                    fontWeight: FontWeight.w700,
                  ),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: AppSpacing.lg),

                // Partial dispense explanation message
                if (isPartial) ...[
                  Text(
                    l10n.checkoutPartialMessage(
                        partialDispenseInfo!.actualDispensed),
                    style: TextStyle(
                      color: hexToColor(AppColors.textMuted),
                      fontSize: AppFontSizes.base,
                    ),
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: AppSpacing.lg),
                ],

                // Member name
                Text(
                  memberName,
                  style: TextStyle(
                    color: hexToColor(AppColors.textSecondary),
                    fontSize: AppFontSizes.lg,
                  ),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: AppSpacing.lg),

                // Total amount using PriceDisplay
                PriceDisplay(
                  priceCents: cartTotal,
                  locale: locale,
                  fontSize: PriceFontSize.large,
                  fullWidth: true,
                ),
                // Show crossed-out original amount for partial dispense
                if (isPartial) ...[
                  const SizedBox(height: AppSpacing.sm),
                  Text(
                    l10n.checkoutOriginalTotal(
                      formatPrice(partialDispenseInfo!.originalTotalCents, locale),
                    ),
                    style: TextStyle(
                      color: hexToColor(AppColors.textMuted),
                      fontSize: AppFontSizes.base,
                      decoration: TextDecoration.lineThrough,
                    ),
                    textAlign: TextAlign.center,
                  ),
                ],
                const SizedBox(height: AppSpacing.lg),

                // Transaction reference ID
                Text(
                  widget.transactionId,
                  style: TextStyle(
                    color: hexToColor(AppColors.textMuted),
                    fontSize: AppFontSizes.sm,
                    fontFamily: 'monospace',
                  ),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: AppSpacing.lg),

                // Balance after transaction (color-coded)
                Text(
                  _getBalanceDisplayText(newBalance, l10n, locale),
                  style: TextStyle(
                    color: _getBalanceColor(newBalance),
                    fontSize: AppFontSizes.base,
                  ),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: AppSpacing.lg),

                // Auto-dismiss countdown message
                Text(
                  l10n.redirectingIn(_secondsRemaining),
                  style: TextStyle(
                    color: hexToColor(AppColors.textMuted),
                    fontSize: AppFontSizes.base,
                  ),
                  textAlign: TextAlign.center,
                ),
              ],
            ),
          ),
        ),
      );
  }
}
