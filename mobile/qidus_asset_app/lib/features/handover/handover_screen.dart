import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:signature/signature.dart';
import 'package:uuid/uuid.dart';

import '../../core/api/api_client.dart';
import '../../core/security/keystore_channel.dart';
import '../../core/storage/local_db.dart';

class HandoverScreen extends StatefulWidget {
  final ApiClient api;
  final VoidCallback onQueued;

  const HandoverScreen({super.key, required this.api, required this.onQueued});

  @override
  State<HandoverScreen> createState() => _HandoverScreenState();
}

class _HandoverScreenState extends State<HandoverScreen> {
  final _receiverCtrl = TextEditingController();
  final _qtyCtrl = TextEditingController(text: '1');
  final _sigCtrl = SignatureController(penStrokeWidth: 2.5, penColor: Colors.black, exportBackgroundColor: Colors.white);
  final _keystore = KeystoreChannel();

  List<Map<String, dynamic>> _assets = [];
  List<Map<String, dynamic>> _members = [];
  int? _selectedAssetId;
  String? _selectedMemberExtId;
  String _handoverType = 'issue';
  bool _useBiometricKeystore = true;
  bool _busy = false;
  String? _message;

  @override
  void initState() {
    super.initState();
    _loadCached();
  }

  @override
  void dispose() {
    _receiverCtrl.dispose();
    _qtyCtrl.dispose();
    _sigCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadCached() async {
    final assets = await LocalDb.instance.listAssets();
    final members = await LocalDb.instance.searchMembers('');
    if (!mounted) return;
    setState(() {
      _assets = assets;
      _members = members;
      if (_assets.isNotEmpty) _selectedAssetId ??= _assets.first['id'] as int;
    });
  }

  Future<void> _submit() async {
    if (_selectedAssetId == null || _receiverCtrl.text.trim().isEmpty) {
      setState(() => _message = 'Select an asset and enter recipient display name.');
      return;
    }

    setState(() {
      _busy = true;
      _message = null;
    });

    try {
      final qty = (int.tryParse(_qtyCtrl.text.trim()) ?? 1).clamp(1, 10000);
      final txUuid = const Uuid().v4();
      final deviceUuid = await _keystore.getOrCreateDeviceUuid();

      String? receiverSigB64;
      if (_sigCtrl.isNotEmpty) {
        final pngBytes = await _sigCtrl.toPngBytes();
        if (pngBytes != null) {
          receiverSigB64 = base64Encode(pngBytes);
        }
      }

      String? canonicalPayload;
      String? biometricSigB64;
      if (_useBiometricKeystore) {
        final authed = await _keystore.authenticateBiometric(reason: 'Confirm asset $_handoverType handover');
        if (!authed) {
          setState(() => _message = 'Biometric confirmation cancelled.');
          return;
        }
        canonicalPayload = KeystoreChannel.buildCanonicalPayload(
          assetId: _selectedAssetId!,
          quantity: qty,
          handoverType: _handoverType,
          receiverName: _receiverCtrl.text.trim(),
          clientTxUuid: txUuid,
          deviceUuid: deviceUuid,
        );
        biometricSigB64 = await _keystore.signCanonicalPayload(canonicalPayload);
      }

      final method = (receiverSigB64 != null && biometricSigB64 != null)
          ? 'both'
          : (biometricSigB64 != null ? 'biometric_keystore' : 'drawn_signature');

      final payload = <String, dynamic>{
        'asset_id': _selectedAssetId,
        'handover_type': _handoverType,
        'receiver_member_external_id': _selectedMemberExtId,
        'receiver_display_name': _receiverCtrl.text.trim(),
        'quantity': qty,
        'verification_method': method,
        'device_uuid': deviceUuid,
        'client_tx_uuid': txUuid,
        'device_tx_at': DateTime.now().toUtc().toIso8601String(),
        if (receiverSigB64 != null) 'receiver_sig_b64': receiverSigB64,
        if (canonicalPayload != null) 'canonical_payload': canonicalPayload,
        if (biometricSigB64 != null) 'biometric_sig_b64': biometricSigB64,
      };

      try {
        final res = await widget.api.request(
          'POST',
          '/handovers',
          body: payload,
          idempotencyKey: txUuid,
          deviceUuid: deviceUuid,
        );
        final handoverObj = (res['handover'] as Map?) ?? res;
        if (mounted) setState(() => _message = 'Handover synced: ${handoverObj['handover_number'] ?? txUuid}');
      } catch (_) {
        await LocalDb.instance.enqueueOutbox(
          clientTxUuid: txUuid,
          actionType: 'handover',
          endpoint: '/handovers',
          payload: payload,
        );
        widget.onQueued();
        if (mounted) setState(() => _message = 'Offline: signed handover queued in durable outbox.');
      }

      final delta = _handoverType == 'issue' ? -qty : (_handoverType == 'transfer' ? 0 : qty);
      if (delta != 0) await LocalDb.instance.adjustLocalAssetQty(_selectedAssetId!, delta);
      _sigCtrl.clear();
    } catch (e) {
      if (mounted) setState(() => _message = 'Error: $e');
    } finally {
      if (mounted) setState(() => _busy = false);
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
                const Text('Custody Handover / Return', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                const SizedBox(height: 12),
                DropdownButtonFormField<int>(
                  value: _selectedAssetId,
                  decoration: const InputDecoration(labelText: 'Asset', border: OutlineInputBorder(), isDense: true),
                  items: _assets
                      .map((a) => DropdownMenuItem<int>(
                            value: a['id'] as int,
                            child: Text('${a['asset_code']} — ${a['name_en']} (${a['quantity']})'),
                          ))
                      .toList(),
                  onChanged: (v) => setState(() => _selectedAssetId = v),
                ),
                const SizedBox(height: 10),
                if (_members.isNotEmpty) ...[
                  DropdownButtonFormField<String>(
                    value: _selectedMemberExtId,
                    decoration: const InputDecoration(labelText: 'Synced Member (optional)', border: OutlineInputBorder(), isDense: true),
                    items: _members
                        .map((m) => DropdownMenuItem<String>(
                              value: m['external_member_id'] as String,
                              child: Text('${m['name_en']} (${m['clothing_size'] ?? 'No size'})'),
                            ))
                        .toList(),
                    onChanged: (v) {
                      setState(() {
                        _selectedMemberExtId = v;
                        final match = _members.firstWhere((m) => m['external_member_id'] == v);
                        _receiverCtrl.text = (match['name_en'] ?? '').toString();
                      });
                    },
                  ),
                  const SizedBox(height: 10),
                ],
                Row(
                  children: [
                    Expanded(
                      flex: 2,
                      child: DropdownButtonFormField<String>(
                        value: _handoverType,
                        decoration: const InputDecoration(labelText: 'Type', border: OutlineInputBorder(), isDense: true),
                        items: const [
                          DropdownMenuItem(value: 'issue', child: Text('Issue')),
                          DropdownMenuItem(value: 'return', child: Text('Return')),
                          DropdownMenuItem(value: 'partial_return', child: Text('Partial Return')),
                        ],
                        onChanged: (v) => setState(() => _handoverType = v ?? 'issue'),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: TextField(
                        controller: _qtyCtrl,
                        keyboardType: TextInputType.number,
                        decoration: const InputDecoration(labelText: 'Qty', border: OutlineInputBorder(), isDense: true),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: _receiverCtrl,
                  decoration: const InputDecoration(labelText: 'Recipient Display Name', border: OutlineInputBorder(), isDense: true),
                ),
                const SizedBox(height: 10),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: const Text('Sign with Android Keystore Biometric (EC P-256)'),
                  value: _useBiometricKeystore,
                  onChanged: (v) => setState(() => _useBiometricKeystore = v),
                ),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text('Drawn Recipient Signature', style: TextStyle(fontWeight: FontWeight.w600)),
                    TextButton(onPressed: () => _sigCtrl.clear(), child: const Text('Clear')),
                  ],
                ),
                Container(
                  height: 120,
                  decoration: BoxDecoration(
                    border: Border.all(color: Colors.grey.shade400),
                    borderRadius: BorderRadius.circular(10),
                    color: Colors.white,
                  ),
                  child: Signature(controller: _sigCtrl, backgroundColor: Colors.white),
                ),
                const SizedBox(height: 14),
                FilledButton(
                  onPressed: _busy ? null : _submit,
                  child: _busy
                      ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                      : const Text('Confirm Signed Handover'),
                ),
                if (_message != null) ...[
                  const SizedBox(height: 10),
                  Text(_message!, style: const TextStyle(fontWeight: FontWeight.w600)),
                ],
              ],
            ),
          ),
        ),
      ],
    );
  }
}
