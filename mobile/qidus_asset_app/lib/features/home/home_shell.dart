import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/config/app_config.dart';
import '../../core/security/keystore_channel.dart';
import '../../core/storage/local_db.dart';
import '../../core/sync/sync_engine.dart';
import '../events/rapid_event_screen.dart';
import '../handover/handover_screen.dart';
import '../scan/qr_scan_screen.dart';
import '../sync/outbox_screen.dart';
import 'role_home_screen.dart';

class HomeShell extends StatefulWidget {
  final ApiClient api;
  final Map<String, dynamic> user;
  final String lang;
  final bool isDark;
  final VoidCallback onToggleTheme;
  final VoidCallback onToggleLang;
  final Future<void> Function() onLogout;

  const HomeShell({
    super.key,
    required this.api,
    required this.user,
    required this.lang,
    required this.isDark,
    required this.onToggleTheme,
    required this.onToggleLang,
    required this.onLogout,
  });

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _tab = 0;
  bool _online = true;
  bool _syncing = false;
  int _pendingCount = 0;
  StreamSubscription<List<ConnectivityResult>>? _connSub;

  @override
  void initState() {
    super.initState();
    _refreshBadge();
    Connectivity().checkConnectivity().then((res) {
      if (mounted) {
        setState(() => _online = !res.contains(ConnectivityResult.none));
      }
    });
    _connSub = Connectivity().onConnectivityChanged.listen((res) {
      final isNowOnline = !res.contains(ConnectivityResult.none);
      if (mounted) setState(() => _online = isNowOnline);
      if (isNowOnline) _triggerSync();
    });
  }

  @override
  void dispose() {
    _connSub?.cancel();
    super.dispose();
  }

  Future<void> _refreshBadge() async {
    final cnt = await LocalDb.instance.pendingOutboxCount();
    if (mounted) setState(() => _pendingCount = cnt);
  }

  Future<void> _triggerSync() async {
    if (_syncing) return;
    setState(() => _syncing = true);
    try {
      final devUuid = await KeystoreChannel().getOrCreateDeviceUuid();
      final sum = await SyncEngine(api: widget.api, db: LocalDb.instance).syncAll(deviceUuid: devUuid);
      await _refreshBadge();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              'Synced ${sum.syncedOutbox} · Pulled ${sum.pulledAssets} assets / ${sum.pulledMembers} members / ${sum.pulledEvents} events'
              '${sum.conflicts > 0 ? ' · ${sum.conflicts} conflicts!' : ''}'
              '${sum.rejected > 0 ? ' · ${sum.rejected} rejected!' : ''}',
            ),
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _syncing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = AppStrings(widget.lang);
    final screens = [
      RoleHomeScreen(
        api: widget.api,
        user: widget.user,
        lang: widget.lang,
        onOpenScan: () => setState(() => _tab = 1),
        onOpenEvents: () => setState(() => _tab = 3),
      ),
      QrScanScreen(api: widget.api, lang: widget.lang, onQueued: _refreshBadge),
      HandoverScreen(api: widget.api, lang: widget.lang, onQueued: _refreshBadge),
      RapidEventScreen(api: widget.api, lang: widget.lang, onQueued: _refreshBadge),
      OutboxScreen(onSyncNow: _triggerSync, lang: widget.lang),
    ];

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(s.appTitle, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
            Row(
              children: [
                Icon(Icons.circle, size: 9, color: _online ? Colors.greenAccent : Colors.amber),
                const SizedBox(width: 5),
                Text(
                  '${_online ? s.online : s.offline} · ${(widget.user['full_name'] ?? widget.user['username'] ?? '').toString()}',
                  style: const TextStyle(fontSize: 11),
                ),
              ],
            ),
          ],
        ),
        actions: [
          IconButton(
            tooltip: s.syncNow,
            onPressed: _syncing ? null : _triggerSync,
            icon: _syncing ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.sync),
          ),
          IconButton(
            tooltip: 'Toggle EN/AM',
            onPressed: widget.onToggleLang,
            icon: Text(widget.lang.toUpperCase(), style: const TextStyle(fontWeight: FontWeight.w700)),
          ),
          IconButton(
            tooltip: 'Toggle Theme',
            onPressed: widget.onToggleTheme,
            icon: Icon(widget.isDark ? Icons.light_mode : Icons.dark_mode),
          ),
          IconButton(
            tooltip: 'Sign Out',
            onPressed: widget.onLogout,
            icon: const Icon(Icons.logout),
          ),
        ],
      ),
      body: screens[_tab],
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (i) {
          setState(() => _tab = i);
          _refreshBadge();
        },
        destinations: [
          NavigationDestination(icon: const Icon(Icons.dashboard), label: s.homeTab),
          NavigationDestination(icon: const Icon(Icons.qr_code_scanner), label: s.scanTab),
          NavigationDestination(icon: const Icon(Icons.assignment_turned_in), label: s.handoverTab),
          NavigationDestination(icon: const Icon(Icons.flash_on), label: s.eventsTab),
          NavigationDestination(
            icon: Badge(
              isLabelVisible: _pendingCount > 0,
              label: Text('$_pendingCount'),
              child: const Icon(Icons.outbox),
            ),
            label: s.outboxTab,
          ),
        ],
      ),
    );
  }
}
