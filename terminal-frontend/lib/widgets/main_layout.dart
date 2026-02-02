import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/widgets/ruderbar_header.dart';

/// Main layout with persistent header across all screens.
/// Only the body content animates during navigation.
class MainLayout extends StatelessWidget {
  final Widget child;

  const MainLayout({
    super.key,
    required this.child,
  });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xff0a1628),
      appBar: RuderbarHeader(isOnline: true),
      body: child,
    );
  }
}
