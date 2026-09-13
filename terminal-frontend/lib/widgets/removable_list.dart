import 'package:flutter/material.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

/// Builds the row for one item, from a snapshot of it.
typedef RemovableRowBuilder<T> = Widget Function(BuildContext context, T item);

/// A list whose removed rows *leave*: the row slides out to the left while its
/// height collapses, and the rows below close the gap (#921).
///
/// The point is to answer the tap. A line that simply disappears leaves the
/// member checking whether they removed the right one; a line that slides out
/// from under their finger while the list closes over it does not.
///
/// The provider stays the source of truth and stays synchronous.
/// `removeItem` / `decreaseItem` are called on the tap exactly as before, and
/// this widget reconciles afterwards by comparing the new list against the one
/// it last rendered: a key that is gone is played out from a **snapshot** of
/// the item, so nothing here holds a reference to state the provider has
/// already dropped.
///
/// A cleared cart is *not* N exits. Checkout empties the whole list at once,
/// behind a `LoadingOverlay`, and animating that would be a farewell parade
/// on the way to the receipt: when everything goes, everything simply goes.
class RemovableList<T> extends StatefulWidget {
  const RemovableList({
    required this.items,
    required this.keyOf,
    required this.itemBuilder,
    this.padding,
    super.key,
  });

  final List<T> items;

  /// Stable identity of an item — the product id for a cart line.
  final Object Function(T item) keyOf;

  final RemovableRowBuilder<T> itemBuilder;

  final EdgeInsetsGeometry? padding;

  @override
  State<RemovableList<T>> createState() => _RemovableListState<T>();
}

class _RemovableListState<T> extends State<RemovableList<T>>
    with TickerProviderStateMixin {
  /// The items as last rendered, in order — what an incoming list is compared
  /// against to find what left.
  late List<T> _rendered;

  /// Rows on their way out: their snapshot, where they were, and the
  /// controller playing them out.
  final List<_ExitingRow<T>> _exiting = [];

  @override
  void initState() {
    super.initState();
    _rendered = List<T>.from(widget.items);
  }

  @override
  void didUpdateWidget(RemovableList<T> oldWidget) {
    super.didUpdateWidget(oldWidget);
    _reconcile();
  }

  void _reconcile() {
    final incoming = List<T>.from(widget.items);
    final incomingKeys = incoming.map(widget.keyOf).toSet();

    // Everything at once is a cleared cart, not N removals (see the class
    // doc): checkout, and the list is frozen under an overlay anyway.
    final clearedOutright = incoming.isEmpty && _rendered.isNotEmpty;
    final reducedMotion = MediaQuery.maybeDisableAnimationsOf(context) ?? false;

    if (!clearedOutright && !reducedMotion) {
      for (var index = 0; index < _rendered.length; index++) {
        final item = _rendered[index];
        final key = widget.keyOf(item);
        if (incomingKeys.contains(key)) continue;
        if (_exiting.any((row) => row.key == key)) continue;

        final controller = AnimationController(
          vsync: this,
          duration: AppAnimations.lineExit,
        );
        final row = _ExitingRow<T>(
          key: key,
          item: item,
          index: index,
          controller: controller,
        );
        _exiting.add(row);
        controller.forward().whenComplete(() {
          if (!mounted) {
            controller.dispose();
            return;
          }
          setState(() => _exiting.remove(row));
          controller.dispose();
        });
      }
    }

    _rendered = incoming;
  }

  @override
  void dispose() {
    for (final row in _exiting) {
      row.controller.dispose();
    }
    _exiting.clear();
    super.dispose();
  }

  /// The live rows with each exiting row put back where it was, so the list
  /// closes over the gap rather than the gap jumping to the end.
  List<Widget> _rows(BuildContext context) {
    final rows = <Widget>[
      for (final item in _rendered)
        KeyedSubtree(
          key: ValueKey(widget.keyOf(item)),
          child: widget.itemBuilder(context, item),
        ),
    ];

    // Ascending, so an earlier insertion cannot shift a later one's index.
    final exiting = [..._exiting]..sort((a, b) => a.index.compareTo(b.index));
    for (final row in exiting) {
      final at = row.index.clamp(0, rows.length);
      rows.insert(
        at,
        _ExitTransition(
          key: ValueKey('exiting-${row.key}'),
          controller: row.controller,
          child: widget.itemBuilder(context, row.item),
        ),
      );
    }
    return rows;
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: widget.padding,
      children: _rows(context),
    );
  }
}

class _ExitingRow<T> {
  _ExitingRow({
    required this.key,
    required this.item,
    required this.index,
    required this.controller,
  });

  final Object key;

  /// A snapshot: the provider has already dropped this line.
  final T item;

  final int index;
  final AnimationController controller;
}

/// Slide out to the left, collapse to nothing. Transforms and a clip, no
/// per-frame repaint of anything expensive (#41).
class _ExitTransition extends StatelessWidget {
  const _ExitTransition({
    required this.controller,
    required this.child,
    super.key,
  });

  final AnimationController controller;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return SizeTransition(
      // The gap closes a touch behind the row leaving, so the rows below
      // follow it rather than racing it.
      sizeFactor: Tween<double>(begin: 1.0, end: 0.0).animate(
        CurvedAnimation(parent: controller, curve: Curves.easeOut),
      ),
      // The row collapses toward its own top edge, so what is below it
      // slides up rather than the row drifting.
      alignment: Alignment.topLeft,
      child: SlideTransition(
        position: Tween<Offset>(
          begin: Offset.zero,
          end: const Offset(-1.0, 0.0),
        ).animate(
          CurvedAnimation(parent: controller, curve: Curves.easeInCubic),
        ),
        child: FadeTransition(
          opacity: Tween<double>(begin: 1.0, end: 0.0).animate(
            CurvedAnimation(parent: controller, curve: Curves.easeIn),
          ),
          child: IgnorePointer(child: child),
        ),
      ),
    );
  }
}
