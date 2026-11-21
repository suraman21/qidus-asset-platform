import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:uuid/uuid.dart';

import '../../core/api/api_client.dart';
import '../../core/config/app_config.dart';
import '../../core/security/keystore_channel.dart';
import '../../core/storage/local_db.dart';

class RapidEventScreen extends StatefulWidget {
  final ApiClient api;
  final String lang;
  final VoidCallback onQueued;

  const RapidEventScreen({
    super.key,
    required this.api,
    required this.lang,
    required this.onQueued,
  });

  @override
  State<RapidEventScreen> createState() => _RapidEventScreenState();
}

class _RapidEventScreenState extends State<RapidEventScreen> {
  final _memberSearchCtrl = TextEditingController();
  final _overrideReasonCtrl = TextEditingController();

  List<Map<String, dynamic>> _events = [];
  List<Map<String, dynamic>> _stations = [];
  List<Map<String, dynamic>> _allocations = [];
  List<Map<String, dynamic>> _memberSearchResults = [];
  List<Map<String, dynamic>> _outstanding = [];
  Map<String, dynamic>? _manifest;
  Map<String, dynamic>? _selectedMember; // NEVER preselect first member!

  int? _eventId;
  int? _stationId;
  bool _stationActive = false;
  bool _scannerOpen = true;
  String _mode = 'issue'; // issue | return
  bool _hasOfflineLease = false;
  final Map<int, int> _issueQuantities = {};
  final Map<int, int> _returnQuantities = {};
  String? _bannerMsg;
  bool _bannerIsError = false;
  String? _lastScannedCode;
  DateTime? _lastScanAt;
  int _processedCount = 0;

  @override
  void initState() {
    super.initState();
    _loadInitial();
  }

  @override
  void dispose() {
    _memberSearchCtrl.dispose();
    _overrideReasonCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadInitial() async {
    final events = await LocalDb.instance.listEvents();
    final lease = await widget.api.getOfflineLease();
    if (!mounted) return;
    setState(() {
      _events = events;
      _hasOfflineLease = lease != null && lease.isNotEmpty;
    });
    if (events.length == 1) {
      await _selectEvent(events.first['id'] as int);
    }
  }

  Future<void> _selectEvent(int eventId) async {
    final stations = await LocalDb.instance.listStations(eventId);
    final manifest = await LocalDb.instance.getEventManifest(eventId);
    if (!mounted) return;
    setState(() {
      _eventId = eventId;
      _stations = stations;
      _manifest = manifest;
      _stationId = stations.length == 1 ? stations.first['id'] as int : null;
      _allocations = [];
      _selectedMember = null;
      _stationActive = false;
    });
    if (_stationId != null) {
      await _loadAllocations(eventId, _stationId!);
    }
  }

  Future<void> _loadAllocations(int eventId, int stationId) async {
    final alloc = await LocalDb.instance.listStationAllocations(eventId, stationId);
    if (!mounted) return;
    setState(() {
      _allocations = alloc;
      _issueQuantities.clear();
      for (final a in alloc) {
        _issueQuantities[a['id'] as int] = 1;
      }
    });
  }

  Future<void> _onScanMember(String raw) async {
    final clean = raw.trim();
    if (clean.isEmpty) return;
    final now = DateTime.now();
    if (_lastScannedCode == clean && _lastScanAt != null && now.difference(_lastScanAt!).inMilliseconds < 1500) {
      return;
    }
    _lastScannedCode = clean;
    _lastScanAt = now;

    final member = await LocalDb.instance.findMemberByQrOrCode(clean);
    if (!mounted) return;
    if (member == null) {
      HapticFeedback.heavyImpact();
      setState(() {
        _bannerMsg = widget.lang == 'am'
            ? 'የአባል QR "$clean" አልተገኘም።'
            : 'No active SSMS member matched "$clean".';
        _bannerIsError = true;
      });
      return;
    }
    await _selectMember(member);
  }

  Future<void> _selectMember(Map<String, dynamic> member) async {
    HapticFeedback.selectionClick();
    List<Map<String, dynamic>> out = [];
    if (_eventId != null) {
      out = await LocalDb.instance.listOutstandingForMember(_eventId!, member['id'] as int);
    }
    if (!mounted) return;
    setState(() {
      _selectedMember = member;
      _outstanding = out;
      _returnQuantities.clear();
      for (final o in out) {
        final rem = ((o['issued_qty'] as int) - (o['returned_qty'] as int)).clamp(1, 999);
        _returnQuantities[(o['asset_id'] as int) * 10000 + ((o['variant_id'] as int?) ?? 0)] = rem;
      }
      _bannerMsg = null;
    });
  }

  Future<void> _searchMembers(String query) async {
    final res = await LocalDb.instance.searchMembers(query);
    if (!mounted) return;
    setState(() => _memberSearchResults = res);
  }

  Future<void> _confirmIssueBundle() async {
    if (_eventId == null || _stationId == null || _selectedMember == null) return;
    final selectedAllocs = _allocations.where((al) => (_issueQuantities[al['id'] as int] ?? 0) > 0).toList();
    if (selectedAllocs.isEmpty) {
      setState(() {
        _bannerMsg = widget.lang == 'am' ? 'ቢያንስ አንድ ዕቃ ይምረጡ።' : 'Select at least 1 item to issue.';
        _bannerIsError = true;
      });
      return;
    }

    final deviceUuid = await KeystoreChannel().getOrCreateDeviceUuid();
    final txList = <Map<String, dynamic>>[];
    final batchUuid = const Uuid().v4();
    final memberId = _selectedMember!['id'] as int;

    for (final al in selectedAllocs) {
      final qty = _issueQuantities[al['id'] as int] ?? 1;
      final txUuid = const Uuid().v4();
      txList.add({
        'client_tx_uuid': txUuid,
        'station_id': _stationId,
        'member_cache_id': memberId,
        'asset_id': al['asset_id'],
        if (al['variant_id'] != null) 'variant_id': al['variant_id'],
        'tx_type': 'issue',
        'quantity': qty,
        if (_overrideReasonCtrl.text.trim().isNotEmpty) 'condition_note': _overrideReasonCtrl.text.trim(),
        'verification_method': 'operator_confirm',
        'device_tx_at': DateTime.now().toUtc().toIso8601String().substring(0, 19).replaceFirst('T', ' '),
      });

      await LocalDb.instance.recordLocalEventTx(
        eventId: _eventId!,
        allocationId: al['id'] as int,
        memberCacheId: memberId,
        assetId: al['asset_id'] as int,
        variantId: al['variant_id'] as int?,
        assetCode: (al['asset_code'] ?? '').toString(),
        assetName: (al['asset_name'] ?? '').toString(),
        txType: 'issue',
        qty: qty,
      );
    }

    await LocalDb.instance.enqueueOutbox(
      clientTxUuid: batchUuid,
      actionType: 'event_tx',
      endpoint: '/events/$_eventId/sync-batch',
      payload: {'transactions': txList, 'device_uuid': deviceUuid},
    );
    widget.onQueued();
    HapticFeedback.mediumImpact();
    await _loadAllocations(_eventId!, _stationId!);
    if (!mounted) return;
    setState(() {
      _processedCount += txList.length;
      final memberName = _selectedMember!['name_en'];
      _selectedMember = null; // Auto-reset ready for next member scan!
      _overrideReasonCtrl.clear();
      _bannerMsg = widget.lang == 'am'
          ? 'ለ $memberName (${txList.length} ዕቃዎች) ተሰጥቷል። ቀጣዩን አባል ይቃኙ!'
          : 'Issued ${txList.length} item(s) to $memberName. Ready for next scan!';
      _bannerIsError = false;
    });
  }

  Future<void> _confirmReturnItem(Map<String, dynamic> item, String txType) async {
    if (_eventId == null || _stationId == null || _selectedMember == null) return;
    final memberId = _selectedMember!['id'] as int;
    final assetId = item['asset_id'] as int;
    final variantId = (item['variant_id'] as int?) ?? 0;
    final key = assetId * 10000 + variantId;
    final maxOut = ((item['issued_qty'] as int) - (item['returned_qty'] as int)).clamp(1, 999);
    final qty = (_returnQuantities[key] ?? maxOut).clamp(1, maxOut);
    final txUuid = const Uuid().v4();

    final matchingAlloc = _allocations.firstWhere(
      (a) => a['asset_id'] == assetId,
      orElse: () => {'id': 0},
    );
    if ((matchingAlloc['id'] as int) > 0) {
      await LocalDb.instance.recordLocalEventTx(
        eventId: _eventId!,
        allocationId: matchingAlloc['id'] as int,
        memberCacheId: memberId,
        assetId: assetId,
        variantId: variantId > 0 ? variantId : null,
        assetCode: (item['asset_code'] ?? '').toString(),
        assetName: (item['asset_name'] ?? '').toString(),
        txType: txType,
        qty: qty,
      );
    }

    await LocalDb.instance.enqueueOutbox(
      clientTxUuid: txUuid,
      actionType: 'event_tx',
      endpoint: '/events/$_eventId/sync-batch',
      payload: {
        'transactions': [
          {
            'client_tx_uuid': txUuid,
            'station_id': _stationId,
            'member_cache_id': memberId,
            'asset_id': assetId,
            if (variantId > 0) 'variant_id': variantId,
            'tx_type': txType,
            'quantity': qty,
            'verification_method': 'operator_confirm',
            'device_tx_at': DateTime.now().toUtc().toIso8601String().substring(0, 19).replaceFirst('T', ' '),
          }
        ]
      },
    );
    widget.onQueued();
    HapticFeedback.mediumImpact();
    await _selectMember(_selectedMember!);
    if (!mounted) return;
    setState(() {
      _processedCount++;
      _bannerMsg = widget.lang == 'am'
          ? '${item['asset_code']} × $qty ($txType) ተመዝግቧል።'
          : 'Recorded $txType of ${item['asset_code']} × $qty.';
      _bannerIsError = false;
      if (_outstanding.isEmpty) {
        _selectedMember = null;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final s = AppStrings(widget.lang);
    final manifestVerified = _manifest != null && (_manifest!['is_verified'] == 1);
    final readyOffline = _eventId != null && _stationId != null && manifestVerified && _hasOfflineLease;

    if (_stationActive && _eventId != null && _stationId != null) {
      return ListView(
        padding: const EdgeInsets.all(14),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(
                children: [
                  Expanded(
                    child: SegmentedButton<String>(
                      segments: [
                        ButtonSegment(value: 'issue', label: Text(s.issueMode), icon: const Icon(Icons.upload)),
                        ButtonSegment(value: 'return', label: Text(s.returnMode), icon: const Icon(Icons.assignment_return)),
                      ],
                      selected: {_mode},
                      onSelectionChanged: (sel) => setState(() => _mode = sel.first),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Chip(label: Text('$_processedCount')),
                  IconButton(
                    tooltip: 'Exit Station',
                    onPressed: () => setState(() => _stationActive = false),
                    icon: const Icon(Icons.close),
                  ),
                ],
              ),
            ),
          ),
          if (_bannerMsg != null) ...[
            const SizedBox(height: 8),
            Card(
              color: _bannerIsError ? Colors.red.withOpacity(0.15) : Colors.green.withOpacity(0.15),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Text(_bannerMsg!, style: const TextStyle(fontWeight: FontWeight.w700)),
              ),
            ),
          ],
          const SizedBox(height: 8),
          if (_selectedMember == null) ...[
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Expanded(child: Text(s.scanMemberPrompt, style: const TextStyle(fontWeight: FontWeight.w800))),
                        Switch(value: _scannerOpen, onChanged: (v) => setState(() => _scannerOpen = v)),
                      ],
                    ),
                    if (_scannerOpen) ...[
                      const SizedBox(height: 8),
                      SizedBox(
                        height: 200,
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(12),
                          child: MobileScanner(
                            onDetect: (cap) {
                              final code = cap.barcodes.isNotEmpty ? cap.barcodes.first.rawValue : null;
                              if (code != null) _onScanMember(code);
                            },
                          ),
                        ),
                      ),
                    ],
                    const SizedBox(height: 10),
                    TextField(
                      controller: _memberSearchCtrl,
                      decoration: InputDecoration(
                        labelText: s.isAm ? 'ወይም አባል በስም/ኮድ ፈልግ' : 'Or search member by name / code / ID',
                        border: const OutlineInputBorder(),
                        suffixIcon: IconButton(
                          icon: const Icon(Icons.search),
                          onPressed: () => _searchMembers(_memberSearchCtrl.text),
                        ),
                      ),
                      onChanged: _searchMembers,
                    ),
                    for (final m in _memberSearchResults.take(6))
                      ListTile(
                        title: Text('${m['name_en']} (${m['member_code'] ?? m['external_member_id']})'),
                        subtitle: Text('${m['section_name'] ?? ''} · Size: ${m['clothing_size'] ?? '—'}'),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () => _selectMember(m),
                      ),
                  ],
                ),
              ),
            ),
          ] else ...[
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('${_selectedMember!['name_en']}', style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                              Text(
                                '${_selectedMember!['external_member_id']} · ${_selectedMember!['section_name'] ?? 'Member'} · Size: ${_selectedMember!['clothing_size'] ?? '—'}',
                                style: const TextStyle(fontSize: 12),
                              ),
                            ],
                          ),
                        ),
                        IconButton(
                          onPressed: () => setState(() => _selectedMember = null),
                          icon: const Icon(Icons.qr_code_scanner),
                          tooltip: 'Change / Rescan Member',
                        ),
                      ],
                    ),
                    const Divider(height: 20),
                    if (_mode == 'issue') ...[
                      for (final al in _allocations)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 4),
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text('${al['asset_code']} · ${al['asset_name']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                                    Text(
                                      '${al['size_label'] != null ? 'Size ${al['size_label']} · ' : ''}Rem: ${(al['allocated_qty'] as int) - (al['issued_qty'] as int)}',
                                      style: const TextStyle(fontSize: 12),
                                    ),
                                  ],
                                ),
                              ),
                              IconButton(
                                onPressed: () => setState(() {
                                  final cur = _issueQuantities[al['id'] as int] ?? 1;
                                  _issueQuantities[al['id'] as int] = (cur - 1).clamp(0, 50);
                                }),
                                icon: const Icon(Icons.remove_circle_outline),
                              ),
                              Text('${_issueQuantities[al['id'] as int] ?? 1}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                              IconButton(
                                onPressed: () => setState(() {
                                  final cur = _issueQuantities[al['id'] as int] ?? 1;
                                  _issueQuantities[al['id'] as int] = (cur + 1).clamp(0, 50);
                                }),
                                icon: const Icon(Icons.add_circle_outline),
                              ),
                            ],
                          ),
                        ),
                      const SizedBox(height: 8),
                      TextField(
                        controller: _overrideReasonCtrl,
                        decoration: InputDecoration(
                          labelText: s.isAm ? 'ልዩ ማስታወሻ (ካለ)' : 'Override / condition note (optional)',
                          border: const OutlineInputBorder(),
                          isDense: true,
                        ),
                      ),
                      const SizedBox(height: 12),
                      SizedBox(
                        height: 54,
                        child: FilledButton.icon(
                          onPressed: _confirmIssueBundle,
                          icon: const Icon(Icons.check_circle),
                          label: Text(s.confirmIssue),
                        ),
                      ),
                    ] else ...[
                      if (_outstanding.isEmpty)
                        Padding(
                          padding: const EdgeInsets.all(12),
                          child: Text(s.isAm ? 'በዚህ አባል እጅ ያልተመለሰ ዕቃ የለም።' : 'No outstanding items for this member.'),
                        )
                      else
                        for (final o in _outstanding)
                          Card(
                            child: Padding(
                              padding: const EdgeInsets.all(10),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.stretch,
                                children: [
                                  Text('${o['asset_code']} · ${o['asset_name']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                                  Text('Outstanding: ${(o['issued_qty'] as int) - (o['returned_qty'] as int)}'),
                                  const SizedBox(height: 8),
                                  Wrap(
                                    spacing: 8,
                                    children: [
                                      FilledButton(
                                        onPressed: () => _confirmReturnItem(o, 'return'),
                                        child: Text(s.confirmReturn),
                                      ),
                                      OutlinedButton(
                                        onPressed: () => _confirmReturnItem(o, 'partial_return'),
                                        child: Text(s.isAm ? 'በከፊል' : 'Partial'),
                                      ),
                                      OutlinedButton(
                                        onPressed: () => _confirmReturnItem(o, 'damaged'),
                                        child: Text(s.isAm ? 'ተጎድቷል' : 'Damaged'),
                                      ),
                                    ],
                                  ),
                                ],
                              ),
                            ),
                          ),
                    ],
                  ],
                ),
              ),
            ),
          ],
        ],
      );
    }

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(s.isAm ? 'የዝግጅት ዝግጁነት ማረጋገጫ (Readiness Gate)' : 'Event Preparation & Readiness Gate',
                    style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                const SizedBox(height: 12),
                if (_events.isEmpty)
                  Text(s.isAm ? 'የተጫነ ዝግጅት የለም። መጀመሪያ Sync ይጫኑ።' : 'No event packages cached yet. Tap Sync while online.')
                else ...[
                  DropdownButtonFormField<int>(
                    value: _eventId,
                    hint: Text(s.isAm ? 'ዝግጅት ይምረጡ...' : 'Select event...'),
                    decoration: const InputDecoration(border: OutlineInputBorder(), isDense: true),
                    items: _events
                        .map((e) => DropdownMenuItem<int>(value: e['id'] as int, child: Text('${e['event_code']} — ${e['name_en']}')))
                        .toList(),
                    onChanged: (v) {
                      if (v != null) _selectEvent(v);
                    },
                  ),
                  const SizedBox(height: 10),
                  DropdownButtonFormField<int>(
                    value: _stationId,
                    hint: Text(s.isAm ? 'ጣቢያ ይምረጡ...' : 'Select station...'),
                    decoration: const InputDecoration(border: OutlineInputBorder(), isDense: true),
                    items: _stations
                        .map((st) => DropdownMenuItem<int>(value: st['id'] as int, child: Text('${st['station_name']}')))
                        .toList(),
                    onChanged: (v) async {
                      setState(() => _stationId = v);
                      if (_eventId != null && v != null) await _loadAllocations(_eventId!, v);
                    },
                  ),
                  const SizedBox(height: 12),
                  Wrap(
                    spacing: 8,
                    runSpacing: 6,
                    children: [
                      Chip(
                        avatar: Icon(manifestVerified ? Icons.verified : Icons.warning, size: 16),
                        label: Text(manifestVerified ? s.readyOffline : s.incompletePkg),
                      ),
                      Chip(
                        avatar: Icon(_hasOfflineLease ? Icons.lock : Icons.lock_open, size: 16),
                        label: Text(_hasOfflineLease ? 'Offline Lease OK' : 'Lease Required'),
                      ),
                      if (_manifest != null)
                        Chip(label: Text('${_manifest!['expected_participants']} members · ${_manifest!['expected_allocations']} items')),
                    ],
                  ),
                  const SizedBox(height: 14),
                  SizedBox(
                    height: 52,
                    child: FilledButton.icon(
                      onPressed: readyOffline ? () => setState(() => _stationActive = true) : null,
                      icon: const Icon(Icons.flash_on),
                      label: Text(s.isAm ? 'ፈጣን የጣቢያ ስካን ጀምር' : 'Launch Station Field Mode'),
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
