import 'package:flutter/material.dart';

import '../../core/models/schedule_entry.dart';

class ScheduleTile extends StatelessWidget {
  const ScheduleTile({super.key, required this.entry});

  final ScheduleEntry entry;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 4),
      child: ListTile(
        leading: CircleAvatar(child: Text(entry.day.isNotEmpty ? entry.day[0] : '?')),
        title: Text('Course ${entry.courseId} · Class ${entry.classId}'),
        subtitle: Text('${entry.day} · ${entry.timeRange} · Room ${entry.roomId}'),
      ),
    );
  }
}
