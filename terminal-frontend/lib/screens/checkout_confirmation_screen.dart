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
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/utils/deckel_sendoff.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';
import 'package:clubbar_terminal/utils/icon_registry.dart';
import 'package:clubbar_terminal/utils/tally.dart';
import 'package:clubbar_terminal/widgets/beer_mat.dart';
import 'package:clubbar_terminal/widgets/counting_amount.dart';

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
/// Multiples of the configured scale rather than fixed numbers, so a terminal
/// that raises `fontSizes` in its config.json (a production terminal runs
/// `xxxl` at 31, not the compiled-in 26) keeps the same hierarchy: the title a
/// step above the lines, the total a clear step above them, the balance — the
/// number the member walks away with — above everything. On the production
/// scale these come to 40, 36 and 48 (docs/font-sizes.md).
double get _receiptTitleSize => AppFontSizes.xxxl * 1.3;
double get _receiptTotalSize => AppFontSizes.xxl * 1.35;
double get _receiptBalanceSize => AppFontSizes.xxxl * 1.55;

/// The coaster on the receipt, and the smaller one a long round leaves room
/// for. Fixed pixels rather than a multiple of the font scale: this is an
/// object on the screen, not type, and it has to stay clear of the lines
/// beside it at every configured scale.
const double _matSize = 260;
const double _matSizeCompact = 200;

/// How wide the two-column receipt may grow. The single-column variants —
/// the partial dispense and the #16 fallback — keep the 720 they had.
const double _wideReceiptWidth = 980;
const double _receiptWidth = 720;

/// Above this many lines the rows tighten up, so a table's round still fits a
/// 1280×800 terminal at the production scale without scrolling — a receipt a
/// member has to scroll is one they do not read.
const int _compactAbove = 4;

/// How long the receipt card takes to scale in — and therefore how long the
/// balance waits before it starts counting (#921).
const Duration _receiptScaleIn = Duration(milliseconds: 300);

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

  /// What the send-off calls them (#929). A receipt greets a person by their
  /// first name, the way the bar does.
  late final String _memberFirstName;
  late final int _balanceCents;
  late final String _locale;

  /// The club's name, printed around the coaster's rim (ADR-0034).
  ///
  /// Snapshotted with the rest: a `/sync/config` poll must not repaint a
  /// finished receipt.
  late final String _clubName;

  /// The tab from which *this* member is warned, captured with the rest.
  ///
  /// A band, not a colour: what the colour is, is decided once in
  /// [balanceColor], and a receipt that pre-computed one would be a call site
  /// making that decision for itself (ADR-0042 scope). `null` means no ceiling
  /// is enforced for them, so the amount is never amber.
  ///
  /// Snapshotted for the same reason the identity is: a one-time `read`, never
  /// a `watch`, so neither the next card scan nor a `/sync/config` poll can
  /// repaint a finished receipt. A member the session no longer knows falls
  /// back to the club default, as `_locale` falls back to German.
  late final int? _warnAtCents;

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
    _memberFirstName = (selectedMember?.firstName ?? '').trim();
    _clubName = context.read<ConfigService>().displayName;
    _locale = selectedMember?.preferredLanguage ?? 'de';
    _balanceCents = context.read<MembersProvider>().memberDeckel ?? 0;
    _warnAtCents = context
        .read<ConfigService>()
        .creditLimitPolicy
        .warnAtCentsFor(selectedMember?.creditLimitCents);
    _lastBilledCents = context.read<CartProvider>().lastCheckoutTotalCents;

    _scaleController = AnimationController(
      duration: _receiptScaleIn,
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

        final compact = receipt.lines.length > _compactAbove;

        // What was booked — the lines the member put in the cart, so the
        // receipt reads like the cart they just confirmed.
        final booked = <Widget>[
          for (final line in receipt.lines) _lineRow(line, compact: compact),
          _totalRow(
            l10n,
            billedCents: receipt.billedCents,
            originalTotalCents: receipt.originalTotalCents,
          ),
        ];

        if (receipt.isPartial) {
          // An attention receipt keeps the warning triangle it has always
          // had, and stays a single column. The mat is a send-off, and a
          // short dispense is not the moment for one.
          return _receiptFrame(
            children: [
              ..._receiptHeader(
                icon: Icons.warning_amber_rounded,
                iconColor: AppColors.semanticWarning,
                title: l10n.checkoutPartialSuccess(receipt.dispensedCount ?? 0),
                compact: compact,
              ),
              ...booked,
              SizedBox(height: compact ? AppSpacing.lg : AppSpacing.xxl),
              ..._balanceBlock(
                l10n,
                compact: compact,
                billedCents: receipt.billedCents,
              ),
            ],
          );
        }

        return _receiptFrame(
          maxWidth: _wideReceiptWidth,
          children: [
            // The send-off, by name — what a bartender says, in place of
            // "Buchung erfolgreich!" (#929).
            Text(
              _sendOffText(l10n, receipt.lines),
              key: const Key('receipt-sendoff'),
              style: TextStyle(
                color: AppColors.textPrimary,
                fontSize: _receiptTitleSize,
                fontWeight: FontWeight.w700,
              ),
              textAlign: TextAlign.center,
            ),
            SizedBox(height: compact ? AppSpacing.lg : AppSpacing.xxl),

            // The Deckel on the left, tonight's lines on the right — the
            // arrangement the 1280×800 panel has the width for, and the
            // reason a round of five still needs no scrolling.
            Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                _mat(l10n, receipt.lines, compact: compact),
                const SizedBox(width: AppSpacing.xxxl),
                Expanded(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: booked,
                  ),
                ),
              ],
            ),
            SizedBox(height: compact ? AppSpacing.lg : AppSpacing.xxl),

            ..._balanceBlock(
              l10n,
              compact: compact,
              billedCents: receipt.billedCents,
            ),
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

  /// The Deckel itself: the club's coaster with tonight's items pencilled on.
  ///
  /// **Strokes are items, not lines** — [tallyStrokeCount] sums the
  /// quantities, so "2 × Helles" is two strokes — and it stops at
  /// [kTallyMaxStrokes]. They say nothing about the month; the euro figure
  /// beside them is the only figure for the tab.
  Widget _mat(
    AppLocalizations l10n,
    List<ReceiptLine> lines, {
    required bool compact,
  }) {
    final strokes = tallyStrokeCount(lines.map((l) => l.quantity));
    return Semantics(
      key: const Key('receipt-mat'),
      image: true,
      label: l10n.receiptMatLabel(strokes),
      child: ExcludeSemantics(
        child: BeerMat(
          size: compact ? _matSizeCompact : _matSize,
          strokes: strokes,
          rimText: _clubName,
        ),
      ),
    );
  }

  /// How the receipt says goodbye, by name.
  ///
  /// Derived from the icon family the Getränkewart already picked
  /// ([sendOffFor]); no new field, no lookup, and a wrong guess costs
  /// nothing. A member record without a first name falls back to the full
  /// name the header has always shown, so the sentence still addresses
  /// somebody.
  String _sendOffText(AppLocalizations l10n, List<ReceiptLine> lines) {
    final who = _memberFirstName.isEmpty ? _memberName : _memberFirstName;
    switch (sendOffFor(lines.map((l) => l.iconName))) {
      case DeckelSendOff.prost:
        return l10n.receiptSendOffProst(who);
      case DeckelSendOff.appetit:
        return l10n.receiptSendOffAppetit(who);
      case DeckelSendOff.erholung:
        return l10n.receiptSendOffErholung(who);
      case DeckelSendOff.bisBald:
        return l10n.receiptSendOffBisBald(who);
    }
  }

  /// Icon, headline and member name — the top of every receipt variant.
  ///
  /// [compact] gives a long receipt's lines the room: a smaller icon and less
  /// air under the name, the type itself unchanged.
  List<Widget> _receiptHeader({
    required IconData icon,
    required Color iconColor,
    required String title,
    bool compact = false,
  }) {
    return [
      Icon(icon, size: compact ? 48 : 64, color: iconColor),
      const SizedBox(height: AppSpacing.md),
      Text(
        title,
        style: TextStyle(
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
      SizedBox(height: compact ? AppSpacing.md : AppSpacing.xxl),
    ];
  }

  /// One booked line: icon, "2 ×", name, what it came to.
  ///
  /// [compact] is the long-receipt row: less air and a smaller icon, the type
  /// unchanged — a line is still read from across the counter.
  Widget _lineRow(ReceiptLine line, {required bool compact}) {
    return Padding(
      padding: EdgeInsets.symmetric(
        vertical: compact ? AppSpacing.xs : AppSpacing.md,
      ),
      child: Row(
        children: [
          getProductIcon(line.iconName, size: compact ? 32 : 44),
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
              // Name, then size (ADR-0056) — a receipt has to name the same
              // thing the tile the member tapped did.
              line.label(_locale),
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
            style: TextStyle(
              color: AppColors.semanticInfo,
              fontSize: _receiptTotalSize,
              fontWeight: FontWeight.w700,
              fontFeatures: const [FontFeature.tabularFigures()],
            ),
          ),
        ],
      ),
    );
  }

  /// Where the balance count starts: the final balance minus what this
  /// receipt says was billed.
  ///
  /// The *billed* amount, never the asked-for one — a partial dispense bills
  /// less than the cart did, and a count that started from the larger figure
  /// would show two numbers on one screen that do not add up. Falls back to
  /// [_lastBilledCents] where the session lookup failed and there is no
  /// read-back total (#16).
  int _balanceBeforeCents(int? billedCents) =>
      _balanceCents - (billedCents ?? _lastBilledCents);

  /// The tab as it stands now — the number the member walks away with —
  /// and, under it, the bar that drains while the receipt is on screen.
  ///
  /// No session reference here (#25): a raw UUID means nothing to the member
  /// it is shown to, and the transaction is looked up from the local database
  /// or the backend when staff actually need it.
  List<Widget> _balanceBlock(
    AppLocalizations l10n, {
    bool compact = false,
    int? billedCents,
  }) {
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
      // The balance counts from what it was *before* this checkout to what
      // it is now (#921), starting once the receipt has finished scaling in
      // so the number is legible before it moves. While it runs, the total
      // above it and the balance below it add up — which is the whole point:
      // the member sees their tab absorb what they just bought.
      CountingAmount(
        cents: _balanceCents,
        startCents: _balanceBeforeCents(billedCents),
        duration: AppAnimations.balanceCountUp,
        delay: _receiptScaleIn,
        format: (cents) => formatBalance(cents, l10n, _locale),
        // Per value, not fixed: a count that crosses zero crosses from the
        // "open tab" colour to the "credit" one with it.
        styleFor: (cents) => TextStyle(
          color: balanceColor(cents, warnAtCents: _warnAtCents),
          fontSize: _receiptBalanceSize,
          fontWeight: FontWeight.w700,
        ),
        textKey: const Key('receipt-balance'),
        textAlign: TextAlign.center,
      ),
      SizedBox(height: compact ? AppSpacing.md : AppSpacing.xxl),
      _DwellBar(
        fraction: _dwell.inSeconds == 0 ? 0 : _secondsRemaining / _dwell.inSeconds,
      ),
    ];
  }

  /// The scale-in card every receipt variant is painted into.
  ///
  /// The whole body takes the dismissing tap, not a control inside it: there
  /// is nothing to find and nothing to aim for.
  Widget _receiptFrame({
    required List<Widget> children,
    double maxWidth = _receiptWidth,
  }) {
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
              constraints: BoxConstraints(maxWidth: maxWidth),
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
