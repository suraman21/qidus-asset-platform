import 'dart:convert';

import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

class LocalOutboxItem {
  final int id;
  final String clientTxUuid;
  final String actionType; // handover | movement | event_tx | inspection_scan
  final String endpoint;
  final Map<String, dynamic> payload;
  final String status; // pending | synced | conflict | failed
  final int attempts;
  final String? lastError;
  final String createdAt;

  const LocalOutboxItem({
    required this.id,
    required this.clientTxUuid,
    required this.actionType,
    required this.endpoint,
    required this.payload,
    required this.status,
    required this.attempts,
    required this.lastError,
    required this.createdAt,
  });

  factory LocalOutboxItem.fromRow(Map<String, dynamic> row) {
    return LocalOutboxItem(
      id: row['id'] as int,
      clientTxUuid: row['client_tx_uuid'] as String,
      actionType: row['action_type'] as String,
      endpoint: row['endpoint'] as String,
      payload: jsonDecode(row['payload_json'] as String) as Map<String, dynamic>,
      status: row['status'] as String,
      attempts: row['attempts'] as int,
      lastError: row['last_error'] as String?,
      createdAt: row['created_at'] as String,
    );
  }
}

class LocalDb {
  LocalDb._();
  static final LocalDb instance = LocalDb._();

  Database? _db;

  Future<Database> get database async {
    if (_db != null) return _db!;
    final dbPath = await getDatabasesPath();
    final fullPath = p.join(dbPath, 'qidus_asset_offline.db');
    _db = await openDatabase(
      fullPath,
      version: 1,
      onCreate: (db, version) async {
        await db.execute('''
          CREATE TABLE local_assets (
            id INTEGER PRIMARY KEY,
            asset_code TEXT NOT NULL,
            name_en TEXT NOT NULL,
            name_am TEXT,
            tracking_mode TEXT NOT NULL,
            qr_token TEXT NOT NULL UNIQUE,
            quantity INTEGER NOT NULL DEFAULT 0,
            min_quantity INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL,
            category_name TEXT,
            location_name TEXT,
            updated_at TEXT NOT NULL
          )
        ''');

        await db.execute('''
          CREATE TABLE local_members (
            id INTEGER PRIMARY KEY,
            external_member_id TEXT NOT NULL UNIQUE,
            member_code TEXT,
            name_en TEXT NOT NULL,
            name_am TEXT,
            section_name TEXT,
            clothing_size TEXT,
            shoe_size TEXT,
            is_tombstoned INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL
          )
        ''');

        await db.execute('''
          CREATE TABLE local_events (
            id INTEGER PRIMARY KEY,
            event_code TEXT NOT NULL,
            name_en TEXT NOT NULL,
            event_date TEXT NOT NULL,
            status TEXT NOT NULL
          )
        ''');

        await db.execute('''
          CREATE TABLE local_event_stations (
            id INTEGER PRIMARY KEY,
            event_id INTEGER NOT NULL,
            station_code TEXT NOT NULL,
            station_name TEXT NOT NULL
          )
        ''');

        await db.execute('''
          CREATE TABLE local_event_allocations (
            id INTEGER PRIMARY KEY,
            event_id INTEGER NOT NULL,
            station_id INTEGER NOT NULL,
            asset_id INTEGER NOT NULL,
            asset_code TEXT NOT NULL,
            asset_name TEXT NOT NULL,
            allocated_qty INTEGER NOT NULL,
            issued_qty INTEGER NOT NULL DEFAULT 0,
            returned_qty INTEGER NOT NULL DEFAULT 0
          )
        ''');

        await db.execute('''
          CREATE TABLE local_outbox (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_tx_uuid TEXT NOT NULL UNIQUE,
            action_type TEXT NOT NULL,
            endpoint TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error TEXT,
            created_at TEXT NOT NULL
          )
        ''');

        await db.execute('''
          CREATE TABLE local_sync_meta (
            meta_key TEXT PRIMARY KEY,
            meta_value TEXT NOT NULL
          )
        ''');
      },
    );
    return _db!;
  }

  Future<void> upsertAssets(List<Map<String, dynamic>> items) async {
    final db = await database;
    final batch = db.batch();
    final now = DateTime.now().toUtc().toIso8601String();
    for (final a in items) {
      batch.insert(
        'local_assets',
        {
          'id': a['id'],
          'asset_code': a['asset_code'] ?? '',
          'name_en': a['name_en'] ?? '',
          'name_am': a['name_am'],
          'tracking_mode': a['tracking_mode'] ?? 'fixed',
          'qr_token': a['qr_token'] ?? '',
          'quantity': a['quantity'] ?? 0,
          'min_quantity': a['min_quantity'] ?? 0,
          'status': a['status'] ?? 'in_stock',
          'category_name': a['category_name'],
          'location_name': a['location_name'],
          'updated_at': now,
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    }
    await batch.commit(noResult: true);
  }

  Future<Map<String, dynamic>?> findAssetByQr(String qrToken) async {
    final db = await database;
    final rows = await db.query('local_assets', where: 'qr_token = ?', whereArgs: [qrToken], limit: 1);
    return rows.isEmpty ? null : rows.first;
  }

  Future<List<Map<String, dynamic>>> listAssets({String search = ''}) async {
    final db = await database;
    if (search.trim().isEmpty) {
      return db.query('local_assets', orderBy: 'id DESC', limit: 200);
    }
    final q = '%${search.trim()}%';
    return db.query(
      'local_assets',
      where: 'asset_code LIKE ? OR name_en LIKE ? OR qr_token = ?',
      whereArgs: [q, q, search.trim()],
      limit: 100,
    );
  }

  Future<void> adjustLocalAssetQty(int assetId, int delta) async {
    final db = await database;
    await db.rawUpdate(
      'UPDATE local_assets SET quantity = MAX(0, quantity + ?) WHERE id = ?',
      [delta, assetId],
    );
  }

  Future<void> upsertMembers(List<Map<String, dynamic>> members, List<String> tombstones) async {
    final db = await database;
    final batch = db.batch();
    final now = DateTime.now().toUtc().toIso8601String();
    for (final m in members) {
      batch.insert(
        'local_members',
        {
          'id': m['id'],
          'external_member_id': m['external_member_id'],
          'member_code': m['member_code'],
          'name_en': m['name_en'] ?? '',
          'name_am': m['name_am'],
          'section_name': m['section_name'],
          'clothing_size': m['clothing_size'],
          'shoe_size': m['shoe_size'],
          'is_tombstoned': (m['is_tombstoned'] == true || m['is_tombstoned'] == 1) ? 1 : 0,
          'updated_at': now,
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
    }
    for (final extId in tombstones) {
      batch.update('local_members', {'is_tombstoned': 1}, where: 'external_member_id = ?', whereArgs: [extId]);
    }
    await batch.commit(noResult: true);
  }

  Future<List<Map<String, dynamic>>> searchMembers(String query) async {
    final db = await database;
    if (query.trim().isEmpty) {
      return db.query('local_members', where: 'is_tombstoned = 0', orderBy: 'name_en ASC', limit: 50);
    }
    final q = '%${query.trim()}%';
    return db.query(
      'local_members',
      where: 'is_tombstoned = 0 AND (name_en LIKE ? OR member_code LIKE ? OR external_member_id LIKE ?)',
      whereArgs: [q, q, q],
      orderBy: 'name_en ASC',
      limit: 50,
    );
  }

  Future<void> saveEventPackage(Map<String, dynamic> pkg) async {
    final db = await database;
    final ev = pkg['event'] as Map<String, dynamic>;
    final stations = (pkg['stations'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
    final allocations = (pkg['allocations'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();

    await db.transaction((txn) async {
      await txn.insert(
        'local_events',
        {
          'id': ev['id'],
          'event_code': ev['event_code'] ?? '',
          'name_en': ev['name_en'] ?? '',
          'event_date': ev['event_date'] ?? '',
          'status': ev['status'] ?? 'active',
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
      await txn.delete('local_event_stations', where: 'event_id = ?', whereArgs: [ev['id']]);
      for (final st in stations) {
        await txn.insert('local_event_stations', {
          'id': st['id'],
          'event_id': ev['id'],
          'station_code': st['station_code'] ?? '',
          'station_name': st['station_name'] ?? '',
        });
      }
      await txn.delete('local_event_allocations', where: 'event_id = ?', whereArgs: [ev['id']]);
      for (final al in allocations) {
        await txn.insert('local_event_allocations', {
          'id': al['id'],
          'event_id': ev['id'],
          'station_id': al['station_id'],
          'asset_id': al['asset_id'],
          'asset_code': al['asset_code'] ?? '',
          'asset_name': al['asset_name'] ?? '',
          'allocated_qty': al['allocated_qty'] ?? 0,
          'issued_qty': al['issued_qty'] ?? 0,
          'returned_qty': al['returned_qty'] ?? 0,
        });
      }
    });
  }

  Future<List<Map<String, dynamic>>> listEvents() async {
    final db = await database;
    return db.query('local_events', orderBy: 'id DESC');
  }

  Future<List<Map<String, dynamic>>> listStations(int eventId) async {
    final db = await database;
    return db.query('local_event_stations', where: 'event_id = ?', whereArgs: [eventId]);
  }

  Future<List<Map<String, dynamic>>> listStationAllocations(int eventId, int stationId) async {
    final db = await database;
    return db.query(
      'local_event_allocations',
      where: 'event_id = ? AND station_id = ?',
      whereArgs: [eventId, stationId],
    );
  }

  Future<void> bumpLocalAllocationCount(int allocationId, String txType, int qty) async {
    final db = await database;
    final col = txType == 'return' ? 'returned_qty' : 'issued_qty';
    await db.rawUpdate(
      'UPDATE local_event_allocations SET $col = $col + ? WHERE id = ?',
      [qty, allocationId],
    );
  }

  Future<void> enqueueOutbox({
    required String clientTxUuid,
    required String actionType,
    required String endpoint,
    required Map<String, dynamic> payload,
  }) async {
    final db = await database;
    await db.insert(
      'local_outbox',
      {
        'client_tx_uuid': clientTxUuid,
        'action_type': actionType,
        'endpoint': endpoint,
        'payload_json': jsonEncode(payload),
        'status': 'pending',
        'attempts': 0,
        'last_error': null,
        'created_at': DateTime.now().toUtc().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.ignore,
    );
  }

  Future<List<LocalOutboxItem>> listOutbox({String? status}) async {
    final db = await database;
    final rows = status == null
        ? await db.query('local_outbox', orderBy: 'id DESC', limit: 250)
        : await db.query('local_outbox', where: 'status = ?', whereArgs: [status], orderBy: 'id ASC', limit: 250);
    return rows.map(LocalOutboxItem.fromRow).toList();
  }

  Future<int> pendingOutboxCount() async {
    final db = await database;
    final res = await db.rawQuery("SELECT COUNT(*) AS cnt FROM local_outbox WHERE status IN ('pending','failed','conflict')");
    return Sqflite.firstIntValue(res) ?? 0;
  }

  Future<void> updateOutboxStatus(int id, String status, {String? error}) async {
    final db = await database;
    await db.rawUpdate(
      'UPDATE local_outbox SET status = ?, attempts = attempts + 1, last_error = ? WHERE id = ?',
      [status, error, id],
    );
  }

  Future<void> setMeta(String key, String value) async {
    final db = await database;
    await db.insert(
      'local_sync_meta',
      {'meta_key': key, 'meta_value': value},
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<String?> getMeta(String key) async {
    final db = await database;
    final rows = await db.query('local_sync_meta', where: 'meta_key = ?', whereArgs: [key], limit: 1);
    return rows.isEmpty ? null : rows.first['meta_value'] as String;
  }
}
