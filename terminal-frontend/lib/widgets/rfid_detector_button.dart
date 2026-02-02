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

  @override
  void initState() {
    super.initState();
    _glowController = AnimationController(
      duration: const Duration(milliseconds: 2000),
      vsync: this,
    )..repeat(reverse: true);

    _glowAnimation = Tween<double>(begin: 15.0, end: 30.0).animate(
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
        // Stop animation when not scanning
        if (rfidProvider.isScanning && !_glowController.isAnimating) {
          _glowController.repeat(reverse: true);
        } else if (!rfidProvider.isScanning && _glowController.isAnimating) {
          _glowController.stop();
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
                        ? [
                            Colors.blue.shade400,
                            Colors.teal.shade300,
                          ]
                        : [
                            Colors.blue.shade200,
                            Colors.teal.shade200,
                          ],
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: const Color(0xff0ea5e9).withValues(
                        alpha: rfidProvider.isScanning ? 0.6 : 0.3,
                      ),
                      blurRadius: rfidProvider.isScanning ? _glowAnimation.value : 15,
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
