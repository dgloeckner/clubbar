import 'package:flutter/material.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

enum ActionButtonStyle { primary, secondary }

class ActionButton extends StatefulWidget {
  final String label;
  final VoidCallback onPressed;
  final bool fullWidth;
  final ActionButtonStyle buttonStyle;
  final bool disabled;

  const ActionButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.fullWidth = true,
    this.buttonStyle = ActionButtonStyle.primary,
    this.disabled = false,
  });

  @override
  State<ActionButton> createState() => _ActionButtonState();
}

class _ActionButtonState extends State<ActionButton>
    with SingleTickerProviderStateMixin {
  late AnimationController _animationController;
  late Animation<double> _scaleAnimation;

  @override
  void initState() {
    super.initState();
    _animationController = AnimationController(
      duration: AppAnimations.normal,
      vsync: this,
    );
    _scaleAnimation = Tween<double>(begin: 1.0, end: 0.98).animate(
      CurvedAnimation(parent: _animationController, curve: Curves.easeOut),
    );
  }

  @override
  void dispose() {
    _animationController.dispose();
    super.dispose();
  }

  void _handleTapDown(TapDownDetails details) {
    if (!widget.disabled) {
      _animationController.forward();
    }
  }

  void _handleTapUp(TapUpDetails details) {
    _animationController.reverse();
    if (!widget.disabled) {
      widget.onPressed();
    }
  }

  void _handleTapCancel() {
    _animationController.reverse();
  }

  @override
  Widget build(BuildContext context) {
    final isPrimary = widget.buttonStyle == ActionButtonStyle.primary;
    final bgColor = isPrimary
        ? const Color(0xff3b82f6)      // Blue primary
        : const Color(0xff0f1d32);     // Secondary bg
    final textColor = isPrimary
        ? Colors.white
        : const Color(0xff94a3b8);     // Secondary text

    return GestureDetector(
      onTapDown: _handleTapDown,
      onTapUp: _handleTapUp,
      onTapCancel: _handleTapCancel,
      child: ScaleTransition(
        scale: _scaleAnimation,
        child: Container(
          width: widget.fullWidth ? double.infinity : 120,
          height: 48,
          decoration: BoxDecoration(
            color: bgColor,
            borderRadius: BorderRadius.circular(AppBorderRadius.md),
            border: isPrimary ? null : Border.all(
              color: const Color(0xff334155),
              width: 1,
            ),
            boxShadow: isPrimary && !widget.disabled
                ? [
                    const BoxShadow(
                      color: Color.fromRGBO(59, 130, 246, 0.3),
                      blurRadius: 12,
                      offset: Offset(0, 4),
                    ),
                  ]
                : const [],
          ),
          child: Material(
            color: Colors.transparent,
            child: InkWell(
              onTap: widget.disabled ? null : widget.onPressed,
              child: Center(
                child: Text(
                  widget.label,
                  style: TextStyle(
                    color: textColor,
                    fontSize: AppFontSizes.base,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
