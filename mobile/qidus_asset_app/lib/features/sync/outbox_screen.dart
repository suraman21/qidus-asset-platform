import 'package:flutter/material.dart';

import '../../core/security/keystore_channel.dart';
import '../../core/storage/local_db.dart';

class OutboxScreen extends StatefulWidget {
  final Future<void> Function() onSyncNow;
  final String lang;

  const OutboxScreen({super.key, required this.onSyncNow, required this.lang});

  @override
  State<OutboxScreen> createState() => _OutboxScreenState();
}

class _OutboxScreenState extends State<OutboxScreen> {
  List<LocalOutboxItem> _items = [];
  String _deviceUuid = '';
  String _lastSync = 'Never';
  String _filter = 'all';

  @override
  void initState() {
    super.initState();
    _reload();
  }

  Future<void> _reload() async {
    final items = await LocalDb.instance.listOutbox(status: _filter == 'all' ? null : _filter);
    final uuid = await KeystoreChannel().getOrCreateDeviceUuid();
    final last = await LocalDb.instance.getMeta('last_sync_utc');
    if (!mounted) return;
    setState(() {
      _items = items;
      _deviceUuid = uuid;
      _lastSync = last ?? 'Never';
    });
  }

  @override
  Widget build(BuildContext context) {
    final isAm = widget.lang == 'am';
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(isAm ? 'የተመሰጠረ የማመሳሰያ ሳጥን' : 'Encrypted Sync Outbox', style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                    FilledButton.icon(
                      onPressed: () async {
                        await widget.onSyncNow();
                        await _reload();
                      },
                      icon: const Icon(Icons.sync),
                      label: Text(isAm ? 'አመሳስል' : 'Flush Outbox'),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                Text('Device UUID: $_deviceUuid', style: const TextStyle(fontSize: 12)),
                Text('Last Sync (UTC): $_lastSync', style: const TextStyle(fontSize: 12)),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 6,
                  children: [
                    for (final entry in {
                      'all': isAm ? 'ሁሉም' : 'All',
                      'pending': isAm ? 'በመጠባበቅ ላይ' : 'Pending',
                      'needs_review': isAm ? 'ግጭት' : 'Needs Review',
                      'rejected': isAm ? 'ውድቅ (4xx)' : 'Rejected',
                      'synced': isAm ? 'ተመሳስሏል' : 'Synced',
                    }.entries)
                      ChoiceChip(
                        label: Text(entry.value),
                        selected: _filter == entry.key,
                        onSelected: (_) {
                          setState(() => _filter = entry.key);
                          _reload();
                        },
                      ),
                  ],
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 10),
        if (_items.isEmpty)
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Text(isAm ? 'ምንም ግብይት አልተገኘም።' : 'No outbox items in this view.'),
            ),
          )
        else
          for (final item in _items)
            Card(
              child: ListTile(
                title: Text('${item.actionType.toUpperCase()} · ${item.status.toUpperCase()}'),
                subtitle: Text(
                  '${item.endpoint}\nUUID: ${item.clientTxUuid}'
                  '${item.lastError != null ? '\nNote: ${item.lastError}' : ''}',
                ),
                isThreeLine: true,
                trailing: Icon(
                  item.status == 'synced'
                      ? Icons.check_circle
                      : (item.status == 'rejected'
                          ? Icons.cancel
                          : (item.status == 'needs_review' ? Icons.warning : Icons.schedule)),
                  color: item.status == 'synced'
                      ? Colors.green
                      : (item.status == 'rejected'
                          ? Colors.redAccent
                          : (item.status == 'needs_review' ? Colors.amber : Colors.blueAccent)),
                ),
              ),
            ),
      ],
    );
  }
}
