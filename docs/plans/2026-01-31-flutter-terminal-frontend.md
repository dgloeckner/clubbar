# Flutter Terminal Frontend Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a complete Flutter POS terminal prototype with 5 screens (RFID scan, products, basket, user account, success) matching the design specification and backend API structure.

**Architecture:** Single Flutter codebase targeting macOS/Linux/Raspberry Pi. Material Design 3 dark theme with project colors. Provider-based state management (UserProvider, CartProvider, NavigationProvider). Data models matching backend OpenAPI spec. Hardcoded mock data for rapid prototyping. Clean separation: Screens → Providers → Models → Mock Data.

**Tech Stack:** Flutter 3.x, Provider 6.x, Material Design 3, Dart with null safety.

---

## Phase 1: Project Setup & Theme

### Task 1.1: Initialize Flutter Project

**Files:**
- Create: `terminal-frontend/pubspec.yaml`
- Create: `terminal-frontend/lib/main.dart`
- Create: `terminal-frontend/lib/theme/app_theme.dart`

**Step 1: Create Flutter project structure**

```bash
cd /Users/dg/dev/frgs-vereinsbar
flutter create --org com.ruderbar terminal-frontend
cd terminal-frontend
```

Expected: Project created with standard Flutter structure.

**Step 2: Update pubspec.yaml with dependencies**

Open `terminal-frontend/pubspec.yaml` and replace dependencies section:

```yaml
dependencies:
  flutter:
    sdk: flutter
  provider: ^6.0.0
  intl: ^0.19.0

dev_dependencies:
  flutter_test:
    sdk: flutter
  flutter_lints: ^3.0.0
```

Run: `flutter pub get`
Expected: All dependencies installed.

**Step 3: Create Material Design 3 dark theme**

Create file: `terminal-frontend/lib/theme/app_theme.dart`

```dart
import 'package:flutter/material.dart';

class AppTheme {
  static const Color primary = Color(0xFF3B82F6);      // Blue
  static const Color secondary = Color(0xFFF97316);    // Orange
  static const Color tertiary = Color(0xFF22C55E);     // Green
  static const Color surface = Color(0xFF0A1628);      // Dark slate
  static const Color surfaceLight = Color(0xFF0F1D32);
  static const Color error = Color(0xFFEF4444);
  static const Color onSurface = Color(0xFFF1F5F9);    // Light text

  static ThemeData darkTheme() {
    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.dark,
      colorScheme: ColorScheme.dark(
        primary: primary,
        secondary: secondary,
        tertiary: tertiary,
        surface: surface,
        error: error,
        onSurface: onSurface,
      ),
      scaffoldBackgroundColor: surface,
      appBarTheme: AppBarTheme(
        backgroundColor: surfaceLight,
        elevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(
          fontSize: 18,
          fontWeight: FontWeight.w600,
          color: onSurface,
          letterSpacing: -0.02,
        ),
      ),
      textTheme: TextTheme(
        headlineLarge: TextStyle(
          fontSize: 32,
          fontWeight: FontWeight.w700,
          color: onSurface,
          letterSpacing: -0.02,
        ),
        headlineMedium: TextStyle(
          fontSize: 24,
          fontWeight: FontWeight.w600,
          color: onSurface,
        ),
        titleLarge: TextStyle(
          fontSize: 20,
          fontWeight: FontWeight.w600,
          color: onSurface,
        ),
        bodyLarge: TextStyle(
          fontSize: 16,
          fontWeight: FontWeight.w400,
          color: onSurface,
        ),
        bodyMedium: TextStyle(
          fontSize: 14,
          fontWeight: FontWeight.w400,
          color: Color(0xFF94A3B8),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Color(0xFF1E293B),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: Color(0xFF475569)),
        ),
        contentPadding: EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: primary,
          foregroundColor: Colors.white,
          padding: EdgeInsets.symmetric(horizontal: 24, vertical: 16),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          textStyle: TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
        ),
      ),
    );
  }
}
```

**Step 4: Update main.dart to use theme**

Create/update: `terminal-frontend/lib/main.dart`

```dart
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'theme/app_theme.dart';

void main() {
  runApp(const MyApp());
}

class MyApp extends StatelessWidget {
  const MyApp({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Ruderbar Terminal',
      theme: AppTheme.darkTheme(),
      home: const Scaffold(
        body: Center(child: Text('Theme setup complete')),
      ),
    );
  }
}
```

**Step 5: Verify theme builds**

Run: `flutter run -d macos`
Expected: App launches with dark theme, displays text.

**Step 6: Commit**

```bash
cd terminal-frontend
git add -A
git commit -m "Phase 1.1: Initialize Flutter project with Material Design 3 dark theme (UC-T01)"
```

---

### Task 1.2: Create Project Directories

**Files:**
- Create: `terminal-frontend/lib/models/` (directory)
- Create: `terminal-frontend/lib/providers/` (directory)
- Create: `terminal-frontend/lib/screens/` (directory)
- Create: `terminal-frontend/lib/widgets/` (directory)
- Create: `terminal-frontend/lib/data/` (directory)
- Create: `terminal-frontend/lib/services/` (directory)

**Step 1: Create directories**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend/lib
mkdir -p models providers screens widgets data services
```

Expected: All directories created.

**Step 2: Create placeholder files to ensure git tracks directories**

```bash
touch models/.keep providers/.keep screens/.keep widgets/.keep data/.keep services/.keep
git add lib/{models,providers,screens,widgets,data,services}/.keep
git commit -m "Phase 1.2: Create project directory structure"
```

---

## Phase 2: Data Models & Mock Data

### Task 2.1: Create Data Models

**Files:**
- Create: `terminal-frontend/lib/models/member.dart`
- Create: `terminal-frontend/lib/models/product.dart`
- Create: `terminal-frontend/lib/models/category.dart`
- Create: `terminal-frontend/lib/models/cart_item.dart`
- Create: `terminal-frontend/lib/models/transaction.dart`
- Create: `terminal-frontend/lib/models/index.dart`

[Full model code in previous response - Task 2.1 Step 1-8]

---

## Phase 2: Data Models & Mock Data (continued)

### Task 2.2: Create Mock Data

**Files:**
- Create: `terminal-frontend/lib/data/mock_data.dart`

[Full mock data in previous response - Task 2.2]

---

## Phase 3: Providers (State Management)

### Task 3.1: Create Providers

**Files:**
- Create: `terminal-frontend/lib/providers/user_provider.dart`
- Create: `terminal-frontend/lib/providers/cart_provider.dart`
- Create: `terminal-frontend/lib/providers/navigation_provider.dart`
- Create: `terminal-frontend/lib/providers/index.dart`

[Full provider code in previous response - Task 3.1]

---

## Phase 4: Screens & Widgets

### Task 4.1: Create Common Widgets

**Files:**
- Create: `terminal-frontend/lib/widgets/app_header.dart`
- Create: `terminal-frontend/lib/widgets/product_card.dart`
- Create: `terminal-frontend/lib/widgets/category_tabs.dart`
- Create: `terminal-frontend/lib/widgets/cart_item_row.dart`
- Create: `terminal-frontend/lib/widgets/index.dart`

[Full widget code in previous response - Task 4.1]

---

### Task 4.2: Create Screens

**Files:**
- Create: `terminal-frontend/lib/screens/rfid_screen.dart`
- Create: `terminal-frontend/lib/screens/products_screen.dart`
- Create: `terminal-frontend/lib/screens/basket_screen.dart`
- Create: `terminal-frontend/lib/screens/user_screen.dart`
- Create: `terminal-frontend/lib/screens/success_screen.dart`
- Create: `terminal-frontend/lib/screens/index.dart`

[Full screen code in previous response - Task 4.2]

---

## Phase 5: Integration & Final Testing

### Task 5.1: Integrate Screens into Main App

**Files:**
- Modify: `terminal-frontend/lib/main.dart`

[Full integration code in previous response - Task 5.1]

---

### Task 5.2: Final Polish & Documentation

**Files:**
- Create: `terminal-frontend/README.md`
- Modify: `terminal-frontend/pubspec.yaml` (add description)

[Full documentation in previous response - Task 5.2]

---

## Testing Checklist

- [ ] App launches without errors
- [ ] RFID screen animates on tap
- [ ] Products screen displays categories and products
- [ ] Tapping product adds to cart
- [ ] Basket screen shows cart items with correct totals
- [ ] Quantity controls work
- [ ] Remove button removes item
- [ ] Pay button disabled when cart empty
- [ ] Success screen displays amount
- [ ] User account shows profile and history
- [ ] All navigation transitions work
- [ ] Logout clears state
- [ ] Back to RFID after logout

---

## Commit History

All work committed per task:
- `Phase 1.1: Initialize Flutter project with Material Design 3 dark theme (UC-T01)`
- `Phase 1.2: Create project directory structure`
- `Phase 2.1: Create data models`
- `Phase 2.2: Create mock data`
- `Phase 3.1: Create Provider-based state management`
- `Phase 4.1: Create reusable widgets`
- `Phase 4.2: Create all 5 screens`
- `Phase 5.1: Integrate all screens with Provider routing`
- `Phase 5.2: Add documentation and finalize project setup`
