import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/testing.dart';
import 'package:qrivo_mobile/core/api/api_client.dart';
import 'package:qrivo_mobile/core/api/student_api.dart';
import 'package:qrivo_mobile/core/models/attendance_result.dart';
import 'package:qrivo_mobile/features/attendance/attendance_failure.dart';
import 'package:qrivo_mobile/features/attendance/attendance_result_sheet.dart';
import 'package:qrivo_mobile/features/attendance/qr_attendance_controller.dart';

final _unusedApi = StudentApi(
  ApiClient(
    httpClient: MockClient((_) async => throw StateError('not used')),
    tokenProvider: () async => null,
  ),
);

class _FakeController extends QrAttendanceController {
  _FakeController() : super(_unusedApi);

  QrFlowStage _stage = QrFlowStage.validating;
  AttendanceResult? _result;
  AttendanceFailure? _failure;

  @override
  QrFlowStage get stage => _stage;
  @override
  AttendanceResult? get result => _result;
  @override
  AttendanceFailure? get failure => _failure;

  void show(QrFlowStage s, {AttendanceResult? result, AttendanceFailure? failure}) {
    _stage = s;
    _result = result;
    _failure = failure;
    notifyListeners();
  }
}

Future<void> _pump(WidgetTester tester, _FakeController c) => tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: AttendanceResultSheet(
            controller: c,
            onRetry: () => c.show(QrFlowStage.idle),
            onDone: () {},
          ),
        ),
      ),
    );

void main() {
  testWidgets('shows progress while the pipeline runs', (tester) async {
    final c = _FakeController()..show(QrFlowStage.verifying);
    await _pump(tester, c);
    expect(find.byKey(const Key('attendance_progress')), findsOneWidget);
    expect(find.text('Verifying with the server…'), findsOneWidget);
  });

  testWidgets('shows a PRESENT success with a Done button', (tester) async {
    final c = _FakeController()
      ..show(
        QrFlowStage.success,
        result: AttendanceResult.fromJson(const {
          'status': 'PRESENT',
          'source': 'QR',
          'risk': {'level': 'LOW', 'outcome': 'PRESENT'},
        }),
      );
    await _pump(tester, c);
    expect(find.byKey(const Key('attendance_success')), findsOneWidget);
    expect(find.textContaining('PRESENT'), findsOneWidget);
    expect(find.byKey(const Key('attendance_done')), findsOneWidget);
  });

  testWidgets('review outcome is worded as pending, not PRESENT', (tester) async {
    final c = _FakeController()
      ..show(
        QrFlowStage.success,
        result: AttendanceResult.fromJson(const {
          'status': 'PENDING_REVIEW',
          'source': 'QR',
          'risk': {'level': 'HIGH', 'outcome': 'PENDING_REVIEW'},
        }),
      );
    await _pump(tester, c);
    expect(find.text('Submitted for review'), findsOneWidget);
    expect(find.textContaining('PRESENT'), findsNothing);
  });

  testWidgets('retryable failure shows Try again + Close', (tester) async {
    final c = _FakeController()
      ..show(
        QrFlowStage.failure,
        failure: const AttendanceFailure(
          kind: QrFailureKind.network,
          message: 'Could not reach the server.',
        ),
      );
    await _pump(tester, c);
    expect(find.byKey(const Key('attendance_failure_message')), findsOneWidget);
    expect(find.byKey(const Key('attendance_retry')), findsOneWidget);
    expect(find.byKey(const Key('attendance_close')), findsOneWidget);
  });

  testWidgets('non-retryable failure hides Try again', (tester) async {
    final c = _FakeController()
      ..show(
        QrFlowStage.failure,
        failure: const AttendanceFailure(
          kind: QrFailureKind.blocked,
          message: 'Attendance could not be recorded.',
          retryable: false,
        ),
      );
    await _pump(tester, c);
    expect(find.byKey(const Key('attendance_retry')), findsNothing);
    expect(find.byKey(const Key('attendance_close')), findsOneWidget);
  });
}
