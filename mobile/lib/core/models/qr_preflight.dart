/// `POST /api/v1/student/attendance/qr/verify` — the non-consuming preflight
/// check (ATTENDANCE_ALGORITHM.md §4, "QR validation" step).
///
/// The server is authoritative: this is the server's verdict on a scanned code,
/// not a local decision. `valid == false` carries a coarse [reason] the client
/// turns into a friendly message; it never exposes signing details.
class QrPreflight {
  const QrPreflight({
    required this.valid,
    required this.reason,
    this.sessionUuid,
  });

  final bool valid;

  /// One of: VALID · MALFORMED · SESSION_NOT_FOUND · SESSION_NOT_ACTIVE ·
  /// WRONG_SESSION · EXPIRED · BAD_SIGNATURE · REPLAYED
  final String reason;
  final String? sessionUuid;

  factory QrPreflight.fromJson(Map<String, dynamic> json) => QrPreflight(
        valid: json['valid'] as bool? ?? false,
        reason: json['reason'] as String? ?? 'MALFORMED',
        sessionUuid: json['session_uuid'] as String?,
      );
}
