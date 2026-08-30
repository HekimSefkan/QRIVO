import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api/student_api.dart';
import '../../widgets/async_view.dart';
import '../history/attendance_tile.dart';
import '../schedule/schedule_tile.dart';

class DashboardScreen extends StatelessWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final api = context.read<StudentApi>();
    return AsyncView<Dashboard>(
      load: api.dashboard,
      builder: (context, d) => ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text('Hi, ${d.profile.firstName}', style: Theme.of(context).textTheme.headlineSmall),
          Text(d.profile.studentNumber, style: Theme.of(context).textTheme.bodySmall),
          const SizedBox(height: 20),
          _SummaryRow(summary: d.attendanceSummary),
          const SizedBox(height: 24),
          Text('Today', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          if (d.todaySchedule.isEmpty)
            const _Empty('No classes scheduled today.')
          else
            ...d.todaySchedule.map((e) => ScheduleTile(entry: e)),
          const SizedBox(height: 24),
          Text('Recent attendance', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          if (d.recentAttendance.isEmpty)
            const _Empty('No attendance records yet.')
          else
            ...d.recentAttendance.map((e) => AttendanceTile(entry: e)),
        ],
      ),
    );
  }
}

class _SummaryRow extends StatelessWidget {
  const _SummaryRow({required this.summary});
  final Map<String, int> summary;

  @override
  Widget build(BuildContext context) {
    const order = ['PRESENT', 'LATE', 'EXCUSED', 'ABSENT'];
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final key in order)
          Chip(label: Text('$key ${summary[key] ?? 0}')),
      ],
    );
  }
}

class _Empty extends StatelessWidget {
  const _Empty(this.text);
  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Text(text, style: Theme.of(context).textTheme.bodyMedium),
      );
}
