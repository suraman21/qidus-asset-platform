import '../api/api_client.dart';
import '../storage/local_db.dart';

class SyncSummary {
  final int pulledAssets;
  final int pulledMembers;
  final int syncedOutbox;
  final int conflicts;
  final int failed;

  const SyncSummary({
    required this.pulledAssets,
    required this.pulledMembers,
    required this.syncedOutbox,
    required this.conflicts,
    required this.failed,
  });
}

class SyncEngine {
  final ApiClient api;
  final LocalDb db;

  SyncEngine({required this.api, required this.db});

  Future<SyncSummary> syncAll({String? deviceUuid}) async {
    int syncedOutbox = 0;
    int conflicts = 0;
    int failed = 0;

    // 1. Push pending & failed outbox items first with Idempotency-Key
    final pending = [
      ...await db.listOutbox(status: 'pending'),
      ...await db.listOutbox(status: 'failed'),
    ];
    for (final item in pending) {
      try {
        final res = await api.request(
          'POST',
          item.endpoint,
          body: item.payload,
          idempotencyKey: item.clientTxUuid,
          deviceUuid: deviceUuid,
        );
        if (item.actionType == 'event_tx' && (res['conflicts'] as int? ?? 0) > 0) {
          await db.updateOutboxStatus(item.id, 'conflict', error: 'Server flagged stock/eligibility conflict for review.');
          conflicts++;
        } else {
          await db.updateOutboxStatus(item.id, 'synced');
          syncedOutbox++;
        }
      } on ApiException catch (e) {
        if (e.statusCode >= 400 && e.statusCode < 500) {
          await db.updateOutboxStatus(item.id, 'conflict', error: e.message);
          conflicts++;
        } else {
          await db.updateOutboxStatus(item.id, 'failed', error: e.message);
          failed++;
        }
      } catch (e) {
        await db.updateOutboxStatus(item.id, 'failed', error: e.toString());
        failed++;
      }
    }

    // 2. Pull latest assets + incremental member cache + tombstones
    int pulledAssets = 0;
    int pulledMembers = 0;
    try {
      final assetsResp = await api.request('GET', '/assets?limit=100');
      final items = (assetsResp['items'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
      await db.upsertAssets(items);
      pulledAssets = items.length;

      final since = await db.getMeta('members_sync_since');
      final memUrl = since == null ? '/members/sync?limit=200' : '/members/sync?since=${Uri.encodeComponent(since)}&limit=200';
      final memResp = await api.request('GET', memUrl);
      final members = (memResp['items'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
      final tombstones = (memResp['tombstones'] as List<dynamic>? ?? []).map((e) => e.toString()).toList();
      await db.upsertMembers(members, tombstones);
      if (memResp['next_cursor'] is String) {
        await db.setMeta('members_sync_since', memResp['next_cursor'] as String);
      }
      pulledMembers = members.length;

      final eventsResp = await api.request('GET', '/events');
      final events = (eventsResp['events'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
      for (final ev in events.take(5)) {
        final pkg = await api.request('GET', '/events/${ev['id']}/package');
        await db.saveEventPackage(pkg);
      }
      await db.setMeta('last_sync_utc', DateTime.now().toUtc().toIso8601String());
    } catch (_) {
      // Offline or partial pull; local cache remains intact
    }

    return SyncSummary(
      pulledAssets: pulledAssets,
      pulledMembers: pulledMembers,
      syncedOutbox: syncedOutbox,
      conflicts: conflicts,
      failed: failed,
    );
  }
}
