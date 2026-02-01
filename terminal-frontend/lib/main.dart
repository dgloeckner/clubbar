import 'package:flutter/material.dart';
import 'config/app_config.dart';

void main() {
  runApp(const RuderbarTerminalApp());
}

class RuderbarTerminalApp extends StatelessWidget {
  const RuderbarTerminalApp({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: AppConfig.appName,
      theme: ThemeData(
        useMaterial3: true,
        colorScheme: ColorScheme.fromSeed(
          seedColor: const Color(0xFF3B82F6),
          brightness: Brightness.dark,
        ),
      ),
      home: const Scaffold(
        body: Center(
          child: Text('Ruderbar Terminal - Flutter Phase 1'),
        ),
      ),
    );
  }
}
