import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:uuid/uuid.dart';

import '../../core/api/api_client.dart';
import '../../core/storage/local_db.dart';

class QrScanScreen extends StatefulWidget {
  final ApiClient api;
  final VoidCallback onQueued;

  const QrScanScreen({super.key, required this.api, required this.onQueued});

  @override
  State<QrScanScreen> createState() => _QrScanScreenState();
}

class _QrScanScreenState extends State<QrScanScreen> {
  final _tokenCtrl = TextEditingController();
  final _reasonCtrl = TextEditingController(text: 'Field scan adjustment');
  final _qtyCtrl = TextEditingController(text: '1');
  Map<String, dynamic>? _asset;
  bool _cameraActive = false;
  String? _statusMsg;

  @override
  void dispose() {
    _tokenCtrl.dispose();
    _reasonCtrl.dispose();
    _qtyCtrl.dispose();
    super.dispose();
  }

  Future<void> _lookupToken(String token) async {
    final clean = token.trim();
    if (clean.isEmpty) return;
    _tokenCtrl.text = clean;

    Map<String, dynamic>? found = await LocalDb.instance.findAssetByQr(clean);
    if (found == null) {
      try {
        final remote = await widget.api.request('GET', '/assets/by-qr/${Uri.encodeComponent(clean)}');
        found = remote;
        await LocalDb.instance.upsertAssets([remote]);
      } catch (_) {}
    }
    if (!mounted) return;
    setState(() {
      _asset = found;
      _cameraActive = false;
      _statusMsg = found == null ? 'No asset found for QR token "$clean" (offline cache & server checked).' : null;
    });
  }

  Future<void> _queueMovement(int sign) async {
    if (_asset == null) return;
    final assetId = _asset!['id'] as int;
    final qty = int.tryParse(_qtyCtrl.text.trim()) ?? 1;
    final delta = sign * qty.abs();
    final txUuid = const Uuid().v4();
    final payload = <String, dynamic>{
      'movement_type': sign > 0 ? 'incoming' : 'outgoing',
      'quantity_delta': delta,
      'reason': _reasonCtrl.text.trim().isEmpty ? 'Mobile field adjustment' : _reasonCtrl.text.trim(),
      'client_tx_uuid': txUuid,
    };

    try {
      await widget.api.request('POST', '/assets/$assetId/movements', body: payload, idempotencyKey: txUuid);
      await LocalDb.instance.adjustLocalAssetQty(assetId, delta);
      if (mounted) setState(() => _statusMsg = 'Stock movement synced immediately.');
    } catch (_) {
      await LocalDb.instance.enqueueOutbox(
        clientTxUuid: txUuid,
        actionType: 'movement',
        endpoint: '/assets/$assetId/movements',
        payload: payload,
      );
      await LocalDb.instance.adjustLocalAssetQty(assetId, delta);
      widget.onQueued();
      if (mounted) setState(() => _statusMsg = 'Offline: movement queued in durable outbox.');
    }
    final refreshed = await LocalDb.instance.findAssetByQr(_asset!['qr_token'] as String);
    if (mounted && refreshed != null) setState(() => _asset = refreshed);
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
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text('QR Scanner & Asset Lookup', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                    FilledButton.icon(
                      onPressed: () => setState(() => _cameraActive = !_cameraActive),
                      icon: Icon(_cameraActive ? Icons.stop : Icons.qr_code_scanner),
                      label: Text(_cameraActive ? 'Stop Camera' : 'Scan QR'),
                    ),
                  ],
                ),
                if (_cameraActive) ...[
                  const SizedBox(height: 12),
                  SizedBox(
                    height: 220,
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(12),
                      child: MobileScanner(
                        onDetect: (capture) {
                          final codes = capture.barcodes;
                          if (codes.isNotEmpty && codes.first.rawValue != null) {
                            _lookupToken(codes.first.rawValue!);
                          }
                        },
                      ),
                    ),
                  ),
                ],
                const SizedBox(height: 12),
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: _tokenCtrl,
                        decoration: const InputDecoration(
                          labelText: 'QR Token (QA-...)',
                          border: OutlineInputBorder(),
                          isDense: true,
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    ElevatedButton(
                      onPressed: () => _lookupToken(_tokenCtrl.text),
                      child: const Text('Find'),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
        if (_statusMsg != null) ...[
          const SizedBox(height: 10),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Text(_statusMsg!),
            ),
          ),
        ],
        if (_asset != null) ...[
          const SizedBox(height: 12),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('${_asset!['asset_code']} · ${_asset!['name_en']}', style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                  const SizedBox(height: 6),
                  Text('Mode: ${_asset!['tracking_mode']} · Status: ${_asset!['status']} · Qty: ${_asset!['quantity']}'),
                  if (_asset!['location_name'] != null) Text('Location: ${_asset!['location_name']}'),
                  const Divider(height: 22),
                  Row(
                    children: [
                      SizedBox(
                        width: 80,
                        child: TextField(
                          controller: _qtyCtrl,
                          keyboardType: TextInputType.number,
                          decoration: const InputDecoration(labelText: 'Qty', border: OutlineInputBorder(), isDense: true),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextField(
                          controller: _reasonCtrl,
                          decoration: const InputDecoration(labelText: 'Movement Reason', border: OutlineInputBorder(), isDense: true),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: FilledButton(
                          onPressed: () => _queueMovement(1),
                          child: const Text('+ Add Stock'),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: OutlinedButton(
                          onPressed: () => _queueMovement(-1),
                          child: const Text('- Issue Stock'),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ],
      ],
    );
  }
}
