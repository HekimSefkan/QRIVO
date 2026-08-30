/// The authenticated account, from `POST /api/v1/auth/login` / `GET /auth/me`.
class User {
  const User({
    required this.uuid,
    required this.email,
    required this.firstName,
    required this.lastName,
    required this.roles,
  });

  final String uuid;
  final String email;
  final String firstName;
  final String lastName;
  final List<String> roles;

  String get fullName => '$firstName $lastName'.trim();
  bool get isStudent => roles.contains('STUDENT');

  factory User.fromJson(Map<String, dynamic> json) => User(
        uuid: json['uuid'] as String? ?? '',
        email: json['email'] as String? ?? '',
        firstName: json['first_name'] as String? ?? '',
        lastName: json['last_name'] as String? ?? '',
        roles: (json['roles'] as List<dynamic>? ?? const [])
            .map((e) => '$e')
            .toList(growable: false),
      );
}
