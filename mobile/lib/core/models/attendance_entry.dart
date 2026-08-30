/// One row of `GET /api/v1/student/attendance/history`.
class AttendanceEntry {
  const AttendanceEntry({
    required this.sessionId,
    required this.courseId,
    required this.classId,
    required this.status,
    required this.source,
    required this.markedAt,
    required this.sessionStartTime,
    required this.sessionStatus,
  });

  final int sessionId;
  final int courseId;
  final int classId;

  /// WAITING | PRESENT | ABSENT | LATE | EXCUSED | PENDING_REVIEW
  final String status;

  /// SYSTEM | QR | MANUAL
  final String source;
  final DateTime? markedAt;
  final DateTime? sessionStartTime;
  final String sessionStatus;

  bool get isPresent => status == 'PRESENT';

  factory AttendanceEntry.fromJson(Map<String, dynamic> json) => AttendanceEntry(
        sessionId: (json['attendance_session_id'] as num?)?.toInt() ?? 0,
        courseId: (json['course_id'] as num?)?.toInt() ?? 0,
        classId: (json['class_id'] as num?)?.toInt() ?? 0,
        status: json['status'] as String? ?? 'WAITING',
        source: json['source'] as String? ?? 'SYSTEM',
        markedAt: DateTime.tryParse(json['marked_at'] as String? ?? ''),
        sessionStartTime:
            DateTime.tryParse(json['session_start_time'] as String? ?? ''),
        sessionStatus: json['session_status'] as String? ?? '',
      );
}
