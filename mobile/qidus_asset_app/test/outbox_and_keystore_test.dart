import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:qidus_asset_app/core/security/keystore_channel.dart';
import 'package:qidus_asset_app/core/storage/local_db.dart';

void main() {
  test('buildCanonicalPayload produces deterministic JSON key order', () {
    final p1 = KeystoreChannel.buildCanonicalPayload(
      assetId: 42,
      quantity: 2,
      handoverType: 'issue',
      receiverName: 'Abebe Kebede',
      clientTxUuid: 'uuid-123',
      deviceUuid: 'dev-1',
    );
    final decoded = jsonDecode(p1) as Map<String, dynamic>;
    expect(decoded['asset_id'], 42);
    expect(decoded['quantity'], 2);
    expect(decoded['client_tx_uuid'], 'uuid-123');
    expect(decoded.keys.toList(), [
      'asset_id',
      'client_tx_uuid',
      'device_uuid',
      'handover_type',
      'quantity',
      'receiver_display_name',
    ]);
  });

  test('LocalOutboxItem parses persisted SQLite JSON payload cleanly', () {
    final item = LocalOutboxItem.fromRow({
      'id': 7,
      'client_tx_uuid': 'tx-777',
      'action_type': 'handover',
      'endpoint': '/handovers',
      'payload_json': jsonEncode({'asset_id': 9, 'quantity': 1}),
      'status': 'pending',
      'attempts': 0,
      'last_error': null,
      'created_at': '2026-11-21T00:00:00Z',
    });
    expect(item.id, 7);
    expect(item.payload['asset_id'], 9);
    expect(item.status, 'pending');
  });
}
