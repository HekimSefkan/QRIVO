import 'package:flutter/material.dart';

import '../attendance/qr_scanner_screen.dart';
import '../dashboard/dashboard_screen.dart';
import '../history/history_screen.dart';
import '../profile/profile_screen.dart';
import '../schedule/schedule_screen.dart';

/// The signed-in student's tab shell: Dashboard · Schedule · History · Profile,
/// with a always-present "Scan" action that opens the QR attendance flow.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _index = 0;

  static const _tabs = <(String, IconData, Widget)>[
    ('Home', Icons.home_outlined, DashboardScreen()),
    ('Schedule', Icons.calendar_today_outlined, ScheduleScreen()),
    ('History', Icons.history, AttendanceHistoryScreen()),
    ('Profile', Icons.person_outline, ProfileScreen()),
  ];

  void _openScanner() =>
      Navigator.of(context).push(QrScannerScreen.route());

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(_tabs[_index].$1)),
      body: IndexedStack(
        index: _index,
        children: [for (final t in _tabs) t.$3],
      ),
      floatingActionButton: FloatingActionButton.extended(
        key: const Key('scan_attendance'),
        onPressed: _openScanner,
        icon: const Icon(Icons.qr_code_scanner),
        label: const Text('Scan'),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: [
          for (final t in _tabs)
            NavigationDestination(icon: Icon(t.$2), label: t.$1),
        ],
      ),
    );
  }
}
