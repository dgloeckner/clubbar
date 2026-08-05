import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'dart:async';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
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
    final memberName = selectedMember != null
        ? '${selectedMember.firstName} ${selectedMember.lastName}'
        : 'Member';
    final newBalance = membersProvider.memberDeckel ?? 0;

    return FutureBuilder<_SessionData>(
      future: _sessionDataFuture,
      builder: (context, snapshot) {
        if (snapshot.hasError) {
          if (!_autoNavStarted) {
            _autoNavStarted = true;
            WidgetsBinding.instance.addPostFrameCallback((_) {
              if (mounted) _performNavigation();
            });
          }
          return const Center(child: CircularProgressIndicator());
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
                  // Icon: partial dispense gets warning icon
                  Icon(
                    data.isPartial ? Icons.warning_amber_rounded : Icons.check_circle,
                    size: 48,
                    color: data.isPartial
                        ? hexToColor(AppColors.semanticWarning)
                        : hexToColor(AppColors.semanticSuccess),
                  ),
                  const SizedBox(height: AppSpacing.lg),

                  // Title
                  Text(
                    data.isPartial
                        ? l10n.checkoutPartialSuccess(data.dispenserActual ?? 0)
                        : l10n.checkoutSuccess,
                    style: TextStyle(
                      color: hexToColor(AppColors.textPrimary),
                      fontSize: AppFontSizes.xxxl,
                      fontWeight: FontWeight.w700,
                    ),
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: AppSpacing.lg),

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

                  // Session reference ID
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

                  // Balance
                  Text(
                    _getBalanceDisplayText(newBalance, l10n, locale),
                    style: TextStyle(
                      color: _getBalanceColor(newBalance),
                      fontSize: AppFontSizes.lg,
                    ),
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: AppSpacing.lg),

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
                    ElevatedButton(
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
                    ),
                  ],
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}
