# Plan: Refactor Status Modal with Tabs

## Goal
Transform the current status modal into a tabbed interface with two tabs:
1. **Übersicht (Overview)** - Quick summary view
2. **Dispenser Status** - Detailed dispenser dashboard matching the provided design

## Current State
- Single modal showing sync status, dispenser health summary, and endpoints
- Limited space for dispenser information
- No visual distinction between different types of information

## Target Design Analysis

### Design Reference: `/Users/dg/Downloads/dispenser-dashboard.jsx`

The target design shows a comprehensive dispenser dashboard with:

1. **Uptime Section**
   - Uptime counter (HH:MM:SS format)
   - Firmware version display
   - Debug mode indicator

2. **Machine State Section**
   - Current dispenser state badge (idle/dispensing/error)
   - 4 metric cards:
     - Total Dispensed
     - Successful (green accent)
     - Jams (orange accent)
     - Failures (red accent)

3. **Network Section**
   - WiFi signal strength visual indicator (bar graph)
   - SSID name
   - RSSI (dBm) and signal percentage
   - IP address display

4. **Error History Section**
   - List of errors with:
     - Error code (E1, E2, etc.)
     - Error type (COIN_STUCK, etc.)
     - Timestamp (T+Xh Ym Zs format)
     - Cleared/Active status with visual indicator

### Visual Design Features
- Dark theme with subtle backgrounds
- Monospace font (JetBrains Mono) for technical data
- Color-coded metrics:
  - Green (#34d399) - success
  - Orange (#f59e0b) - warnings/jams
  - Red (#ef4444) - failures/errors
  - Blue (#818cf8) - idle state
- Metric cards with large numbers (30px font)
- Rounded corners and subtle borders
- Grid dot background pattern
- Scanline animation (optional)

---

## Implementation Plan

### Phase 1: Add Tab Navigation Component (15 min)

**Files to modify:**
- `lib/widgets/status_info_modal.dart`

**Tasks:**
1. Create `_TabButton` widget for tab switching
2. Add state management for active tab (useState with TabView enum)
3. Create tab bar UI at top of modal (below header)
4. Style tabs to match design tokens

**Design:**
```dart
enum StatusModalTab { overview, dispenser }

Widget _buildTabBar() {
  return Row(
    children: [
      _TabButton(
        label: 'Übersicht',
        isActive: _currentTab == StatusModalTab.overview,
        onTap: () => setState(() => _currentTab = StatusModalTab.overview),
      ),
      _TabButton(
        label: 'Dispenser Status',
        isActive: _currentTab == StatusModalTab.dispenser,
        onTap: () => setState(() => _currentTab = StatusModalTab.dispenser),
      ),
    ],
  );
}
```

---

### Phase 2: Extract Overview Tab (20 min)

**Files to modify:**
- `lib/widgets/status_info_modal.dart`

**Tasks:**
1. Move existing content into `_buildOverviewTab()` method
2. Simplify dispenser section to show only:
   - Status (Online/Offline)
   - Success rate percentage
3. Keep sync status and endpoints sections as-is
4. Conditionally render based on `_currentTab`

**Content for Overview Tab:**
- Sync Status section (lastSync, lastTransactionSync, retryCount)
- Dispenser section (status + success rate only)
- Endpoints section (backend URL, dispenser URL)
- Error section (if any)

---

### Phase 3: Create Dispenser Dashboard Tab (60 min)

**Files to modify:**
- `lib/widgets/status_info_modal.dart`
- `lib/l10n/app_en.arb` (add new strings)
- `lib/l10n/app_de.arb` (add new strings)

**Tasks:**

#### 3.1: Add Localization Strings
```arb
"dispenserUptime": "Uptime",
"dispenserFirmware": "Firmware",
"dispenserMachineState": "Machine State",
"dispenserDispensed": "Dispensed",
"dispenserSuccess": "Success",
"dispenserJams": "Jams",
"dispenserFailures": "Failures",
"dispenserNetwork": "Network",
"dispenserSignalStrength": "Signal Strength",
"dispenserErrorHistory": "Error History",
"dispenserNoErrors": "No errors recorded",
"dispenserErrorCleared": "resolved",
```

#### 3.2: Create Helper Widgets

**MetricCard Widget:**
```dart
Widget _buildMetricCard({
  required String label,
  required int value,
  Color? accentColor,
}) {
  return Container(
    padding: EdgeInsets.symmetric(vertical: 18, horizontal: 8),
    decoration: BoxDecoration(
      color: Color(0x05FFFFFF), // rgba(255,255,255,0.02)
      border: Border.all(color: Color(0x0DFFFFFF)), // rgba(255,255,255,0.05)
      borderRadius: BorderRadius.circular(12),
    ),
    child: Column(
      children: [
        Text(
          '$value',
          style: TextStyle(
            fontSize: 30,
            fontWeight: FontWeight.w700,
            color: accentColor ?? Color(0xffe2e8f0),
          ),
        ),
        SizedBox(height: 8),
        Text(
          label.toUpperCase(),
          style: TextStyle(
            fontSize: 9,
            color: Color(0xff475569),
            letterSpacing: 0.1,
          ),
        ),
      ],
    ),
  );
}
```

**WiFi Signal Strength Bar:**
```dart
Widget _buildWiFiSignalBar(int signalPercent) {
  return Row(
    children: [25, 50, 75, 100].asMap().entries.map((entry) {
      final threshold = entry.value;
      final index = entry.key;
      final isActive = signalPercent >= threshold;

      return Container(
        margin: EdgeInsets.only(right: 2),
        width: 3,
        height: 4.0 + (index * 4),
        decoration: BoxDecoration(
          color: isActive
            ? Color(0xCC34D399) // rgba(52,211,153,0.8)
            : Color(0x14FFFFFF), // rgba(255,255,255,0.08)
          borderRadius: BorderRadius.circular(1),
        ),
      );
    }).toList(),
  );
}
```

#### 3.3: Build Dispenser Dashboard Layout

**Main Structure:**
```dart
Widget _buildDispenserTab(AppLocalizations l10n, DispenserHealth health) {
  return SingleChildScrollView(
    child: Column(
      children: [
        _buildUptimeSection(l10n, health),
        SizedBox(height: 20),
        _buildMachineStateSection(l10n, health),
        SizedBox(height: 20),
        _buildNetworkSection(l10n, health),
        SizedBox(height: 20),
        _buildErrorHistorySection(l10n, health),
      ],
    ),
  );
}
```

**Uptime Section:**
```dart
Widget _buildUptimeSection(AppLocalizations l10n, DispenserHealth health) {
  // Note: Need to add uptime field to DispenserHealth model
  return Container(
    padding: EdgeInsets.symmetric(horizontal: 18, vertical: 12),
    decoration: BoxDecoration(
      color: Color(0x08FFFFFF),
      border: Border.all(color: Color(0x0AFFFFFF)),
      borderRadius: BorderRadius.circular(12),
    ),
    child: Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Row(
          children: [
            Text('UPTIME', style: smallLabelStyle),
            SizedBox(width: 8),
            Text(
              formatUptime(uptimeSeconds), // HH:MM:SS
              style: monospaceLargeStyle.copyWith(color: Color(0xff34d399)),
            ),
          ],
        ),
        Text('fw 1.0.0', style: monospaceTinyStyle),
      ],
    ),
  );
}
```

**Machine State Section with Metrics:**
```dart
Widget _buildMachineStateSection(AppLocalizations l10n, DispenserHealth health) {
  return Container(
    padding: EdgeInsets.all(24),
    decoration: BoxDecoration(
      gradient: LinearGradient(
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
        colors: [
          Color(0xCC0f172a), // rgba(15,23,42,0.8)
          Color(0x660f172a), // rgba(15,23,42,0.4)
        ],
      ),
      border: Border.all(color: Color(0x0FFFFFFF)),
      borderRadius: BorderRadius.circular(16),
    ),
    child: Column(
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text('MACHINE STATE', style: smallLabelStyle),
            _buildStatusBadge(health.dispenser),
          ],
        ),
        SizedBox(height: 20),
        Row(
          children: [
            Expanded(child: _buildMetricCard(
              label: l10n.dispenserDispensed,
              value: health.totalDispenses,
            )),
            SizedBox(width: 8),
            Expanded(child: _buildMetricCard(
              label: l10n.dispenserSuccess,
              value: health.successful,
              accentColor: Color(0xff34d399),
            )),
            SizedBox(width: 8),
            Expanded(child: _buildMetricCard(
              label: l10n.dispenserJams,
              value: health.jams,
              accentColor: Color(0xfff59e0b),
            )),
            SizedBox(width: 8),
            Expanded(child: _buildMetricCard(
              label: l10n.dispenserFailures,
              value: health.failures ?? 0,
              accentColor: Color(0xffef4444),
            )),
          ],
        ),
      ],
    ),
  );
}

Widget _buildStatusBadge(String status) {
  final isIdle = status == 'idle';
  return Container(
    padding: EdgeInsets.symmetric(horizontal: 14, vertical: 4),
    decoration: BoxDecoration(
      color: isIdle ? Color(0x196366f1) : Color(0x1934d399),
      border: Border.all(
        color: isIdle ? Color(0x336366f1) : Color(0x3334d399),
      ),
      borderRadius: BorderRadius.circular(6),
    ),
    child: Text(
      status.toUpperCase(),
      style: TextStyle(
        fontFamily: 'monospace',
        fontSize: 11,
        fontWeight: FontWeight.w600,
        color: isIdle ? Color(0xff818cf8) : Color(0xff34d399),
        letterSpacing: 0.08,
      ),
    ),
  );
}
```

**Network Section:**
```dart
Widget _buildNetworkSection(AppLocalizations l10n, DispenserHealth health) {
  // Note: Need to add network info to DispenserHealth model
  final wifiPercent = rssiToPercent(rssi);

  return Container(
    padding: EdgeInsets.symmetric(horizontal: 22, vertical: 20),
    decoration: BoxDecoration(
      color: Color(0x990f172a),
      border: Border.all(color: Color(0x0FFFFFFF)),
      borderRadius: BorderRadius.circular(16),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('NETWORK', style: smallLabelStyle),
        SizedBox(height: 16),
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Row(
              children: [
                _buildWiFiSignalBar(wifiPercent),
                SizedBox(width: 12),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(ssid, style: monospaceMediumStyle),
                    Text('$rssi dBm · $wifiPercent%', style: monospaceTinyStyle),
                  ],
                ),
              ],
            ),
            Container(
              padding: EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                color: Color(0x33000000),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(ipAddress, style: monospaceTinyStyle),
            ),
          ],
        ),
      ],
    ),
  );
}
```

**Error History Section:**
```dart
Widget _buildErrorHistorySection(AppLocalizations l10n, DispenserHealth health) {
  // Note: Need to add error_history to DispenserHealth model
  final errors = health.errorHistory ?? [];

  return Container(
    padding: EdgeInsets.symmetric(horizontal: 22, vertical: 20),
    decoration: BoxDecoration(
      color: Color(0x990f172a),
      border: Border.all(color: Color(0x0FFFFFFF)),
      borderRadius: BorderRadius.circular(16),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('ERROR HISTORY', style: smallLabelStyle),
        SizedBox(height: 16),
        errors.isEmpty
          ? Text(l10n.dispenserNoErrors, style: monospaceTinyStyle)
          : Column(
              children: errors.map((error) => _buildErrorItem(l10n, error)).toList(),
            ),
      ],
    ),
  );
}

Widget _buildErrorItem(AppLocalizations l10n, DispenserError error) {
  return Container(
    margin: EdgeInsets.only(bottom: 8),
    padding: EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: error.cleared ? Color(0x05FFFFFF) : Color(0x0Fef4444),
      border: Border.all(
        color: error.cleared ? Color(0x0AFFFFFF) : Color(0x26ef4444),
      ),
      borderRadius: BorderRadius.circular(10),
    ),
    child: Row(
      children: [
        Container(
          width: 32,
          height: 32,
          decoration: BoxDecoration(
            color: error.cleared ? Color(0x1434d399) : Color(0x19ef4444),
            borderRadius: BorderRadius.circular(8),
          ),
          child: Center(
            child: Text(
              error.cleared ? '✓' : '⚠',
              style: TextStyle(
                fontSize: 14,
                color: error.cleared ? Color(0xff34d399) : Color(0xffef4444),
              ),
            ),
          ),
        ),
        SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'E${error.code} · ${error.type}',
                style: monospaceTinyStyle.copyWith(
                  fontWeight: FontWeight.w600,
                  color: error.cleared ? Color(0xff94a3b8) : Color(0xffef4444),
                ),
              ),
              SizedBox(height: 2),
              Text(
                '${formatTimestamp(error.timestamp)}${error.cleared ? " · resolved" : ""}',
                style: monospaceTinyStyle.copyWith(color: Color(0xff475569)),
              ),
            ],
          ),
        ),
      ],
    ),
  );
}
```

---

### Phase 4: Update Data Models (30 min)

**Files to modify:**
- `lib/services/dispenser_client.dart`

**Tasks:**

1. **Extend DispenserHealth model** to include additional fields:
```dart
class DispenserHealth {
  final String status;
  final String dispenser;
  final int totalDispenses;
  final int successful;
  final int jams;
  final double successRate;

  // NEW FIELDS:
  final int? uptime; // seconds
  final String? firmware;
  final WifiInfo? wifi;
  final int? failures;
  final int? partial;
  final List<DispenserError>? errorHistory;

  // Constructor, fromJson, etc.
}
```

2. **Add WifiInfo model:**
```dart
class WifiInfo {
  final int rssi; // signal strength in dBm
  final String ip;
  final String ssid;

  WifiInfo({required this.rssi, required this.ip, required this.ssid});

  factory WifiInfo.fromJson(Map<String, dynamic> json) {
    return WifiInfo(
      rssi: json['rssi'] as int,
      ip: json['ip'] as String,
      ssid: json['ssid'] as String,
    );
  }
}
```

3. **Add DispenserError model:**
```dart
class DispenserError {
  final int code;
  final String type;
  final int timestamp; // milliseconds
  final bool cleared;

  DispenserError({
    required this.code,
    required this.type,
    required this.timestamp,
    required this.cleared,
  });

  factory DispenserError.fromJson(Map<String, dynamic> json) {
    return DispenserError(
      code: json['code'] as int,
      type: json['type'] as String,
      timestamp: json['timestamp'] as int,
      cleared: json['cleared'] as bool,
    );
  }
}
```

4. **Update DispenserHealth.fromJson** to parse new fields

5. **Update DispenserHealth.offline()** factory to handle new fields

---

### Phase 5: Add Helper Functions (15 min)

**Files to modify:**
- `lib/widgets/status_info_modal.dart`

**Tasks:**

1. Add uptime formatter:
```dart
String _formatUptime(int seconds) {
  final h = seconds ~/ 3600;
  final m = (seconds % 3600) ~/ 60;
  final s = seconds % 60;
  return '${h.toString().padLeft(2, '0')}:${m.toString().padLeft(2, '0')}:${s.toString().padLeft(2, '0')}';
}
```

2. Add timestamp formatter for error history:
```dart
String _formatErrorTimestamp(int ms) {
  final h = ms ~/ 3600000;
  final m = (ms % 3600000) ~/ 60000;
  final s = (ms % 60000) ~/ 1000;
  return 'T+${h}h ${m}m ${s}s';
}
```

3. Add RSSI to percentage converter:
```dart
int _rssiToPercent(int rssi) {
  return (2 * (rssi + 100)).clamp(0, 100);
}
```

4. Add reusable text styles:
```dart
final smallLabelStyle = TextStyle(
  fontSize: 10,
  fontWeight: FontWeight.w600,
  color: Color(0xff475569),
  letterSpacing: 0.12,
);

final monospaceLargeStyle = TextStyle(
  fontFamily: 'monospace',
  fontSize: 18,
  fontWeight: FontWeight.w600,
  letterSpacing: 0.05,
);

final monospaceMediumStyle = TextStyle(
  fontFamily: 'monospace',
  fontSize: 14,
  fontWeight: FontWeight.w600,
  color: Color(0xffe2e8f0),
);

final monospaceTinyStyle = TextStyle(
  fontFamily: 'monospace',
  fontSize: 10,
  color: Color(0xff475569),
);
```

---

### Phase 6: Update Modal to StatefulWidget (10 min)

**Files to modify:**
- `lib/widgets/status_info_modal.dart`

**Tasks:**
1. Convert `_StatusInfoDialog` from StatelessWidget to StatefulWidget
2. Add `_currentTab` state variable
3. Add tab switching logic
4. Render tab content conditionally

---

### Phase 7: Testing & Polish (20 min)

**Tasks:**
1. Test tab switching behavior
2. Verify all data displays correctly
3. Test with dispenser offline (null health data)
4. Test with empty error history
5. Ensure proper scrolling in both tabs
6. Verify colors match design
7. Check responsive layout
8. Run `flutter gen-l10n` to generate localization

---

## Summary of Changes

### New Files
- None (all changes in existing files)

### Modified Files
1. `lib/widgets/status_info_modal.dart` - Main refactoring
2. `lib/services/dispenser_client.dart` - Extend data models
3. `lib/l10n/app_en.arb` - Add ~15 new strings
4. `lib/l10n/app_de.arb` - Add ~15 new German translations

### New Widgets
- `_TabButton` - Tab navigation button
- `_buildTabBar()` - Tab bar container
- `_buildOverviewTab()` - Overview tab content
- `_buildDispenserTab()` - Dispenser dashboard tab
- `_buildUptimeSection()` - Uptime display
- `_buildMachineStateSection()` - Metrics section
- `_buildNetworkSection()` - Network info
- `_buildErrorHistorySection()` - Error list
- `_buildMetricCard()` - Individual metric display
- `_buildWiFiSignalBar()` - WiFi strength indicator
- `_buildStatusBadge()` - Machine state badge
- `_buildErrorItem()` - Single error display

### New Data Models
- `WifiInfo` - WiFi connection details
- `DispenserError` - Error history entry
- Extended `DispenserHealth` - Add uptime, wifi, errors

### New Helper Functions
- `_formatUptime()` - Format seconds to HH:MM:SS
- `_formatErrorTimestamp()` - Format milliseconds to T+Xh Ym Zs
- `_rssiToPercent()` - Convert dBm to percentage

---

## Estimated Time
- **Total:** ~2.5 hours
- **Critical Path:** Phase 3 (Dispenser Dashboard) + Phase 4 (Data Models)

## Dependencies
- Backend must provide extended health data (uptime, wifi, errors)
- If backend doesn't provide these fields yet, show placeholder or "N/A"

## Risks & Mitigations
1. **Risk:** Backend doesn't return extended health data
   - **Mitigation:** Make all new fields nullable, show "N/A" or hide sections if data unavailable

2. **Risk:** Modal becomes too tall for small screens
   - **Mitigation:** Already using SingleChildScrollView with maxHeight constraint

3. **Risk:** Tab switching performance issues
   - **Mitigation:** Use simple setState, no complex animations needed

## Success Criteria
- [x] Two tabs visible and switchable
- [x] Overview tab shows condensed info (sync, basic dispenser status, endpoints)
- [x] Dispenser tab matches design reference visual layout
- [x] All metrics display correctly
- [x] WiFi signal bar animates/displays properly
- [x] Error history shows cleared vs active errors correctly
- [x] Colors match design specification
- [x] German translations provided for all new strings
- [x] Modal scrolls properly when content overflows
