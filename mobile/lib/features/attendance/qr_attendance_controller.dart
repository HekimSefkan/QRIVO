import 'package:flutter/foundation.dart';

import '../../core/api/api_exception.dart';
import '../../core/api/student_api.dart';
import '../../core/models/attendance_challenge.dart';
import '../../core/models/attendance_result.dart';
import 'attendance_failure.dart';

/// Where the pipeline is right now — drives the progress UI.
enum QrFlowStage {
  idle,
  validating, // preflight — "QR validation"
  challenging, // request challenge
  verifying, // submit challenge response + verification
  success,
  failure,
}

/// Runs the student QR attendance pipeline exactly in the order the algorithm
/// defines (ATTENDANCE_ALGORITHM.md §4):
///
///   scan → QR validation → challenge → challenge response → server verification
///   → attendance result
///
/// **No security decision is made here.** The single local check is a format
/// sniff that avoids firing the attendance API at a random barcode; every real
/// verdict (expiry, signature, enrolment, replay, risk, duplicates) comes from
/// the server. Failures are mapped to a [QrFailureKind] for presentation only.
class QrAttendanceController extends ChangeNotifier {
  QrAttendanceController(this._api);

  final StudentApi _api;

  QrFlowStage _stage = QrFlowStage.idle;
  AttendanceResult? _result;
  AttendanceFailure? _failure;
  bool _busy = false;

  QrFlowStage get stage => _stage;
  AttendanceResult? get result => _result;
  AttendanceFailure? get failure => _failure;

  /// True while a scan is being processed — the scanner must ignore new frames.
  bool get isBusy => _busy;

  /// Feed a raw decoded barcode string into the pipeline.
  Future<void> submit(String rawQr) async {
    if (_busy) return;
    _busy = true;
    _result = null;
    _failure = null;
    _setStage(QrFlowStage.validating);

    final qr = rawQr.trim();

    try {
      // Local UX filter only — not a security decision. The server still fully
      // validates anything that gets past this.
      if (!qr.startsWith('qrivo.')) {
        _fail(const AttendanceFailure(
          kind: QrFailureKind.notQrivoCode,
          message:
              'That is not a QRIVO attendance code. Point the camera at the code on the screen.',
        ),);
        return;
      }

      // Step: QR validation (non-consuming preflight).
      final pre = await _api.preflightQr(qr);
      if (!pre.valid) {
        _fail(_failureForReason(pre.reason));
        return;
      }

      // Step: challenge.
      _setStage(QrFlowStage.challenging);
      final AttendanceChallenge challenge = await _api.requestChallenge(qr);

      // Step: challenge response + server verification.
      _setStage(QrFlowStage.verifying);
      _result = await _api.submitAttendance(
        challengeId: challenge.challengeId,
        nonce: challenge.nonce,
        qr: qr,
      );

      _setStage(QrFlowStage.success);
    } on ApiException catch (e) {
      _fail(_failureForException(e));
    } catch (_) {
      _fail(const AttendanceFailure(
        kind: QrFailureKind.unknown,
        message: 'Something went wrong. Please try again.',
      ),);
    } finally {
      _busy = false;
      notifyListeners();
    }
  }

  /// Return to the scanner for another attempt.
  void reset() {
    if (_busy) return;
    _stage = QrFlowStage.idle;
    _result = null;
    _failure = null;
    notifyListeners();
  }

  // ─── internals ─────────────────────────────────────────────────────────────

  void _setStage(QrFlowStage stage) {
    _stage = stage;
    notifyListeners();
  }

  void _fail(AttendanceFailure failure) {
    _failure = failure;
    _stage = QrFlowStage.failure;
    notifyListeners();
  }

  /// Preflight verdict (`data.reason`) → presentation kind.
  AttendanceFailure _failureForReason(String reason) {
    switch (reason) {
      case 'EXPIRED':
        return const AttendanceFailure(
          kind: QrFailureKind.expiredQr,
          message: 'This code has expired. Scan the current one on the screen.',
        );
      case 'BAD_SIGNATURE':
        return const AttendanceFailure(
          kind: QrFailureKind.tamperedQr,
          message: 'This code could not be verified. Scan the code on the screen.',
        );
      case 'SESSION_NOT_FOUND':
      case 'SESSION_NOT_ACTIVE':
      case 'WRONG_SESSION':
        return const AttendanceFailure(
          kind: QrFailureKind.sessionUnavailable,
          message: 'This attendance session is not open.',
        );
      case 'REPLAYED':
        return const AttendanceFailure(
          kind: QrFailureKind.alreadyRecorded,
          message: 'This code has already been used.',
        );
      case 'MALFORMED':
      default:
        return const AttendanceFailure(
          kind: QrFailureKind.invalidQr,
          message: 'This code is not valid. Scan the code on the screen.',
        );
    }
  }

  /// Challenge / verify error → presentation kind. The server's own message is
  /// safe to surface; we only add [kind] and [retryable].
  AttendanceFailure _failureForException(ApiException e) {
    if (e.isNetworkError) {
      return AttendanceFailure(
        kind: QrFailureKind.network,
        message: e.message,
      );
    }
    switch (e.statusCode) {
      case 401:
        return const AttendanceFailure(
          kind: QrFailureKind.notAuthenticated,
          message: 'Your session has ended. Please sign in again.',
          retryable: false,
        );
      case 403:
        // Not enrolled, or risk-blocked — both are the server's final word.
        final blocked = e.message.toLowerCase().contains('could not be recorded');
        return AttendanceFailure(
          kind: blocked ? QrFailureKind.blocked : QrFailureKind.notEnrolled,
          message: e.message,
          retryable: false,
        );
      case 404:
        return AttendanceFailure(
          kind: QrFailureKind.sessionUnavailable,
          message: e.message,
        );
      case 409:
        final msg = e.message.toLowerCase();
        final kind = msg.contains('expired')
            ? QrFailureKind.challengeExpired
            : msg.contains('no longer open') || msg.contains('not open')
                ? QrFailureKind.sessionUnavailable
                : QrFailureKind.alreadyRecorded;
        return AttendanceFailure(
          kind: kind,
          message: e.message,
          retryable: kind != QrFailureKind.alreadyRecorded,
        );
      case 429:
        return AttendanceFailure(
          kind: QrFailureKind.rateLimited,
          message: e.message,
        );
      case 422:
        return const AttendanceFailure(
          kind: QrFailureKind.invalidQr,
          message: 'This code is not valid. Scan the code on the screen.',
        );
      default:
        return const AttendanceFailure(
          kind: QrFailureKind.unknown,
          message: 'Attendance could not be completed. Please try again.',
        );
    }
  }
}
