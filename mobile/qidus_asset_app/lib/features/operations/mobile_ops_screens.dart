import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/storage/local_db.dart';

class AssetCatalogMobileScreen extends StatefulWidget {
  final ApiClient api;
  final String lang;

  const AssetCatalogMobileScreen({super.key, required this.api, required this.lang});

  @override
  State<AssetCatalogMobileScreen> createState() => _AssetCatalogMobileScreenState();
}

class _AssetCatalogMobileScreenState extends State<AssetCatalogMobileScreen> {
  final _searchCtrl = TextEditingController();
  final _nameEnCtrl = TextEditingController();
  final _nameAmCtrl = TextEditingController();
  final _qtyCtrl = TextEditingController(text: '1');
  final _serialCtrl = TextEditingController();
  List<Map<String, dynamic>> _items = [];
  String _trackingMode = 'fixed';
  String? _statusMsg;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    _nameEnCtrl.dispose();
    _nameAmCtrl.dispose();
    _qtyCtrl.dispose();
    _serialCtrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final rows = await LocalDb.instance.listAssets(search: _searchCtrl.text);
    if (mounted) setState(() => _items = rows);
  }

  Future<void> _registerAsset() async {
    if (_nameEnCtrl.text.trim().isEmpty) return;
    try {
      final catsResp = await widget.api.request('GET', '/asset-categories');
      final cats = (catsResp['categories'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
      final matched = cats.firstWhere(
        (c) => c['tracking_mode'] == _trackingMode,
        orElse: () => cats.isNotEmpty ? cats.first : {'id': 1},
      );
      final res = await widget.api.request('POST', '/assets', body: {
        'name_en': _nameEnCtrl.text.trim(),
        'name_am': _nameAmCtrl.text.trim().isEmpty ? _nameEnCtrl.text.trim() : _nameAmCtrl.text.trim(),
        'category_id': matched['id'],
        'tracking_mode': _trackingMode,
        'quantity': int.tryParse(_qtyCtrl.text.trim()) ?? 1,
        if (_trackingMode == 'fixed' && _serialCtrl.text.trim().isNotEmpty) 'serial_number': _serialCtrl.text.trim(),
      });
      final created = (res['asset'] as Map<String, dynamic>?) ?? res;
      if (created['id'] != null) {
        await LocalDb.instance.upsertAssets([created]);
      }
      _nameEnCtrl.clear();
      _nameAmCtrl.clear();
      _serialCtrl.clear();
      await _load();
      if (mounted) setState(() => _statusMsg = 'Asset registered: ${created['asset_code'] ?? ''}');
    } catch (e) {
      if (mounted) setState(() => _statusMsg = 'Error: $e');
    }
  }

  @override
  Widget build(BuildContext context) {
    final isAm = widget.lang == 'am';
    return Scaffold(
      appBar: AppBar(title: Text(isAm ? 'ንብረቶች እና ምዝገባ' : 'Assets & Guided Register')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(isAm ? 'አዲስ ንብረት መዝግብ' : 'Guided Asset Registration', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                  const SizedBox(height: 10),
                  DropdownButtonFormField<String>(
                    value: _trackingMode,
                    decoration: const InputDecoration(labelText: 'Tracking Mode', border: OutlineInputBorder(), isDense: true),
                    items: const [
                      DropdownMenuItem(value: 'fixed', child: Text('Fixed Asset (Serialized)')),
                      DropdownMenuItem(value: 'consumable', child: Text('Consumable Stock')),
                      DropdownMenuItem(value: 'reusable_member', child: Text('Reusable Member Gear')),
                    ],
                    onChanged: (v) => setState(() => _trackingMode = v ?? 'fixed'),
                  ),
                  const SizedBox(height: 8),
                  TextField(controller: _nameEnCtrl, decoration: const InputDecoration(labelText: 'Name (EN)', border: OutlineInputBorder(), isDense: true)),
                  const SizedBox(height: 8),
                  TextField(controller: _nameAmCtrl, decoration: const InputDecoration(labelText: 'Name (AM)', border: OutlineInputBorder(), isDense: true)),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(child: TextField(controller: _qtyCtrl, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Qty', border: OutlineInputBorder(), isDense: true))),
                      if (_trackingMode == 'fixed') ...[
                        const SizedBox(width: 8),
                        Expanded(flex: 2, child: TextField(controller: _serialCtrl, decoration: const InputDecoration(labelText: 'Serial Number', border: OutlineInputBorder(), isDense: true))),
                      ],
                    ],
                  ),
                  const SizedBox(height: 10),
                  FilledButton(onPressed: _registerAsset, child: Text(isAm ? 'መዝግብ' : 'Register Asset')),
                  if (_statusMsg != null) ...[
                    const SizedBox(height: 8),
                    Text(_statusMsg!, style: const TextStyle(fontWeight: FontWeight.w600)),
                  ],
                ],
              ),
            ),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _searchCtrl,
            decoration: InputDecoration(
              labelText: isAm ? 'ንብረት ፈልግ (ኮድ / ስም / QR)' : 'Search assets (code / name / QR)',
              border: const OutlineInputBorder(),
              suffixIcon: IconButton(icon: const Icon(Icons.search), onPressed: _load),
            ),
            onChanged: (_) => _load(),
          ),
          const SizedBox(height: 10),
          for (final a in _items)
            Card(
              child: ListTile(
                title: Text('${a['asset_code']} · ${a['name_en']}'),
                subtitle: Text('${a['tracking_mode']} · Qty: ${a['quantity']} · ${a['status']}'),
                trailing: Text((a['location_name'] ?? '').toString(), style: const TextStyle(fontSize: 12)),
              ),
            ),
        ],
      ),
    );
  }
}

class RequestsApprovalsMobileScreen extends StatefulWidget {
  final ApiClient api;
  final String lang;

  const RequestsApprovalsMobileScreen({super.key, required this.api, required this.lang});

  @override
  State<RequestsApprovalsMobileScreen> createState() => _RequestsApprovalsMobileScreenState();
}

class _RequestsApprovalsMobileScreenState extends State<RequestsApprovalsMobileScreen> {
  final _itemCtrl = TextEditingController();
  final _reasonCtrl = TextEditingController();
  List<Map<String, dynamic>> _requests = [];
  String? _msg;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _itemCtrl.dispose();
    _reasonCtrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final res = await widget.api.request('GET', '/asset-requests');
      final rows = (res['requests'] as List<dynamic>? ?? res['items'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
      if (mounted) setState(() => _requests = rows);
    } catch (e) {
      if (mounted) setState(() => _msg = 'Online connection required to load approvals: $e');
    }
  }

  Future<void> _create() async {
    if (_itemCtrl.text.trim().isEmpty || _reasonCtrl.text.trim().isEmpty) return;
    try {
      await widget.api.request('POST', '/asset-requests', body: {
        'requested_item_name': _itemCtrl.text.trim(),
        'quantity': 1,
        'reason': _reasonCtrl.text.trim(),
      });
      _itemCtrl.clear();
      _reasonCtrl.clear();
      await _load();
      if (mounted) setState(() => _msg = 'Request submitted.');
    } catch (e) {
      if (mounted) setState(() => _msg = 'Error: $e');
    }
  }

  Future<void> _decide(int id, String stage, String decision) async {
    try {
      await widget.api.request('POST', '/asset-requests/$id/$stage', body: {'decision': decision, 'note': 'Mobile decision'});
      await _load();
    } catch (e) {
      if (mounted) setState(() => _msg = 'Decision error: $e');
    }
  }

  @override
  Widget build(BuildContext context) {
    final isAm = widget.lang == 'am';
    return Scaffold(
      appBar: AppBar(title: Text(isAm ? 'ጥያቄዎች እና ማጽደቂያ' : 'Requests & Approvals')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  TextField(controller: _itemCtrl, decoration: const InputDecoration(labelText: 'Requested Item', border: OutlineInputBorder(), isDense: true)),
                  const SizedBox(height: 8),
                  TextField(controller: _reasonCtrl, decoration: const InputDecoration(labelText: 'Reason', border: OutlineInputBorder(), isDense: true)),
                  const SizedBox(height: 10),
                  FilledButton(onPressed: _create, child: Text(isAm ? 'ጥያቄ ላክ' : 'Submit Request')),
                  if (_msg != null) ...[const SizedBox(height: 8), Text(_msg!)],
                ],
              ),
            ),
          ),
          const SizedBox(height: 10),
          for (final r in _requests)
            Card(
              child: ListTile(
                title: Text('${r['request_number']} · ${r['requested_item_name']}'),
                subtitle: Text('Status: ${r['status']}'),
                trailing: r['status'] == 'PENDING_DEPARTMENT_HEAD'
                    ? FilledButton(onPressed: () => _decide(r['id'] as int, 'dept-head-decide', 'approve'), child: const Text('Approve'))
                    : (r['status'] == 'PENDING_DEPUTY_CHAIRPERSON'
                        ? FilledButton(onPressed: () => _decide(r['id'] as int, 'deputy-decide', 'approve'), child: const Text('Deputy OK'))
                        : null),
              ),
            ),
        ],
      ),
    );
  }
}

class MaintenanceMobileScreen extends StatefulWidget {
  final ApiClient api;
  final String lang;

  const MaintenanceMobileScreen({super.key, required this.api, required this.lang});

  @override
  State<MaintenanceMobileScreen> createState() => _MaintenanceMobileScreenState();
}

class _MaintenanceMobileScreenState extends State<MaintenanceMobileScreen> {
  List<Map<String, dynamic>> _tickets = [];
  String? _msg;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final res = await widget.api.request('GET', '/maintenance');
      final rows = (res['tickets'] as List<dynamic>? ?? res['items'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
      if (mounted) setState(() => _tickets = rows);
    } catch (e) {
      if (mounted) setState(() => _msg = 'Load error: $e');
    }
  }

  Future<void> _complete(int id) async {
    try {
      await widget.api.request('PATCH', '/maintenance/$id', body: {'status': 'completed', 'resolution_notes': 'Completed on mobile'});
      await _load();
    } catch (e) {
      if (mounted) setState(() => _msg = 'Update error: $e');
    }
  }

  @override
  Widget build(BuildContext context) {
    final isAm = widget.lang == 'am';
    return Scaffold(
      appBar: AppBar(title: Text(isAm ? 'ጥገና' : 'Maintenance Tickets')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_msg != null) Card(child: Padding(padding: const EdgeInsets.all(12), child: Text(_msg!))),
          for (final t in _tickets)
            Card(
              child: ListTile(
                title: Text('${t['ticket_number']} · ${t['issue_summary']}'),
                subtitle: Text('Status: ${t['status']} · Type: ${t['maintenance_type']}'),
                trailing: t['status'] != 'completed'
                    ? OutlinedButton(onPressed: () => _complete(t['id'] as int), child: const Text('Complete'))
                    : const Icon(Icons.check_circle, color: Colors.green),
              ),
            ),
        ],
      ),
    );
  }
}

class InspectionsDisposalsMobileScreen extends StatefulWidget {
  final ApiClient api;
  final String lang;

  const InspectionsDisposalsMobileScreen({super.key, required this.api, required this.lang});

  @override
  State<InspectionsDisposalsMobileScreen> createState() => _InspectionsDisposalsMobileScreenState();
}

class _InspectionsDisposalsMobileScreenState extends State<InspectionsDisposalsMobileScreen> {
  List<Map<String, dynamic>> _inspections = [];
  List<Map<String, dynamic>> _disposals = [];
  String? _msg;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final ins = await widget.api.request('GET', '/inspections');
      final disp = await widget.api.request('GET', '/disposals');
      if (!mounted) return;
      setState(() {
        _inspections = (ins['inspections'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
        _disposals = (disp['disposals'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
      });
    } catch (e) {
      if (mounted) setState(() => _msg = 'Load error: $e');
    }
  }

  @override
  Widget build(BuildContext context) {
    final isAm = widget.lang == 'am';
    return Scaffold(
      appBar: AppBar(title: Text(isAm ? 'ቆጠራ እና ማስወገጃ' : 'Inspections & Disposals')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_msg != null) Card(child: Padding(padding: const EdgeInsets.all(12), child: Text(_msg!))),
          Text(isAm ? 'የንብረት ቆጠራ ዘመቻዎች' : 'Inspection Campaigns', style: const TextStyle(fontWeight: FontWeight.w800)),
          for (final i in _inspections)
            Card(child: ListTile(title: Text('${i['inspection_number']} · ${i['title']}'), subtitle: Text('Status: ${i['status']}'))),
          const SizedBox(height: 12),
          Text(isAm ? 'የማስወገጃ ጥያቄዎች' : 'Disposal Queue', style: const TextStyle(fontWeight: FontWeight.w800)),
          for (final d in _disposals)
            Card(child: ListTile(title: Text('${d['disposal_number']} · ${d['reason_type']}'), subtitle: Text('Status: ${d['status']}'))),
        ],
      ),
    );
  }
}

class ReportsMobileScreen extends StatefulWidget {
  final ApiClient api;
  final String lang;

  const ReportsMobileScreen({super.key, required this.api, required this.lang});

  @override
  State<ReportsMobileScreen> createState() => _ReportsMobileScreenState();
}

class _ReportsMobileScreenState extends State<ReportsMobileScreen> {
  Map<String, dynamic>? _report;
  String? _err;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final res = await widget.api.request('GET', '/reports/inventory_register');
      if (mounted) setState(() => _report = res);
    } catch (e) {
      if (mounted) setState(() => _err = e.toString());
    }
  }

  @override
  Widget build(BuildContext context) {
    final isAm = widget.lang == 'am';
    final rows = (_report?['rows'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
    return Scaffold(
      appBar: AppBar(title: Text(isAm ? 'ሪፖርቶች' : 'Asset Reports')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_err != null) Card(child: Padding(padding: const EdgeInsets.all(12), child: Text(_err!))),
          if (_report != null)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Text('Inventory Register Rows: ${rows.length}', style: const TextStyle(fontWeight: FontWeight.w700)),
              ),
            ),
          for (final r in rows.take(40))
            Card(
              child: ListTile(
                title: Text('${r['asset_code'] ?? ''} · ${r['name_en'] ?? ''}'),
                subtitle: Text('Qty: ${r['quantity'] ?? 0} · Status: ${r['status'] ?? ''}'),
              ),
            ),
        ],
      ),
    );
  }
}
