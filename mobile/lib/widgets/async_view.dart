import 'package:flutter/material.dart';

import '../core/api/api_exception.dart';

/// Renders the loading / error / data states of a one-shot [Future] and offers
/// a retry. Keeps every screen's boilerplate in one place.
class AsyncView<T> extends StatefulWidget {
  const AsyncView({
    super.key,
    required this.load,
    required this.builder,
  });

  final Future<T> Function() load;
  final Widget Function(BuildContext context, T data) builder;

  @override
  State<AsyncView<T>> createState() => _AsyncViewState<T>();
}

class _AsyncViewState<T> extends State<AsyncView<T>> {
  late Future<T> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.load();
  }

  void _retry() => setState(() => _future = widget.load());

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () async => _retry(),
      child: FutureBuilder<T>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final error = snapshot.error;
            final message = error is ApiException
                ? error.message
                : 'Something went wrong.';
            return ListView(
              children: [
                const SizedBox(height: 120),
                Icon(Icons.cloud_off, size: 48, color: Theme.of(context).disabledColor),
                const SizedBox(height: 12),
                Center(child: Text(message, textAlign: TextAlign.center)),
                const SizedBox(height: 12),
                Center(
                  child: OutlinedButton(onPressed: _retry, child: const Text('Retry')),
                ),
              ],
            );
          }
          return widget.builder(context, snapshot.data as T);
        },
      ),
    );
  }
}
