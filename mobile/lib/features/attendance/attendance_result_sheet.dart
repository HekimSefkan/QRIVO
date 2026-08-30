import 'package:flutter/material.dart';

import 'attendance_failure.dart';
import 'qr_attendance_controller.dart';

/// The panel shown over the scanner once a code has been picked up: a progress
/// view while the server pipeline runs, then the attendance result or a mapped
/// failure. Purely presentational — it reads [QrAttendanceController] state.
class AttendanceResultSheet extends StatelessWidget {
  const AttendanceResultSheet({
    super.key,
    required this.controller,
    required this.onRetry,
    required this.onDone,
  });

  final QrAttendanceController controller;
  final VoidCallback onRetry;
  final VoidCallback onDone;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            switch (controller.stage) {
              QrFlowStage.success => _Success(controller: controller, onDone: onDone),
              QrFlowStage.failure => _Failure(
                  failure: controller.failure!,
                  onRetry: onRetry,
                  onDone: onDone,
                ),
              _ => _Progress(stage: controller.stage),
            },
          ],
        ),
      ),
    );
  }
}

class _Progress extends StatelessWidget {
  const _Progress({required this.stage});
  final QrFlowStage stage;

  @override
  Widget build(BuildContext context) {
    final label = switch (stage) {
      QrFlowStage.validating => 'Checking the code…',
      QrFlowStage.challenging => 'Requesting a challenge…',
      QrFlowStage.verifying => 'Verifying with the server…',
      _ => 'Working…',
    };
    return Column(
      key: const Key('attendance_progress'),
      mainAxisSize: MainAxisSize.min,
      children: [
        const SizedBox(height: 8),
        const CircularProgressIndicator(),
        const SizedBox(height: 20),
        Text(label, style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'The server decides the result.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
      ],
    );
  }
}

class _Success extends StatelessWidget {
  const _Success({required this.controller, required this.onDone});
  final QrAttendanceController controller;
  final VoidCallback onDone;

  @override
  Widget build(BuildContext context) {
    final result = controller.result!;
    final review = result.needsReview;
    final color = review ? Colors.orange : Colors.green;

    return Column(
      key: const Key('attendance_success'),
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(review ? Icons.hourglass_top : Icons.check_circle, size: 64, color: color),
        const SizedBox(height: 16),
        Text(
          review ? 'Submitted for review' : 'You are marked ${result.status}',
          style: Theme.of(context).textTheme.titleLarge,
          textAlign: TextAlign.center,
        ),
        const SizedBox(height: 8),
        Text(
          review
              ? 'Your teacher will confirm this attendance.'
              : 'Recorded via QR. No further action needed.',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.bodyMedium,
        ),
        const SizedBox(height: 24),
        FilledButton(
          key: const Key('attendance_done'),
          onPressed: onDone,
          child: const Text('Done'),
        ),
      ],
    );
  }
}

class _Failure extends StatelessWidget {
  const _Failure({
    required this.failure,
    required this.onRetry,
    required this.onDone,
  });

  final AttendanceFailure failure;
  final VoidCallback onRetry;
  final VoidCallback onDone;

  IconData get _icon => switch (failure.kind) {
        QrFailureKind.network => Icons.wifi_off,
        QrFailureKind.rateLimited => Icons.timer_outlined,
        QrFailureKind.blocked => Icons.block,
        QrFailureKind.notAuthenticated => Icons.lock_outline,
        QrFailureKind.alreadyRecorded => Icons.done_all,
        _ => Icons.error_outline,
      };

  @override
  Widget build(BuildContext context) {
    return Column(
      key: const Key('attendance_failure'),
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(_icon, size: 64, color: Theme.of(context).colorScheme.error),
        const SizedBox(height: 16),
        Text(
          failure.message,
          key: const Key('attendance_failure_message'),
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.titleMedium,
        ),
        const SizedBox(height: 24),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            if (failure.retryable) ...[
              FilledButton(
                key: const Key('attendance_retry'),
                onPressed: onRetry,
                child: const Text('Try again'),
              ),
              const SizedBox(width: 12),
            ],
            OutlinedButton(
              key: const Key('attendance_close'),
              onPressed: onDone,
              child: const Text('Close'),
            ),
          ],
        ),
      ],
    );
  }
}
