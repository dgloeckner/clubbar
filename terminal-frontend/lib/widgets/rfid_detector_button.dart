import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/rfid_provider.dart';

class RfidDetectorButton extends StatefulWidget {
  const RfidDetectorButton({super.key});

  @override
  State<RfidDetectorButton> createState() => _RfidDetectorButtonState();
}

class _RfidDetectorButtonState extends State<RfidDetectorButton>
    with SingleTickerProviderStateMixin {
  late AnimationController _glowController;
  late Animation<double> _glowAnimation;
  late Animation<double> _opacityAnimation;

  // Colors from prototype
  static const Color _blue = Color(0xff3b82f6);
  static const Color _teal = Color(0xff14b8a6);

  @override
  void initState() {
    super.initState();
    _glowController = AnimationController(
      duration: const Duration(milliseconds: 2000),
      vsync: this,
    )..repeat(reverse: true);

    // Glow blur radius: 40px -> 60px (from prototype)
    _glowAnimation = Tween<double>(begin: 40.0, end: 60.0).animate(
      CurvedAnimation(parent: _glowController, curve: Curves.easeInOut),
    );

    // Glow opacity: 0.2 -> 0.4 (from prototype)
    _opacityAnimation = Tween<double>(begin: 0.2, end: 0.4).animate(
      CurvedAnimation(parent: _glowController, curve: Curves.easeInOut),
    );
  }

  @override
  void dispose() {
    _glowController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Consumer<RfidProvider>(
      builder: (context, rfidProvider, child) {
        // Adjust animation speed based on state
        if (rfidProvider.isScanning) {
          // Faster pulse when scanning (0.8s)
          if (_glowController.duration != const Duration(milliseconds: 800)) {
            _glowController.duration = const Duration(milliseconds: 800);
            _glowController.repeat(reverse: true);
          }
        } else {
          // Slower glow when idle (2s)
          if (_glowController.duration != const Duration(milliseconds: 2000)) {
            _glowController.duration = const Duration(milliseconds: 2000);
            _glowController.repeat(reverse: true);
          }
        }

        return GestureDetector(
          onTap: rfidProvider.isScanning
              ? null
              : () => rfidProvider.simulateCardDetection(context),
          child: AnimatedBuilder(
            animation: _glowAnimation,
            builder: (context, child) {
              return Container(
                width: 140,
                height: 140,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: rfidProvider.isScanning
                        ? [_blue, _teal] // Full opacity when scanning
                        : [
                            _blue.withValues(alpha: 0.2),
                            _teal.withValues(alpha: 0.2),
                          ], // 20% opacity when idle
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: _blue.withValues(
                        alpha: rfidProvider.isScanning ? 0.5 : _opacityAnimation.value,
                      ),
                      blurRadius: rfidProvider.isScanning ? 60 : _glowAnimation.value,
                      spreadRadius: rfidProvider.isScanning ? 5 : 0,
                    ),
                  ],
                ),
                child: Center(
                  child: rfidProvider.isScanning
                      ? SizedBox(
                          width: 60,
                          height: 60,
                          child: CircularProgressIndicator(
                            valueColor: AlwaysStoppedAnimation(
                              Colors.white.withValues(alpha: 0.8),
                            ),
                            strokeWidth: 3,
                          ),
                        )
                      : SvgPicture.asset(
                          'assets/icons/ui/rfid_icon.svg',
                          width: 80,
                          height: 80,
                        ),
                ),
              );
            },
          ),
        );
      },
    );
  }
}
