import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'dart:async';
import 'package:clubbar_terminal/config/app_config.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/models/receipt_line.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';
import 'package:clubbar_terminal/utils/icon_registry.dart';

/// What a session bought, read back from the rows the checkout wrote.
class _Receipt {
  final List<ReceiptLine> lines;
  final int billedCents;

  /// Fewer tokens came out than were asked for.
  final bool isPartial;
  final int? dispensedCount;

  /// What the round would have cost had every token come out — only on a
  /// partial dispense, where it is shown struck through beside the bill.
  final int? originalTotalCents;

  const _Receipt({
    required this.lines,
    required this.billedCents,
    required this.isPartial,
    this.dispensedCount,
    this.originalTotalCents,
  });
}

/// The post-checkout receipt (ADR-0027 rule 10; UC-T01 step 12).
///
/// A receipt, not a dialog: it has **no buttons**. Members were unsure what
/// the buttons on the old screen would do to a purchase that had already gone
/// through ("Fertig" — finish *what*?), so the screen now only states what
/// was booked and what the tab stands at, and leaves on its own. Everything
/// a button used to do has a quieter route:
///
/// - **Leaving** happens by itself after [AppConfig.receiptAutoReturnDelay];
///   the thin bar under the balance drains to show it is coming. A tap
///   anywhere leaves at once — the ordinary kiosk gesture, and what the
///   queue needs from a member who has read enough.
/// - **A second round** is a card scan: on this screen any valid card starts
///   that member's session, the same member's included (rule 9). That is how
///   the member got here the first time, so it needs no explaining.
///
/// A receipt that needs *reading* rather than glancing at — a partial dispense,
/// or one whose details could not be read back (#16) — stays for
/// [AppConfig.receiptAttentionDwell] instead. Time is the one thing a screen
/// without buttons can give.
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

/// Hero sizes for the receipt, read standing up from across a bar counter.
///
/// Fixed rather than token-driven, like the idle headline and the cart's grand
/// total (docs/font-sizes.md): they are the receipt's visual identity, and the
/// three of them are sized against each other, not against the type scale.
const double _receiptTitleSize = 40.0;
const double _receiptTotalSize = 34.0;
const double _receiptBalanceSize = 48.0;

class _CheckoutConfirmationScreenState extends State<CheckoutConfirmationScreen>
    with SingleTickerProviderStateMixin {
  Timer? _autoReturnTimer;
  Timer? _tickTimer;
  Duration _dwell = AppConfig.receiptAutoReturnDelay;
  int _secondsRemaining = AppConfig.receiptAutoReturnDelay.inSeconds;
  late AnimationController _scaleController;
  late Animation<double> _scaleAnimation;
  late Future<_Receipt> _receiptFuture;
  bool _dwellStarted = false;

  /// Whom the receipt was issued to, captured once at mount.
  ///
  /// A receipt is a finished transaction, so it must not follow live session
  /// state: a card scan on this screen ends the shown session and starts the
  /// next member's (ADR-0027 rule 9), and a receipt still watching
  /// [MembersProvider] would repaint with a cleared or foreign identity while
  /// it fades out. The balance is already final here — the cart screen awaits
  /// `refreshDeckel()` before navigating.
  late final String _memberName;
  late final int _balanceCents;
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
    _balanceCents = context.read<MembersProvider>().memberDeckel ?? 0;
    _lastBilledCents = context.read<CartProvider>().lastCheckoutTotalCents;

    _scaleController = AnimationController(
      duration: const Duration(milliseconds: 300),
      vsync: this,
    );
    _scaleAnimation = Tween<double>(begin: 0.8, end: 1.0).animate(
      CurvedAnimation(parent: _scaleController, curve: Curves.easeOut),
    );
    _scaleController.forward();

    _receiptFuture = _loadReceipt();
  }

  Future<_Receipt> _loadReceipt() async {
    final repo = context.read<TransactionsRepository>();
    final billedCents = await repo.getSessionTotal(widget.sessionId);
    final lines = await repo.getSessionLines(widget.sessionId);

    final partial = lines.where((l) => l.isPartial).firstOrNull;
    return _Receipt(
      lines: lines,
      billedCents: billedCents,
      isPartial: partial != null,
      dispensedCount: partial?.quantity,
      // Every other line was billed in full, so only the short line's
      // shortfall separates the two totals.
      originalTotalCents: partial == null
          ? null
          : billedCents +
              (partial.requestedQuantity! - partial.quantity) *
                  partial.unitPriceCents,
    );
  }

  /// Starts the receipt's clock: a one-second tick for the drain bar, and the
  /// return to idle when [dwell] is up.
  ///
  /// Called once, from the first frame that knows which receipt this is —
  /// the dwell depends on that.
  void _startDwell(Duration dwell) {
    if (_dwellStarted) return;
    _dwellStarted = true;
    _dwell = dwell;
    _secondsRemaining = dwell.inSeconds;

    _tickTimer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      setState(() => _secondsRemaining = (_secondsRemaining - 1).clamp(0, 999));
    });
    _autoReturnTimer = Timer(dwell, () {
      if (mounted) _endSessionAndReturnToIdle();
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

  void _cancelTimers() {
    _autoReturnTimer?.cancel();
    _tickTimer?.cancel();
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

    return FutureBuilder<_Receipt>(
      future: _receiptFuture,
      builder: (context, snapshot) {
        if (snapshot.hasError) {
          // The purchase already succeeded — only the receipt details are
          // missing. Say so, and give it the longer dwell (#16): an
          // unexplained bounce to idle leaves the member unsure whether they
          // were charged.
          _scheduleDwell(AppConfig.receiptAttentionDwell);
          return _buildFallbackReceipt(l10n);
        }
        if (!snapshot.hasData) {
          return const Center(child: CircularProgressIndicator());
        }
        final receipt = snapshot.data!;
        _scheduleDwell(receipt.isPartial
            ? AppConfig.receiptAttentionDwell
            : AppConfig.receiptAutoReturnDelay);

        return _receiptFrame(
          children: [
            ..._receiptHeader(
              icon: receipt.isPartial
                  ? Icons.warning_amber_rounded
                  : Icons.check_circle,
              iconColor: receipt.isPartial
                  ? AppColors.semanticWarning
                  : AppColors.semanticSuccess,
              title: receipt.isPartial
                  ? l10n.checkoutPartialSuccess(receipt.dispensedCount ?? 0)
                  : l10n.checkoutSuccess,
            ),

            // What was booked — the lines the member put in the cart, so the
            // receipt reads like the cart they just confirmed.
            for (final line in receipt.lines) _lineRow(line),
            _totalRow(
              l10n,
              billedCents: receipt.billedCents,
              originalTotalCents: receipt.originalTotalCents,
            ),
            const SizedBox(height: AppSpacing.xxl),

            ..._balanceBlock(l10n),
          ],
        );
      },
    );
  }

  /// Starts the dwell after this frame — the builder must not touch state.
  void _scheduleDwell(Duration dwell) {
    if (_dwellStarted) return;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _startDwell(dwell);
    });
  }

  /// The receipt shown when the session details cannot be loaded (#16).
  ///
  /// The money already moved, so this stays a receipt — success wording plus
  /// the amount the checkout billed — with a note about what is missing.
  Widget _buildFallbackReceipt(AppLocalizations l10n) {
    return _receiptFrame(
      children: [
        // The booking itself did go through — lead with that.
        ..._receiptHeader(
          icon: Icons.check_circle,
          iconColor: AppColors.semanticSuccess,
          title: l10n.checkoutSuccess,
        ),

        // What is missing, so the thinner receipt is not a mystery
        Text(
          l10n.checkoutReceiptUnavailable,
          style: TextStyle(
            // Secondary, not muted: this explains why the receipt is thin, so
            // it is text the member has to be able to read (#41).
            color: AppColors.textSecondary,
            fontSize: AppFontSizes.xl,
          ),
          textAlign: TextAlign.center,
        ),
        const SizedBox(height: AppSpacing.lg),

        // Billed amount, absent only for a zero-total checkout
        if (_lastBilledCents > 0) _totalRow(l10n, billedCents: _lastBilledCents),
        const SizedBox(height: AppSpacing.xxl),

        ..._balanceBlock(l10n),
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
      Icon(icon, size: 64, color: iconColor),
      const SizedBox(height: AppSpacing.md),
      Text(
        title,
        style: const TextStyle(
          color: AppColors.textPrimary,
          fontSize: _receiptTitleSize,
          fontWeight: FontWeight.w700,
        ),
        textAlign: TextAlign.center,
      ),
      const SizedBox(height: AppSpacing.xs),
      Text(
        _memberName,
        style: TextStyle(
          color: AppColors.textSecondary,
          fontSize: AppFontSizes.xl,
        ),
        textAlign: TextAlign.center,
      ),
      const SizedBox(height: AppSpacing.xxl),
    ];
  }

  /// One booked line: icon, "2 ×", name, what it came to.
  Widget _lineRow(ReceiptLine line) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.md),
      child: Row(
        children: [
          getProductIcon(line.iconName, size: 44),
          const SizedBox(width: AppSpacing.lg),
          SizedBox(
            width: 52,
            child: Text(
              '${line.quantity} ×',
              textAlign: TextAlign.right,
              style: TextStyle(
                color: AppColors.textSecondary,
                fontSize: AppFontSizes.xxl,
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
            ),
          ),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Text(
              line.name(_locale),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: AppColors.textPrimary,
                fontSize: AppFontSizes.xxl,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
          const SizedBox(width: AppSpacing.lg),
          Text(
            formatPrice(line.totalCents, _locale),
            style: TextStyle(
              color: AppColors.textPrimary,
              fontSize: AppFontSizes.xxl,
              fontFeatures: const [FontFeature.tabularFigures()],
            ),
          ),
        ],
      ),
    );
  }

  /// The bill: a rule, "Gesamt", the amount — and on a partial dispense the
  /// amount the round would have cost, struck through beside it.
  Widget _totalRow(
    AppLocalizations l10n, {
    required int billedCents,
    int? originalTotalCents,
  }) {
    return Container(
      margin: const EdgeInsets.only(top: AppSpacing.md),
      padding: const EdgeInsets.only(top: AppSpacing.lg),
      decoration: const BoxDecoration(
        border: Border(top: BorderSide(color: AppColors.borderLight)),
      ),
      child: Row(
        children: [
          Expanded(
            child: Text(
              l10n.cartTotal,
              style: TextStyle(
                color: AppColors.textSecondary,
                fontSize: AppFontSizes.xxl,
              ),
            ),
          ),
          if (originalTotalCents != null) ...[
            Text(
              formatPrice(originalTotalCents, _locale),
              style: TextStyle(
                color: AppColors.textSecondary,
                fontSize: AppFontSizes.xxl,
                decoration: TextDecoration.lineThrough,
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
            ),
            const SizedBox(width: AppSpacing.md),
          ],
          Text(
            formatPrice(billedCents, _locale),
            key: const Key('receipt-total'),
            style: const TextStyle(
              color: AppColors.semanticInfo,
              fontSize: _receiptTotalSize,
              fontWeight: FontWeight.w700,
              fontFeatures: [FontFeature.tabularFigures()],
            ),
          ),
        ],
      ),
    );
  }

  /// The tab as it stands now — the number the member walks away with —
  /// and, under it, the bar that drains while the receipt is on screen.
  ///
  /// No session reference here (#25): a raw UUID means nothing to the member
  /// it is shown to, and the transaction is looked up from the local database
  /// or the backend when staff actually need it.
  List<Widget> _balanceBlock(AppLocalizations l10n) {
    return [
      Text(
        l10n.receiptBalanceLabel,
        style: TextStyle(
          color: AppColors.textSecondary,
          fontSize: AppFontSizes.xl,
        ),
        textAlign: TextAlign.center,
      ),
      const SizedBox(height: AppSpacing.xs),
      Text(
        formatBalance(_balanceCents, l10n, _locale),
        key: const Key('receipt-balance'),
        style: TextStyle(
          color: balanceColor(_balanceCents),
          fontSize: _receiptBalanceSize,
          fontWeight: FontWeight.w700,
        ),
        textAlign: TextAlign.center,
      ),
      const SizedBox(height: AppSpacing.xxl),
      _DwellBar(
        fraction: _dwell.inSeconds == 0 ? 0 : _secondsRemaining / _dwell.inSeconds,
      ),
    ];
  }

  /// The scale-in card every receipt variant is painted into.
  ///
  /// The whole body takes the dismissing tap, not a control inside it: there
  /// is nothing to find and nothing to aim for.
  Widget _receiptFrame({required List<Widget> children}) {
    return GestureDetector(
      key: const Key('receipt'),
      behavior: HitTestBehavior.opaque,
      onTap: _endSessionAndReturnToIdle,
      child: Center(
        child: ScaleTransition(
          scale: _scaleAnimation,
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.lg,
              vertical: AppSpacing.xl,
            ),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 720),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: children,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// The receipt's clock: a thin bar that empties over the dwell.
///
/// Says "this leaves on its own" without a number to read — the countdown
/// text it replaces was a thing to keep checking on a screen that should need
/// no attention. Each second's step is eased over less than a second, so the
/// bar is never mid-animation when the next tick lands.
class _DwellBar extends StatelessWidget {
  const _DwellBar({required this.fraction});

  /// Share of the dwell still to run, 1 → 0.
  final double fraction;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      key: const Key('receipt-dwell'),
      value: fraction.toStringAsFixed(2),
      child: SizedBox(
        width: 240,
        height: 5,
        child: ClipRRect(
          borderRadius: BorderRadius.circular(AppBorderRadius.full),
          child: ColoredBox(
            color: AppColors.borderLight,
            child: TweenAnimationBuilder<double>(
              tween: Tween(end: fraction.clamp(0.0, 1.0)),
              duration: const Duration(milliseconds: 600),
              curve: Curves.easeOut,
              builder: (context, value, _) => Align(
                alignment: Alignment.centerLeft,
                child: FractionallySizedBox(
                  widthFactor: value,
                  child: const ColoredBox(color: AppColors.textSecondary),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
