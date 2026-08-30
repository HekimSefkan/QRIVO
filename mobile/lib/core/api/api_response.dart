/// A successful response from the QRIVO API envelope
/// `{ "success": true, "message": ..., "data": ..., "meta"?: ... }`.
class ApiResponse {
  const ApiResponse({required this.data, this.meta, this.message});

  /// The `data` field — an object or a list, depending on the endpoint.
  final Object? data;

  /// The `meta` field on paginated list responses.
  final Map<String, dynamic>? meta;

  final String? message;

  Map<String, dynamic> get object =>
      data is Map<String, dynamic> ? data as Map<String, dynamic> : const {};

  List<dynamic> get list => data is List ? data as List<dynamic> : const [];
}
