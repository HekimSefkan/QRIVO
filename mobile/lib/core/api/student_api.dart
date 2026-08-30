import '../models/attendance_entry.dart';
import '../models/schedule_entry.dart';
import '../models/student_profile.dart';
import 'api_client.dart';

class Dashboard {
  const Dashboard({
    required this.profile,
    required this.todaySchedule,
    required this.attendanceSummary,
    required this.recentAttendance,
  });

  final StudentProfile profile;
  final List<ScheduleEntry> todaySchedule;
  final Map<String, int> attendanceSummary;
  final List<AttendanceEntry> recentAttendance;
}

class AttendanceHistoryPage {
  const AttendanceHistoryPage({
    required this.entries,
    required this.page,
    required this.totalPages,
    required this.total,
  });

  final List<AttendanceEntry> entries;
  final int page;
  final int totalPages;
  final int total;

  bool get hasMore => page < totalPages;
}

/// Typed wrapper over the student self-service endpoints.
class StudentApi {
  StudentApi(this._client);

  final ApiClient _client;

  Future<StudentProfile> profile() async {
    final res = await _client.get('/student/profile');
    return StudentProfile.fromJson(res.object);
  }

  Future<List<ScheduleEntry>> schedule() async {
    final res = await _client.get('/student/schedule');
    return (res.object['schedule'] as List<dynamic>? ?? const [])
        .map((e) => ScheduleEntry.fromJson(e as Map<String, dynamic>))
        .toList(growable: false);
  }

  Future<AttendanceHistoryPage> attendanceHistory({int page = 1, int perPage = 20}) async {
    final res = await _client.get(
      '/student/attendance/history',
      query: {'page': page, 'per_page': perPage},
    );
    final meta = res.meta ?? const {};
    return AttendanceHistoryPage(
      entries: res.list
          .map((e) => AttendanceEntry.fromJson(e as Map<String, dynamic>))
          .toList(growable: false),
      page: (meta['page'] as num?)?.toInt() ?? page,
      totalPages: (meta['total_pages'] as num?)?.toInt() ?? 1,
      total: (meta['total'] as num?)?.toInt() ?? 0,
    );
  }

  Future<Dashboard> dashboard() async {
    final res = await _client.get('/student/dashboard');
    final o = res.object;
    return Dashboard(
      profile: StudentProfile.fromJson(
        o['profile'] as Map<String, dynamic>? ?? const {},
      ),
      todaySchedule: (o['today_schedule'] as List<dynamic>? ?? const [])
          .map((e) => ScheduleEntry.fromJson(e as Map<String, dynamic>))
          .toList(growable: false),
      attendanceSummary:
          (o['attendance_summary'] as Map<String, dynamic>? ?? const {}).map(
        (k, v) => MapEntry(k, (v as num).toInt()),
      ),
      recentAttendance: (o['recent_attendance'] as List<dynamic>? ?? const [])
          .map((e) => AttendanceEntry.fromJson(e as Map<String, dynamic>))
          .toList(growable: false),
    );
  }
}
