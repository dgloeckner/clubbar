import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

/// Says "there is more below" on a vertical list or grid that scrolls.
///
/// A fade and a chevron across the foot of [child] while content remains
/// below the fold, gone once the member reaches the end. On a touch kiosk
/// nothing else says a list goes on: a cart line below the fold read as
/// missing, and a product row under the summary bar as not there at all.
///
/// Pointer-transparent, so a tile or cart line under the fade still takes its
/// tap. Listens to the first scrollable under it (notification depth 0).
class ScrollMoreHint extends StatefulWidget {
  const ScrollMoreHint({required this.child, super.key});

  final Widget child;

  /// Height of the fade over the list's foot.
  static const double fadeHeight = 56.0;

  @override
  State<ScrollMoreHint> createState() => _ScrollMoreHintState();
}

class _ScrollMoreHintState extends State<ScrollMoreHint> {
  bool _moreBelow = false;

  bool _onMetrics(ScrollMetrics metrics, int depth) {
    if (depth != 0 || metrics.axis != Axis.vertical) return false;
    final moreBelow = metrics.extentAfter > 1.0;
    if (moreBelow == _moreBelow) return false;

    // Metrics arrive during layout as well as while scrolling; a rebuild is
    // only allowed outside the build phase.
    if (SchedulerBinding.instance.schedulerPhase ==
        SchedulerPhase.persistentCallbacks) {
      SchedulerBinding.instance.addPostFrameCallback((_) {
        if (mounted) setState(() => _moreBelow = moreBelow);
      });
    } else {
      setState(() => _moreBelow = moreBelow);
    }
    return false;
  }

  @override
  Widget build(BuildContext context) {
    return NotificationListener<ScrollMetricsNotification>(
      onNotification: (n) => _onMetrics(n.metrics, n.depth),
      child: NotificationListener<ScrollNotification>(
        onNotification: (n) => _onMetrics(n.metrics, n.depth),
        child: Stack(
          children: [
            widget.child,
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              child: IgnorePointer(
                child: AnimatedOpacity(
                  key: const Key('scroll-more-hint'),
                  opacity: _moreBelow ? 1.0 : 0.0,
                  duration: AppAnimations.normal,
                  child: Container(
                    height: ScrollMoreHint.fadeHeight,
                    alignment: Alignment.bottomCenter,
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        begin: Alignment.topCenter,
                        end: Alignment.bottomCenter,
                        colors: [
                          AppColors.bgPrimary.withValues(alpha: 0.0),
                          AppColors.bgPrimary,
                        ],
                      ),
                    ),
                    child: const Icon(
                      Icons.keyboard_arrow_down,
                      size: 40,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
