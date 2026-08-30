import 'package:flutter_test/flutter_test.dart';
import 'package:qrivo_mobile/core/models/attendance_entry.dart';
import 'package:qrivo_mobile/core/models/schedule_entry.dart';
import 'package:qrivo_mobile/core/models/student_profile.dart';
import 'package:qrivo_mobile/core/models/user.dart';

void main() {
  test('User.fromJson maps snake_case and derives helpers', () {
    final u = User.fromJson({
      'uuid': 'u-1',
      'email': 's@x.test',
      'first_name': 'Sam',
      'last_name': 'Lee',
      'roles': ['STUDENT', 'X'],
    });
    expect(u.fullName, 'Sam Lee');
    expect(u.isStudent, isTrue);
    expect(User.fromJson(const {}).roles, isEmpty);
  });

  test('StudentProfile.fromJson coerces numeric fields', () {
    final p = StudentProfile.fromJson({
      'uuid': 'u-1',
      'email': 's@x.test',
      'first_name': 'Sam',
      'last_name': 'Lee',
      'student_number': 'S-1',
      'program_id': 3,
      'enrollment_year': 2025,
      'roles': ['STUDENT'],
    });
    expect(p.studentNumber, 'S-1');
    expect(p.programId, 3);
    expect(p.enrollmentYear, 2025);
    expect(p.fullName, 'Sam Lee');
  });

  test('ScheduleEntry.fromJson maps a class meeting', () {
    final s = ScheduleEntry.fromJson({
      'course_id': 10,
      'class_id': 20,
      'room_id': 5,
      'day_of_week': 0,
      'day': 'Monday',
      'start_time': '09:00',
      'end_time': '11:00',
    });
    expect(s.day, 'Monday');
    expect(s.timeRange, '09:00 – 11:00');
  });

  test('AttendanceEntry.fromJson reads attendance_session_id and dates', () {
    final a = AttendanceEntry.fromJson({
      'attendance_session_id': 42,
      'course_id': 10,
      'class_id': 20,
      'status': 'PRESENT',
      'source': 'QR',
      'marked_at': '2026-01-01T09:05:00Z',
      'session_start_time': '2026-01-01T09:00:00Z',
      'session_status': 'CLOSED',
    });
    expect(a.sessionId, 42);
    expect(a.isPresent, isTrue);
    expect(a.markedAt, isNotNull);
    expect(a.sessionStartTime, isNotNull);
  });

  test('AttendanceEntry.fromJson tolerates missing optional fields', () {
    final a = AttendanceEntry.fromJson(const {});
    expect(a.status, 'WAITING');
    expect(a.source, 'SYSTEM');
    expect(a.markedAt, isNull);
  });
}
