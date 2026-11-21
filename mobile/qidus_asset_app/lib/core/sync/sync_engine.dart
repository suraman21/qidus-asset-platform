import '../api/api_client.dart';
import '../storage/local_db.dart';

class SyncSummary {
  final int pulledAssets;
  final int pulledMembers;
  final int pulledEvents;
  final int syncedOutbox;
  final int conflicts;
  final int rejected;
  final int failed;

  const SyncSummary({
    required this.pulledAssets,
    required this.pulledMembers,
    required this.pulledEvents,
    required this.syncedOutbox,
    required this.conflicts,
    required this.rejected,
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
    int rejected = 0;
    int failed = 0;

    // 1. Push pending & retryable failed outbox items with Idempotency-Key.
    // Strict error classification: 409/conflicts -> needs_review, 4xx != 409 -> rejected (non-retryable), network/5xx -> failed.
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
          await db.updateOutboxStatus(item.id, 'needs_review', error: 'Server flagged stock or eligibility conflict for review.');
          conflicts++;
        } else {
          await db.updateOutboxStatus(item.id, 'synced');
          syncedOutbox++;
        }
      } on ApiException catch (e) {
        if (e.statusCode == 409) {
          await db.updateOutboxStatus(item.id, 'needs_review', error: '[${e.statusCode}] ${e.message}');
          conflicts++;
        } else if (e.statusCode >= 400 && e.statusCode < 500) {
          await db.updateOutboxStatus(item.id, 'rejected', error: '[${e.statusCode}:${e.code}] ${e.message}');
          rejected++;
        } else {
          await db.updateOutboxStatus(item.id, 'failed', error: '[${e.statusCode}] ${e.message}');
          failed++;
        }
      } catch (e) {
        await db.updateOutboxStatus(item.id, 'failed', error: e.toString());
        failed++;
      }
    }

    // 2. Pull ALL assets & ALL incremental member pages using cursor loops (no 100/200/5 truncation).
    int pulledAssets = 0;
    int pulledMembers = 0;
    int pulledEvents = 0;
    try {
      int afterId = 0;
      bool moreAssets = true;
      int safety = 0;
      while (moreAssets && safety < 200) {
        safety++;
        final assetsResp = await api.request('GET', '/assets?after_id=$afterId&limit=200');
        final items = (assetsResp['items'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
        if (items.isNotEmpty) {
          await db.upsertAssets(items);
          pulledAssets += items.length;
        }
        moreAssets = assetsResp['has_more'] == true;
        final nextCursor = assetsResp['next_cursor'];
        if (nextCursor is int && nextCursor > afterId) {
          afterId = nextCursor;
        } else {
          moreAssets = false;
        }
      }

      bool moreMembers = true;
      safety = 0;
      while (moreMembers && safety < 200) {
        safety++;
        final since = await db.getMeta('members_sync_since');
        final memUrl = since == null ? '/members/sync?limit=200' : '/members/sync?since=${Uri.encodeComponent(since)}&limit=200';
        final memResp = await api.request('GET', memUrl);
        final members = (memResp['items'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
        final tombstones = (memResp['tombstones'] as List<dynamic>? ?? []).map((e) => e.toString()).toList();
        if (members.isNotEmpty || tombstones.isNotEmpty) {
          await db.upsertMembers(members, tombstones);
          pulledMembers += members.length;
        }
        if (memResp['next_cursor'] is String && (memResp['next_cursor'] as String).isNotEmpty) {
          await db.setMeta('members_sync_since', memResp['next_cursor'] as String);
        }
        moreMembers = memResp['has_more'] == true && members.isNotEmpty;
      }

      final eventsResp = await api.request('GET', '/events');
      final events = (eventsResp['events'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
      for (final ev in events) {
        final pkg = await api.request('GET', '/events/${ev['id']}/package');
        await db.saveEventPackage(pkg);
        pulledEvents++;
      }
      await db.setMeta('last_sync_utc', DateTime.now().toUtc().toIso8601String());
    } catch (_) {
      // Offline or partial pull; local encrypted cache remains intact
    }

    return SyncSummary(
      pulledAssets: pulledAssets,
      pulledMembers: pulledMembers,
      pulledEvents: pulledEvents,
      syncedOutbox: syncedOutbox,
      conflicts: conflicts,
      rejected: rejected,
      failed: failed,
    );
  }
}
