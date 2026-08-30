import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/models/attendance_entry.dart';

class AttendanceTile extends StatelessWidget {
  const AttendanceTile({super.key, required this.entry});

  final AttendanceEntry entry;

  Color _color(BuildContext context) {
    switch (entry.status) {
      case 'PRESENT':
        return Colors.green;
      case 'LATE':
        return Colors.orange;
      case 'EXCUSED':
        return Colors.blue;
      case 'ABSENT':
        return Theme.of(context).colorScheme.error;
      case 'PENDING_REVIEW':
        return Colors.purple;
      default:
        return Theme.of(context).disabledColor;
    }
  }

  @override
  Widget build(BuildContext context) {
    final when = entry.sessionStartTime;
    final subtitle = when != null
        ? DateFormat.yMMMEd().add_jm().format(when)
        : 'Session ${entry.sessionId}';
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 4),
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: _color(context),
          child: const Icon(Icons.check, color: Colors.white, size: 18),
        ),
        title: Text('Course ${entry.courseId} · ${entry.status}'),
        subtitle: Text('$subtitle · via ${entry.source}'),
      ),
    );
  }
}
