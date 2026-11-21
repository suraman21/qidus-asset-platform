import 'package:flutter/material.dart';

import '../../core/security/keystore_channel.dart';
import '../../core/storage/local_db.dart';

class OutboxScreen extends StatefulWidget {
  final Future<void> Function() onSyncNow;

  const OutboxScreen({super.key, required this.onSyncNow});

  @override
  State<OutboxScreen> createState() => _OutboxScreenState();
}

class _OutboxScreenState extends State<OutboxScreen> {
  List<LocalOutboxItem> _items = [];
  String _deviceUuid = '';
  String _lastSync = 'Never';

  @override
  void initState() {
    super.initState();
    _reload();
  }

  Future<void> _reload() async {
    final items = await LocalDb.instance.listOutbox();
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
                    const Text('Durable Sync Outbox', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                    FilledButton.icon(
                      onPressed: () async {
                        await widget.onSyncNow();
                        await _reload();
                      },
                      icon: const Icon(Icons.sync),
                      label: const Text('Flush Outbox'),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                Text('Device UUID: $_deviceUuid', style: const TextStyle(fontSize: 12)),
                Text('Last Sync (UTC): $_lastSync', style: const TextStyle(fontSize: 12)),
              ],
            ),
          ),
        ),
        const SizedBox(height: 10),
        if (_items.isEmpty)
          const Card(
            child: Padding(
              padding: EdgeInsets.all(16),
              child: Text('No offline transactions recorded yet.'),
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
                      : (item.status == 'conflict' ? Icons.warning : Icons.schedule),
                  color: item.status == 'synced'
                      ? Colors.green
                      : (item.status == 'conflict' ? Colors.amber : Colors.blueAccent),
                ),
              ),
            ),
      ],
    );
  }
}
