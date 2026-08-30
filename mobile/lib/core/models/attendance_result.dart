/// `POST /api/v1/student/attendance/verify` success body
/// (ATTENDANCE_ALGORITHM.md §4 — "attendance result").
///
/// The server decided the outcome; the app only displays it.
class AttendanceResult {
  const AttendanceResult({
    required this.status,
    required this.source,
    required this.riskLevel,
    required this.riskOutcome,
    this.sessionUuid,
    this.markedAt,
  });

  /// PRESENT · PENDING_REVIEW · LATE · … (server-assigned).
  final String status;

  /// Always `QR` for this flow.
  final String source;

  /// LOW · MEDIUM · HIGH · BLOCKED — informational only.
  final String riskLevel;
  final String riskOutcome;

  final String? sessionUuid;
  final DateTime? markedAt;

  bool get isPresent => status == 'PRESENT';
  bool get needsReview => status == 'PENDING_REVIEW';

  factory AttendanceResult.fromJson(Map<String, dynamic> json) {
    final risk = json['risk'] as Map<String, dynamic>? ?? const {};
    return AttendanceResult(
      status: json['status'] as String? ?? 'PENDING_REVIEW',
      source: json['source'] as String? ?? 'QR',
      sessionUuid: json['session_uuid'] as String?,
      markedAt: DateTime.tryParse(json['marked_at'] as String? ?? ''),
      riskLevel: risk['level'] as String? ?? 'LOW',
      riskOutcome: risk['outcome'] as String? ?? '',
    );
  }
}
