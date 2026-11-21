import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:qidus_asset_app/core/security/keystore_channel.dart';
import 'package:qidus_asset_app/core/storage/local_db.dart';

void main() {
  test('buildCanonicalPayload v2 produces deterministic JSON key order with member binding', () {
    final p1 = KeystoreChannel.buildCanonicalPayload(
      assetId: 42,
      quantity: 2,
      handoverType: 'issue',
      receiverName: 'Abebe Kebede',
      clientTxUuid: 'uuid-123',
      deviceUuid: 'dev-1',
      actorUserId: 7,
      externalMemberId: 'SSMS-1042',
      variantId: 3,
      eventId: 5,
      stationId: 2,
      leaseJti: 'lease-abc',
      deviceTxAt: '2026-11-21T10:00:00Z',
    );
    final decoded = jsonDecode(p1) as Map<String, dynamic>;
    expect(decoded['v'], 2);
    expect(decoded['asset_id'], 42);
    expect(decoded['external_member_id'], 'SSMS-1042');
    expect(decoded['client_tx_uuid'], 'uuid-123');
    expect(decoded.keys.toList(), [
      'actor_user_id',
      'asset_id',
      'client_tx_uuid',
      'device_tx_at',
      'device_uuid',
      'event_id',
      'external_member_id',
      'handover_type',
      'lease_jti',
      'quantity',
      'receiver_display_name',
      'station_id',
      'v',
      'variant_id',
    ]);
  });

  test('LocalOutboxItem parses persisted SQLite JSON payload cleanly', () {
    final item = LocalOutboxItem.fromRow({
      'id': 7,
      'client_tx_uuid': 'tx-777',
      'action_type': 'handover',
      'endpoint': '/handovers',
      'payload_json': jsonEncode({'asset_id': 9, 'quantity': 1}),
      'status': 'rejected',
      'attempts': 1,
      'last_error': '[422:VALIDATION_ERROR] Invalid member',
      'created_at': '2026-11-21T00:00:00Z',
    });
    expect(item.id, 7);
    expect(item.payload['asset_id'], 9);
    expect(item.status, 'rejected');
  });
}
