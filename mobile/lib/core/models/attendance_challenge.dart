/// `POST /api/v1/student/attendance/challenge` — the server-issued challenge
/// (ATTENDANCE_ALGORITHM.md §4). The client does not derive anything from this;
/// it echoes [nonce] back with the scanned QR to `/verify`.
class AttendanceChallenge {
  const AttendanceChallenge({
    required this.challengeId,
    required this.nonce,
    this.expiresAt,
  });

  final String challengeId;
  final String nonce;
  final DateTime? expiresAt;

  factory AttendanceChallenge.fromJson(Map<String, dynamic> json) =>
      AttendanceChallenge(
        challengeId: json['challenge_id'] as String? ?? '',
        nonce: json['nonce'] as String? ?? '',
        expiresAt: DateTime.tryParse(json['expires_at'] as String? ?? ''),
      );
}
