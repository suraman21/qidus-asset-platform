import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:signature/signature.dart';
import 'package:uuid/uuid.dart';

import '../../core/api/api_client.dart';
import '../../core/config/app_config.dart';
import '../../core/security/keystore_channel.dart';
import '../../core/storage/local_db.dart';

class HandoverScreen extends StatefulWidget {
  final ApiClient api;
  final String lang;
  final VoidCallback onQueued;

  const HandoverScreen({
    super.key,
    required this.api,
    required this.lang,
    required this.onQueued,
  });

  @override
  State<HandoverScreen> createState() => _HandoverScreenState();
}

class _HandoverScreenState extends State<HandoverScreen> {
  final _receiverCtrl = TextEditingController();
  final _memberSearchCtrl = TextEditingController();
  final _qtyCtrl = TextEditingController(text: '1');
  final _sigCtrl = SignatureController(penStrokeWidth: 2.5, penColor: Colors.black, exportBackgroundColor: Colors.white);
  final _keystore = KeystoreChannel();

  List<Map<String, dynamic>> _assets = [];
  List<Map<String, dynamic>> _members = [];
  int? _selectedAssetId; // NEVER preselect
  String? _selectedMemberExtId; // NEVER preselect
  bool _nonMemberCustody = false;
  String _handoverType = 'issue';
  bool _useBiometricKeystore = true;
  bool _busy = false;
  String? _message;
  bool _isError = false;

  @override
  void initState() {
    super.initState();
    _loadCached();
  }

  @override
  void dispose() {
    _receiverCtrl.dispose();
    _memberSearchCtrl.dispose();
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
    });
  }

  Future<void> _filterMembers(String q) async {
    final rows = await LocalDb.instance.searchMembers(q);
    if (!mounted) return;
    setState(() => _members = rows);
  }

  Future<void> _submit() async {
    final s = AppStrings(widget.lang);
    if (_selectedAssetId == null) {
      setState(() {
        _message = s.isAm ? 'እባክዎ ንብረት ይምረጡ።' : 'Please select an asset first.';
        _isError = true;
      });
      return;
    }
    if (!_nonMemberCustody && (_selectedMemberExtId == null || _selectedMemberExtId!.isEmpty)) {
      setState(() {
        _message = s.isAm
            ? 'የአባል መታወቂያ (SSMS) መምረጥ ግዴታ ነው ወይም "አባል ያልሆነ" ይምረጡ።'
            : 'Immutable SSMS member selection is required (or toggle explicit non-member custody).';
        _isError = true;
      });
      return;
    }
    if (_receiverCtrl.text.trim().isEmpty) {
      setState(() {
        _message = s.isAm ? 'የተቀባዩ ስም ያስፈልጋል።' : 'Recipient display name is required.';
        _isError = true;
      });
      return;
    }

    setState(() {
      _busy = true;
      _message = null;
      _isError = false;
    });

    try {
      final qty = (int.tryParse(_qtyCtrl.text.trim()) ?? 1).clamp(1, 10000);
      final txUuid = const Uuid().v4();
      final deviceUuid = await _keystore.getOrCreateDeviceUuid();
      final txTime = DateTime.now().toUtc().toIso8601String();
      final user = await widget.api.getSavedUser();

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
        final avail = await _keystore.isBiometricOrCredentialAvailable();
        if (!avail) {
          setState(() {
            _message = s.isAm
                ? 'ባዮሜትሪክ ወይም የስልክ መቆለፊያ ሳይኖር የKeystore ፊርማ አይፈቀድም።'
                : 'Device biometric or lock-screen authentication is required for Keystore signing.';
            _isError = true;
          });
          return;
        }
        canonicalPayload = KeystoreChannel.buildCanonicalPayload(
          assetId: _selectedAssetId!,
          quantity: qty,
          handoverType: _handoverType,
          receiverName: _receiverCtrl.text.trim(),
          clientTxUuid: txUuid,
          deviceUuid: deviceUuid,
          actorUserId: user?['uid'] as int?,
          externalMemberId: _selectedMemberExtId,
          deviceTxAt: txTime,
        );
        biometricSigB64 = await _keystore.signCanonicalPayload(
          canonicalPayload,
          reason: 'Sign $_handoverType handover for ${_receiverCtrl.text.trim()}',
        );
      } else if (receiverSigB64 == null) {
        setState(() {
          _message = s.isAm
              ? 'ቢያንስ የተቀባይ ፊርማ ወይም የKeystore ባዮሜትሪክ ያስፈልጋል።'
              : 'Provide either a drawn recipient signature or Keystore biometric signature.';
          _isError = true;
        });
        return;
      }

      final method = (receiverSigB64 != null && biometricSigB64 != null)
          ? 'both'
          : (biometricSigB64 != null ? 'biometric_keystore' : 'drawn_signature');

      final payload = <String, dynamic>{
        'asset_id': _selectedAssetId,
        'handover_type': _handoverType,
        if (_selectedMemberExtId != null) 'receiver_member_external_id': _selectedMemberExtId,
        if (_nonMemberCustody) 'non_member_custody': true,
        'require_member_binding': !_nonMemberCustody,
        'receiver_display_name': _receiverCtrl.text.trim(),
        'quantity': qty,
        'verification_method': method,
        'device_uuid': deviceUuid,
        'client_tx_uuid': txUuid,
        'device_tx_at': txTime,
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
        final delta = _handoverType == 'issue' ? -qty : (_handoverType == 'transfer' ? 0 : qty);
        if (delta != 0) await LocalDb.instance.adjustLocalAssetQty(_selectedAssetId!, delta);
        _sigCtrl.clear();
        if (mounted) {
          setState(() {
            _message = 'Handover synced: ${handoverObj['handover_number'] ?? txUuid}';
            _isError = false;
          });
        }
      } on ApiException catch (e) {
        // Strict error separation: 4xx business/auth errors are NEVER queued as offline!
        if (e.statusCode >= 400 && e.statusCode < 500) {
          if (mounted) {
            setState(() {
              _message = 'Rejected by server (${e.statusCode}): ${e.message}';
              _isError = true;
            });
          }
        } else {
          await _queueOffline(txUuid, payload, qty);
        }
      } on SocketException {
        await _queueOffline(txUuid, payload, qty);
      } catch (e) {
        // Only transient transport failures go to offline queue
        if (e.toString().contains('TimeoutException') || e.toString().contains('SocketException') || e.toString().contains('ClientException')) {
          await _queueOffline(txUuid, payload, qty);
        } else {
          if (mounted) {
            setState(() {
              _message = 'Error: $e';
              _isError = true;
            });
          }
        }
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _message = 'Signing failed: $e';
          _isError = true;
        });
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _queueOffline(String txUuid, Map<String, dynamic> payload, int qty) async {
    await LocalDb.instance.enqueueOutbox(
      clientTxUuid: txUuid,
      actionType: 'handover',
      endpoint: '/handovers',
      payload: payload,
    );
    final delta = _handoverType == 'issue' ? -qty : (_handoverType == 'transfer' ? 0 : qty);
    if (delta != 0) await LocalDb.instance.adjustLocalAssetQty(_selectedAssetId!, delta);
    _sigCtrl.clear();
    widget.onQueued();
    if (mounted) {
      setState(() {
        _message = 'Network unreachable: signed handover queued in encrypted outbox.';
        _isError = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = AppStrings(widget.lang);
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(s.handoverTab, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                const SizedBox(height: 12),
                DropdownButtonFormField<int>(
                  value: _selectedAssetId,
                  hint: Text(s.selectAsset),
                  decoration: InputDecoration(labelText: s.selectAsset, border: const OutlineInputBorder(), isDense: true),
                  items: _assets
                      .map((a) => DropdownMenuItem<int>(
                            value: a['id'] as int,
                            child: Text('${a['asset_code']} — ${a['name_en']} (${a['quantity']})'),
                          ))
                      .toList(),
                  onChanged: (v) => setState(() => _selectedAssetId = v),
                ),
                const SizedBox(height: 10),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(s.nonMemberToggle),
                  value: _nonMemberCustody,
                  onChanged: (v) => setState(() {
                    _nonMemberCustody = v;
                    if (v) _selectedMemberExtId = null;
                  }),
                ),
                if (!_nonMemberCustody) ...[
                  TextField(
                    controller: _memberSearchCtrl,
                    decoration: InputDecoration(
                      labelText: s.isAm ? 'የSSMS አባል ፈልግ' : 'Filter SSMS member by name/code',
                      border: const OutlineInputBorder(),
                      isDense: true,
                    ),
                    onChanged: _filterMembers,
                  ),
                  const SizedBox(height: 8),
                  DropdownButtonFormField<String>(
                    value: _selectedMemberExtId,
                    hint: Text(s.selectMember),
                    decoration: InputDecoration(labelText: s.selectMember, border: const OutlineInputBorder(), isDense: true),
                    items: _members
                        .map((m) => DropdownMenuItem<String>(
                              value: m['external_member_id'] as String,
                              child: Text('${m['name_en']} (${m['member_code'] ?? m['external_member_id']})'),
                            ))
                        .toList(),
                    onChanged: (v) {
                      setState(() {
                        _selectedMemberExtId = v;
                        if (v != null) {
                          final match = _members.firstWhere((m) => m['external_member_id'] == v);
                          _receiverCtrl.text = (match['name_en'] ?? '').toString();
                        }
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
                  readOnly: !_nonMemberCustody && _selectedMemberExtId != null,
                  decoration: const InputDecoration(labelText: 'Recipient Display Name', border: OutlineInputBorder(), isDense: true),
                ),
                const SizedBox(height: 10),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(s.isAm ? 'በAndroid Keystore ባዮሜትሪክ ፈርም (EC P-256)' : 'Auth-Bound Android Keystore Biometric (EC P-256)'),
                  value: _useBiometricKeystore,
                  onChanged: (v) => setState(() => _useBiometricKeystore = v),
                ),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(s.isAm ? 'የተቀባይ ፊርማ' : 'Drawn Recipient Signature', style: const TextStyle(fontWeight: FontWeight.w600)),
                    TextButton(onPressed: () => _sigCtrl.clear(), child: Text(s.isAm ? 'አጽዳ' : 'Clear')),
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
                SizedBox(
                  height: 50,
                  child: FilledButton(
                    onPressed: _busy ? null : _submit,
                    child: _busy
                        ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                        : Text(s.confirmHandover),
                  ),
                ),
                if (_message != null) ...[
                  const SizedBox(height: 10),
                  Text(
                    _message!,
                    style: TextStyle(
                      fontWeight: FontWeight.w700,
                      color: _isError ? Colors.redAccent : Colors.green,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ],
    );
  }
}
