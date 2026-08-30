import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_exception.dart';
import '../../core/api/student_api.dart';
import '../../core/models/attendance_entry.dart';
import 'attendance_tile.dart';

class AttendanceHistoryScreen extends StatefulWidget {
  const AttendanceHistoryScreen({super.key});

  @override
  State<AttendanceHistoryScreen> createState() => _AttendanceHistoryScreenState();
}

class _AttendanceHistoryScreenState extends State<AttendanceHistoryScreen> {
  final _entries = <AttendanceEntry>[];
  final _scroll = ScrollController();

  int _page = 0;
  int _totalPages = 1;
  bool _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
    _loadNext();
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scroll.position.pixels > _scroll.position.maxScrollExtent - 300) {
      _loadNext();
    }
  }

  Future<void> _refresh() async {
    setState(() {
      _entries.clear();
      _page = 0;
      _totalPages = 1;
      _error = null;
    });
    await _loadNext();
  }

  Future<void> _loadNext() async {
    if (_loading || _page >= _totalPages) return;
    setState(() => _loading = true);
    try {
      final result = await context
          .read<StudentApi>()
          .attendanceHistory(page: _page + 1);
      setState(() {
        _entries.addAll(result.entries);
        _page = result.page;
        _totalPages = result.totalPages;
        _error = null;
      });
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_entries.isEmpty && _loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_entries.isEmpty && _error != null) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(_error!),
            const SizedBox(height: 8),
            OutlinedButton(onPressed: _refresh, child: const Text('Retry')),
          ],
        ),
      );
    }
    if (_entries.isEmpty) {
      return const Center(child: Text('No attendance records yet.'));
    }

    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView.builder(
        controller: _scroll,
        padding: const EdgeInsets.all(12),
        itemCount: _entries.length + (_page < _totalPages ? 1 : 0),
        itemBuilder: (context, i) {
          if (i >= _entries.length) {
            return const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator()),
            );
          }
          return AttendanceTile(entry: _entries[i]);
        },
      ),
    );
  }
}
