/// One row of `GET /api/v1/student/schedule` — a weekly class meeting.
class ScheduleEntry {
  const ScheduleEntry({
    required this.courseId,
    required this.classId,
    required this.roomId,
    required this.dayOfWeek,
    required this.day,
    required this.startTime,
    required this.endTime,
  });

  final int courseId;
  final int classId;
  final int roomId;

  /// 0 = Monday … 6 = Sunday (backend convention).
  final int dayOfWeek;
  final String day;
  final String startTime; // "HH:MM"
  final String endTime;

  String get timeRange => '$startTime – $endTime';

  factory ScheduleEntry.fromJson(Map<String, dynamic> json) => ScheduleEntry(
        courseId: (json['course_id'] as num?)?.toInt() ?? 0,
        classId: (json['class_id'] as num?)?.toInt() ?? 0,
        roomId: (json['room_id'] as num?)?.toInt() ?? 0,
        dayOfWeek: (json['day_of_week'] as num?)?.toInt() ?? 0,
        day: json['day'] as String? ?? '',
        startTime: json['start_time'] as String? ?? '',
        endTime: json['end_time'] as String? ?? '',
      );
}
