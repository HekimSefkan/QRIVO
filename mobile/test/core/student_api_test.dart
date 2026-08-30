import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:qrivo_mobile/core/api/api_client.dart';
import 'package:qrivo_mobile/core/api/student_api.dart';

StudentApi _api(Map<String, http.Response> routes) {
  final client = ApiClient(
    httpClient: MockClient((req) async {
      for (final entry in routes.entries) {
        if (req.url.path.endsWith(entry.key)) return entry.value;
      }
      return http.Response('{"message":"no route"}', 404);
    }),
    tokenProvider: () async => 'token',
  );
  return StudentApi(client);
}

http.Response _json(Object body) => http.Response(jsonEncode(body), 200);

void main() {
  group('StudentApi', () {
    test('profile()', () async {
      final api = _api({
        '/student/profile': _json({
          'data': {
            'uuid': 'u-1',
            'email': 's@x.test',
            'first_name': 'Sam',
            'last_name': 'Lee',
            'student_number': 'S-1',
            'program_id': 1,
            'enrollment_year': 2025,
            'roles': ['STUDENT'],
          },
        }),
      });

      final p = await api.profile();
      expect(p.studentNumber, 'S-1');
    });

    test('schedule() unwraps the schedule list', () async {
      final api = _api({
        '/student/schedule': _json({
          'data': {
            'schedule': [
              {
                'course_id': 1,
                'class_id': 2,
                'room_id': 3,
                'day_of_week': 0,
                'day': 'Monday',
                'start_time': '09:00',
                'end_time': '11:00',
              },
            ],
          },
        }),
      });

      final rows = await api.schedule();
      expect(rows, hasLength(1));
      expect(rows.first.day, 'Monday');
    });

    test('attendanceHistory() reads the list and meta', () async {
      final api = _api({
        '/student/attendance/history': _json({
          'data': [
            {
              'attendance_session_id': 1,
              'course_id': 1,
              'class_id': 2,
              'status': 'PRESENT',
              'source': 'QR',
              'session_status': 'CLOSED',
            },
          ],
          'meta': {'page': 1, 'per_page': 20, 'total': 1, 'total_pages': 1},
        }),
      });

      final page = await api.attendanceHistory();
      expect(page.entries, hasLength(1));
      expect(page.page, 1);
      expect(page.totalPages, 1);
      expect(page.hasMore, isFalse);
    });

    test('dashboard() aggregates all sections', () async {
      final api = _api({
        '/student/dashboard': _json({
          'data': {
            'profile': {
              'uuid': 'u-1',
              'email': 's@x.test',
              'first_name': 'Sam',
              'last_name': 'Lee',
              'student_number': 'S-1',
              'program_id': 1,
              'enrollment_year': 2025,
              'roles': ['STUDENT'],
            },
            'today_schedule': [
              {
                'course_id': 1,
                'class_id': 2,
                'room_id': 3,
                'day_of_week': 0,
                'day': 'Monday',
                'start_time': '09:00',
                'end_time': '11:00',
              },
            ],
            'attendance_summary': {'PRESENT': 3, 'ABSENT': 1},
            'recent_attendance': [
              {
                'attendance_session_id': 1,
                'course_id': 1,
                'class_id': 2,
                'status': 'PRESENT',
                'source': 'QR',
                'session_status': 'CLOSED',
              },
            ],
          },
        }),
      });

      final d = await api.dashboard();
      expect(d.profile.firstName, 'Sam');
      expect(d.todaySchedule, hasLength(1));
      expect(d.attendanceSummary['PRESENT'], 3);
      expect(d.recentAttendance.single.isPresent, isTrue);
    });
  });
}
