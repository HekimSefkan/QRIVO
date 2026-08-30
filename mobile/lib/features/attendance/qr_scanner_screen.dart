import 'dart:async';

import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:provider/provider.dart';

import '../../core/api/student_api.dart';
import 'attendance_result_sheet.dart';
import 'qr_attendance_controller.dart';

/// Camera screen for the student QR attendance flow.
///
/// The camera and barcode decoding live here; everything after a code is
/// decoded is handled by [QrAttendanceController], which talks only to the
/// authoritative backend. This widget makes no attendance decision.
class QrScannerScreen extends StatefulWidget {
  const QrScannerScreen({super.key});

  static Route<void> route() =>
      MaterialPageRoute<void>(builder: (_) => const QrScannerScreen());

  @override
  State<QrScannerScreen> createState() => _QrScannerScreenState();
}

class _QrScannerScreenState extends State<QrScannerScreen> {
  // autoStart (default) brings the camera up; we only drive stop()/start()
  // around the verification pipeline.
  final MobileScannerController _scanner = MobileScannerController(
    detectionSpeed: DetectionSpeed.noDuplicates,
    formats: const [BarcodeFormat.qrCode],
  );
  late final QrAttendanceController _attendance;

  @override
  void initState() {
    super.initState();
    _attendance = QrAttendanceController(context.read<StudentApi>())
      ..addListener(_onFlowChanged);
  }

  @override
  void dispose() {
    _attendance.removeListener(_onFlowChanged);
    _attendance.dispose();
    _scanner.dispose();
    super.dispose();
  }

  void _onFlowChanged() {
    // Pause the camera as soon as the pipeline starts; resume only on retry.
    if (_attendance.stage != QrFlowStage.idle) {
      unawaited(_scanner.stop());
    }
    if (mounted) setState(() {});
  }

  void _onDetect(BarcodeCapture capture) {
    if (_attendance.isBusy || _attendance.stage != QrFlowStage.idle) return;
    final raw = capture.barcodes
        .map((b) => b.rawValue)
        .firstWhere((v) => v != null && v.isNotEmpty, orElse: () => null);
    if (raw == null) return;
    unawaited(_attendance.submit(raw));
  }

  void _retry() {
    _attendance.reset();
    unawaited(_scanner.start());
  }

  void _done() {
    if (mounted) Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final showSheet = _attendance.stage != QrFlowStage.idle;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Scan attendance code'),
        actions: [
          IconButton(
            tooltip: 'Torch',
            icon: const Icon(Icons.flashlight_on_outlined),
            onPressed: () => unawaited(_scanner.toggleTorch()),
          ),
        ],
      ),
      body: Stack(
        children: [
          MobileScanner(
            controller: _scanner,
            onDetect: _onDetect,
            errorBuilder: (context, error, _) => _CameraError(error: error),
          ),
          const _ReticleOverlay(),
          if (!showSheet)
            const Positioned(
              left: 0,
              right: 0,
              bottom: 32,
              child: _Hint(),
            ),
          if (showSheet)
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              child: Material(
                elevation: 8,
                color: Theme.of(context).colorScheme.surface,
                borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
                child: AttendanceResultSheet(
                  controller: _attendance,
                  onRetry: _retry,
                  onDone: _done,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _Hint extends StatelessWidget {
  const _Hint();

  @override
  Widget build(BuildContext context) => const Center(
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: Colors.black54,
            borderRadius: BorderRadius.all(Radius.circular(8)),
          ),
          child: Padding(
            padding: EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Text(
              'Point at the code on the screen',
              style: TextStyle(color: Colors.white),
            ),
          ),
        ),
      );
}

class _ReticleOverlay extends StatelessWidget {
  const _ReticleOverlay();

  @override
  Widget build(BuildContext context) => Center(
        child: Container(
          width: 240,
          height: 240,
          decoration: BoxDecoration(
            border: Border.all(color: Colors.white70, width: 3),
            borderRadius: BorderRadius.circular(16),
          ),
        ),
      );
}

class _CameraError extends StatelessWidget {
  const _CameraError({required this.error});
  final MobileScannerException error;

  @override
  Widget build(BuildContext context) {
    final denied = error.errorCode == MobileScannerErrorCode.permissionDenied;
    return ColoredBox(
      color: Colors.black,
      child: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.no_photography_outlined, color: Colors.white70, size: 48),
              const SizedBox(height: 16),
              Text(
                denied
                    ? 'Camera access is off. Enable it in Settings to scan the attendance code.'
                    : 'The camera is unavailable right now.',
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.white),
              ),
              const SizedBox(height: 20),
              OutlinedButton(
                onPressed: () => Navigator.of(context).maybePop(),
                child: const Text('Go back'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
