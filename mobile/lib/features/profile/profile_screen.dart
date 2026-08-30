import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api/student_api.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/models/student_profile.dart';
import '../../widgets/async_view.dart';

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final api = context.read<StudentApi>();
    return AsyncView<StudentProfile>(
      load: api.profile,
      builder: (context, p) => ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Center(
            child: CircleAvatar(
              radius: 36,
              child: Text(
                (p.firstName.isNotEmpty ? p.firstName[0] : '?').toUpperCase(),
                style: const TextStyle(fontSize: 28),
              ),
            ),
          ),
          const SizedBox(height: 16),
          Center(child: Text(p.fullName, style: Theme.of(context).textTheme.titleLarge)),
          const SizedBox(height: 24),
          _Field(label: 'Email', value: p.email),
          _Field(label: 'Student number', value: p.studentNumber),
          _Field(label: 'Program', value: 'Program ${p.programId}'),
          _Field(label: 'Enrollment year', value: '${p.enrollmentYear}'),
          _Field(label: 'Roles', value: p.roles.join(', ')),
          const SizedBox(height: 32),
          OutlinedButton.icon(
            key: const Key('profile_sign_out'),
            onPressed: () => context.read<AuthController>().signOut(),
            icon: const Icon(Icons.logout),
            label: const Text('Sign out'),
          ),
        ],
      ),
    );
  }
}

class _Field extends StatelessWidget {
  const _Field({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 140,
              child: Text(label, style: Theme.of(context).textTheme.bodySmall),
            ),
            Expanded(child: Text(value.isEmpty ? '—' : value)),
          ],
        ),
      );
}
