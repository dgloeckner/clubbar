import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/rfid_provider.dart';

class RfidDetectorButton extends StatelessWidget {
  const RfidDetectorButton({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return Consumer<RfidProvider>(
      builder: (context, rfidProvider, child) {
        return GestureDetector(
          onTap: rfidProvider.isScanning
              ? null
              : () => rfidProvider.simulateCardDetection(context),
          child: Container(
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
                  color: Colors.blue.withOpacity(0.3),
                  blurRadius: rfidProvider.isScanning ? 30 : 15,
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
                        Colors.white.withOpacity(0.8),
                      ),
                      strokeWidth: 3,
                    ),
                  )
                  : Icon(
                    Icons.contactless,
                    size: 60,
                    color: Colors.white,
                  ),
            ),
          ),
        );
      },
    );
  }
}
