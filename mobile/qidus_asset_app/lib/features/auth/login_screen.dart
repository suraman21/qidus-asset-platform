import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/security/keystore_channel.dart';
import '../../core/storage/local_db.dart';
import '../../core/sync/sync_engine.dart';

class LoginScreen extends StatefulWidget {
  final ApiClient api;
  final void Function(Map<String, dynamic> user) onLoggedIn;

  const LoginScreen({super.key, required this.api, required this.onLoggedIn});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _urlCtrl = TextEditingController();
  final _userCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  final _keystore = KeystoreChannel();
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    widget.api.getBaseUrl().then((u) => _urlCtrl.text = u);
  }

  @override
  void dispose() {
    _urlCtrl.dispose();
    _userCtrl.dispose();
    _passCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.api.setBaseUrl(_urlCtrl.text);
      final keyInfo = await _keystore.ensureKeyPair();
      final deviceUuid = keyInfo['deviceUuid']!;
      final res = await widget.api.login(
        username: _userCtrl.text.trim(),
        password: _passCtrl.text,
        deviceUuid: deviceUuid,
      );

      // Best-effort device Keystore enrollment & offline lease refresh
      try {
        if ((keyInfo['publicKeyPem'] ?? '').isNotEmpty) {
          await widget.api.enrollDevice(
            deviceUuid: deviceUuid,
            deviceLabel: 'QIDUS Android',
            keyAlgorithm: keyInfo['algorithm'] ?? 'EC_P256',
            publicKeyPem: keyInfo['publicKeyPem']!,
          );
        }
        await widget.api.acquireOfflineLease(deviceUuid);
      } catch (_) {
        // Admin approval may still be pending for offline lease
      }

      // Initial background sync
      await SyncEngine(api: widget.api, db: LocalDb.instance).syncAll(deviceUuid: deviceUuid);
      if (!mounted) return;
      widget.onLoggedIn(res['user'] as Map<String, dynamic>);
    } catch (e) {
      if (!mounted) return;
      setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Text(
                      'QIDUS ASSET',
                      style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'Offline-First Asset & Event Field Companion',
                      style: TextStyle(color: Theme.of(context).colorScheme.onSurface.withValues(alpha: 0.7)),
                    ),
                    const SizedBox(height: 20),
                    if (_error != null) ...[
                      Container(
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: Colors.red.withValues(alpha: 0.14),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: Text(_error!, style: const TextStyle(color: Colors.redAccent)),
                      ),
                      const SizedBox(height: 14),
                    ],
                    TextField(
                      controller: _urlCtrl,
                      decoration: const InputDecoration(
                        labelText: 'Server API Base URL',
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: _userCtrl,
                      decoration: const InputDecoration(
                        labelText: 'Username',
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: _passCtrl,
                      obscureText: true,
                      decoration: const InputDecoration(
                        labelText: 'Password',
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 18),
                    FilledButton(
                      onPressed: _busy ? null : _submit,
                      child: Padding(
                        padding: const EdgeInsets.symmetric(vertical: 12),
                        child: _busy
                            ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                            : const Text('Sign In & Sync Offline Cache'),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
