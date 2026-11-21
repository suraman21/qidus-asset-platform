import 'package:flutter/material.dart';
import 'package:uuid/uuid.dart';

import '../../core/api/api_client.dart';
import '../../core/storage/local_db.dart';

class RapidEventScreen extends StatefulWidget {
  final ApiClient api;
  final VoidCallback onQueued;

  const RapidEventScreen({super.key, required this.api, required this.onQueued});

  @override
  State<RapidEventScreen> createState() => _RapidEventScreenState();
}

class _RapidEventScreenState extends State<RapidEventScreen> {
  List<Map<String, dynamic>> _events = [];
  List<Map<String, dynamic>> _stations = [];
  List<Map<String, dynamic>> _allocations = [];
  List<Map<String, dynamic>> _members = [];

  int? _eventId;
  int? _stationId;
  int? _memberCacheId;
  String? _statusMsg;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final events = await LocalDb.instance.listEvents();
    final members = await LocalDb.instance.searchMembers('');
    if (!mounted) return;
    setState(() {
      _events = events;
      _members = members;
      if (events.isNotEmpty) _eventId ??= events.first['id'] as int;
      if (members.isNotEmpty) _memberCacheId ??= members.first['id'] as int;
    });
    if (_eventId != null) await _loadStations(_eventId!);
  }

  Future<void> _loadStations(int eventId) async {
    final stations = await LocalDb.instance.listStations(eventId);
    if (!mounted) return;
    setState(() {
      _stations = stations;
      _stationId = stations.isNotEmpty ? stations.first['id'] as int : null;
    });
    if (_stationId != null) await _loadAllocations(eventId, _stationId!);
  }

  Future<void> _loadAllocations(int eventId, int stationId) async {
    final alloc = await LocalDb.instance.listStationAllocations(eventId, stationId);
    if (!mounted) return;
    setState(() => _allocations = alloc);
  }

  Future<void> _rapidTx(Map<String, dynamic> alloc, String txType) async {
    if (_eventId == null || _stationId == null || _memberCacheId == null) return;
    final txUuid = const Uuid().v4();
    final txItem = {
      'client_tx_uuid': txUuid,
      'station_id': _stationId,
      'member_cache_id': _memberCacheId,
      'asset_id': alloc['asset_id'],
      'tx_type': txType,
      'quantity': 1,
      'device_tx_at': DateTime.now().toUtc().toIso8601String().substring(0, 19).replaceFirst('T', ' '),
    };

    // Zero-latency optimistic local update + durable outbox enqueue
    await LocalDb.instance.bumpLocalAllocationCount(alloc['id'] as int, txType, 1);
    await LocalDb.instance.enqueueOutbox(
      clientTxUuid: txUuid,
      actionType: 'event_tx',
      endpoint: '/events/$_eventId/sync-batch',
      payload: {'transactions': [txItem]},
    );
    widget.onQueued();
    await _loadAllocations(_eventId!, _stationId!);
    if (mounted) {
      setState(() => _statusMsg = 'Recorded $txType of ${alloc['asset_code']} locally (<50ms) & queued for batch sync.');
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Text('Rapid Multi-Station Event Mode', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                const SizedBox(height: 10),
                if (_events.isEmpty)
                  const Text('No downloaded event packages yet. Tap Sync while online to download active event packs.')
                else ...[
                  DropdownButtonFormField<int>(
                    value: _eventId,
                    decoration: const InputDecoration(labelText: 'Event', border: OutlineInputBorder(), isDense: true),
                    items: _events
                        .map((e) => DropdownMenuItem<int>(value: e['id'] as int, child: Text('${e['event_code']} — ${e['name_en']}')))
                        .toList(),
                    onChanged: (v) async {
                      setState(() => _eventId = v);
                      if (v != null) await _loadStations(v);
                    },
                  ),
                  const SizedBox(height: 10),
                  DropdownButtonFormField<int>(
                    value: _stationId,
                    decoration: const InputDecoration(labelText: 'Station', border: OutlineInputBorder(), isDense: true),
                    items: _stations
                        .map((s) => DropdownMenuItem<int>(value: s['id'] as int, child: Text('${s['station_name']}')))
                        .toList(),
                    onChanged: (v) async {
                      setState(() => _stationId = v);
                      if (_eventId != null && v != null) await _loadAllocations(_eventId!, v);
                    },
                  ),
                  const SizedBox(height: 10),
                  DropdownButtonFormField<int>(
                    value: _memberCacheId,
                    decoration: const InputDecoration(labelText: 'Participant', border: OutlineInputBorder(), isDense: true),
                    items: _members
                        .map((m) => DropdownMenuItem<int>(value: m['id'] as int, child: Text('${m['name_en']}')))
                        .toList(),
                    onChanged: (v) => setState(() => _memberCacheId = v),
                  ),
                ],
              ],
            ),
          ),
        ),
        if (_statusMsg != null) ...[
          const SizedBox(height: 10),
          Card(child: Padding(padding: const EdgeInsets.all(12), child: Text(_statusMsg!))),
        ],
        const SizedBox(height: 10),
        for (final al in _allocations)
          Card(
            child: ListTile(
              title: Text('${al['asset_code']} · ${al['asset_name']}'),
              subtitle: Text(
                'Allocated: ${al['allocated_qty']} · Issued: ${al['issued_qty']} · Returned: ${al['returned_qty']}',
              ),
              trailing: Wrap(
                spacing: 6,
                children: [
                  FilledButton(onPressed: () => _rapidTx(al, 'issue'), child: const Text('Issue 1')),
                  OutlinedButton(onPressed: () => _rapidTx(al, 'return'), child: const Text('Return 1')),
                ],
              ),
            ),
          ),
      ],
    );
  }
}
