/// `GET /api/v1/student/profile`
class StudentProfile {
  const StudentProfile({
    required this.uuid,
    required this.email,
    required this.firstName,
    required this.lastName,
    required this.studentNumber,
    required this.programId,
    required this.enrollmentYear,
    required this.roles,
  });

  final String uuid;
  final String email;
  final String firstName;
  final String lastName;
  final String studentNumber;
  final int programId;
  final int enrollmentYear;
  final List<String> roles;

  String get fullName => '$firstName $lastName'.trim();

  factory StudentProfile.fromJson(Map<String, dynamic> json) => StudentProfile(
        uuid: json['uuid'] as String? ?? '',
        email: json['email'] as String? ?? '',
        firstName: json['first_name'] as String? ?? '',
        lastName: json['last_name'] as String? ?? '',
        studentNumber: json['student_number'] as String? ?? '',
        programId: (json['program_id'] as num?)?.toInt() ?? 0,
        enrollmentYear: (json['enrollment_year'] as num?)?.toInt() ?? 0,
        roles: (json['roles'] as List<dynamic>? ?? const [])
            .map((e) => '$e')
            .toList(growable: false),
      );
}
