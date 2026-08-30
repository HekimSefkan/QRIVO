/// The defined failure states of the QR attendance flow
/// (ATTENDANCE_ALGORITHM.md §4 / §9).
///
/// The backend deliberately returns generic messages for security-sensitive
/// failures — the app never infers the specific security reason. [kind] exists
/// only to pick an icon and decide whether a "Try again" button makes sense.
enum QrFailureKind {
  /// Scanned barcode is not a QRIVO attendance code at all (local UX filter).
  notQrivoCode,

  /// Server rejected the code shape.
  invalidQr,

  /// QR is past its short TTL — a fresh one is already on screen.
  expiredQr,

  /// Signature mismatch — tampered or forged. Server's verdict.
  tamperedQr,

  /// No such session, or it is closed / cancelled.
  sessionUnavailable,

  /// Student is not enrolled in this course/class.
  notEnrolled,

  /// Attendance for this session was already recorded, or this QR/challenge was
  /// already used.
  alreadyRecorded,

  /// Too many attempts in the rate-limit window.
  rateLimited,

  /// The issued challenge expired before it was submitted.
  challengeExpired,

  /// Risk evaluation blocked the attempt (HIGH/critical). Server's decision.
  blocked,

  /// The session/token is no longer valid — the user must sign in again.
  notAuthenticated,

  /// Transport failure — no verdict from the server.
  network,

  /// Anything else.
  unknown,
}

class AttendanceFailure {
  const AttendanceFailure({
    required this.kind,
    required this.message,
    this.retryable = true,
  });

  final QrFailureKind kind;

  /// Safe to show as-is — either the server's generic message or a local string.
  final String message;

  /// Whether re-scanning could plausibly succeed.
  final bool retryable;
}
