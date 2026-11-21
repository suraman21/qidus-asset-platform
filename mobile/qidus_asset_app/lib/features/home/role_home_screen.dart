import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/config/app_config.dart';
import '../../core/storage/local_db.dart';
import '../operations/mobile_ops_screens.dart';

class RoleHomeScreen extends StatefulWidget {
  final ApiClient api;
  final Map<String, dynamic> user;
  final String lang;
  final VoidCallback onOpenScan;
  final VoidCallback onOpenEvents;

  const RoleHomeScreen({
    super.key,
    required this.api,
    required this.user,
    required this.lang,
    required this.onOpenScan,
    required this.onOpenEvents,
  });

  @override
  State<RoleHomeScreen> createState() => _RoleHomeScreenState();
}

class _RoleHomeScreenState extends State<RoleHomeScreen> {
  int _assetCount = 0;
  int _memberCount = 0;
  int _pendingOutbox = 0;
  bool _hasLease = false;

  @override
  void initState() {
    super.initState();
    _loadStats();
  }

  Future<void> _loadStats() async {
    final assets = await LocalDb.instance.listAssets();
    final members = await LocalDb.instance.searchMembers('');
    final out = await LocalDb.instance.pendingOutboxCount();
    final lease = await widget.api.getOfflineLease();
    if (!mounted) return;
    setState(() {
      _assetCount = assets.length;
      _memberCount = members.length;
      _pendingOutbox = out;
      _hasLease = lease != null && lease.isNotEmpty;
    });
  }

  @override
  Widget build(BuildContext context) {
    final s = AppStrings(widget.lang);
    final role = (widget.user['role'] ?? 'asset_officer').toString();
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  s.isAm ? 'የዕለት ሥራ መነሻ' : 'My Role Workspace',
                  style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
                ),
                const SizedBox(height: 6),
                Text('${widget.user['full_name'] ?? widget.user['username']} · $role'),
                const SizedBox(height: 10),
                Wrap(
                  spacing: 8,
                  runSpacing: 6,
                  children: [
                    Chip(
                      avatar: Icon(_hasLease ? Icons.verified_user : Icons.lock_clock, size: 16),
                      label: Text(_hasLease ? s.readyOffline : 'Sync for Lease'),
                    ),
                    Chip(label: Text('SQLCipher Encrypted')),
                    Chip(label: Text('Assets: $_assetCount · Members: $_memberCount+')),
                    if (_pendingOutbox > 0) Chip(label: Text('Outbox: $_pendingOutbox')),
                  ],
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 10),
        Card(
          child: Column(
            children: [
              ListTile(
                leading: const Icon(Icons.flash_on),
                title: Text(s.isAm ? 'ፈጣን የዝግጅት ጣቢያ' : 'Rapid Event Station Mode'),
                trailing: const Icon(Icons.chevron_right),
                onTap: widget.onOpenEvents,
              ),
              const Divider(height: 1),
              ListTile(
                leading: const Icon(Icons.qr_code_scanner),
                title: Text(s.isAm ? 'ተከታታይ QR ስካን' : 'Continuous QR Scanner'),
                trailing: const Icon(Icons.chevron_right),
                onTap: widget.onOpenScan,
              ),
              const Divider(height: 1),
              ListTile(
                leading: const Icon(Icons.inventory_2),
                title: Text(s.isAm ? 'ንብረቶች እና ምዝገባ' : 'Assets Catalog & Register'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => AssetCatalogMobileScreen(api: widget.api, lang: widget.lang)),
                ),
              ),
              const Divider(height: 1),
              ListTile(
                leading: const Icon(Icons.approval),
                title: Text(s.isAm ? 'ጥያቄዎች እና ማጽደቂያ' : 'Requests & Approvals'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => RequestsApprovalsMobileScreen(api: widget.api, lang: widget.lang)),
                ),
              ),
              const Divider(height: 1),
              ListTile(
                leading: const Icon(Icons.build),
                title: Text(s.isAm ? 'የጥገና ትኬቶች' : 'Maintenance Tickets'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => MaintenanceMobileScreen(api: widget.api, lang: widget.lang)),
                ),
              ),
              const Divider(height: 1),
              ListTile(
                leading: const Icon(Icons.fact_check),
                title: Text(s.isAm ? 'ቆጠራ እና ማስወገጃ' : 'Inspections & Disposals'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => InspectionsDisposalsMobileScreen(api: widget.api, lang: widget.lang)),
                ),
              ),
              const Divider(height: 1),
              ListTile(
                leading: const Icon(Icons.bar_chart),
                title: Text(s.isAm ? 'ሪፖርቶች' : 'Reports Summary'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => ReportsMobileScreen(api: widget.api, lang: widget.lang)),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
