import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api/student_api.dart';
import '../../core/models/schedule_entry.dart';
import '../../widgets/async_view.dart';
import 'schedule_tile.dart';

class ScheduleScreen extends StatelessWidget {
  const ScheduleScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final api = context.read<StudentApi>();
    return AsyncView<List<ScheduleEntry>>(
      load: api.schedule,
      builder: (context, entries) {
        if (entries.isEmpty) {
          return ListView(
            children: const [
              SizedBox(height: 160),
              Center(child: Text('No scheduled classes.')),
            ],
          );
        }
        final byDay = <String, List<ScheduleEntry>>{};
        for (final e in entries) {
          byDay.putIfAbsent(e.day, () => []).add(e);
        }
        return ListView(
          padding: const EdgeInsets.all(12),
          children: [
            for (final day in byDay.keys) ...[
              Padding(
                padding: const EdgeInsets.fromLTRB(4, 12, 4, 4),
                child: Text(day, style: Theme.of(context).textTheme.titleMedium),
              ),
              ...byDay[day]!.map((e) => ScheduleTile(entry: e)),
            ],
          ],
        );
      },
    );
  }
}
