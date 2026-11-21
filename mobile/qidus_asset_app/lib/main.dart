import 'package:flutter/material.dart';

import 'core/api/api_client.dart';
import 'core/config/app_config.dart';
import 'features/auth/login_screen.dart';
import 'features/home/home_shell.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const QidusAssetApp());
}

class QidusAssetApp extends StatefulWidget {
  const QidusAssetApp({super.key});

  @override
  State<QidusAssetApp> createState() => _QidusAssetAppState();
}

class _QidusAssetAppState extends State<QidusAssetApp> {
  final ApiClient _api = ApiClient();
  ThemeMode _themeMode = ThemeMode.dark;
  String _lang = 'am';
  Map<String, dynamic>? _user;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _restoreSession();
  }

  Future<void> _restoreSession() async {
    final user = await _api.getSavedUser();
    final token = await _api.getAccessToken();
    final lease = await _api.getOfflineLease();
    if (!mounted) return;
    setState(() {
      _user = (user != null && ((token != null && token.isNotEmpty) || (lease != null && lease.isNotEmpty))) ? user : null;
      _loading = false;
    });
  }

  void _toggleTheme() {
    setState(() {
      _themeMode = _themeMode == ThemeMode.dark ? ThemeMode.light : ThemeMode.dark;
    });
  }

  void _toggleLang() {
    setState(() {
      _lang = _lang == 'en' ? 'am' : 'en';
    });
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'QIDUS ASSET',
      debugShowCheckedModeBanner: false,
      themeMode: _themeMode,
      theme: AppConfig.buildLightTheme(),
      darkTheme: AppConfig.buildDarkTheme(),
      home: _loading
          ? const Scaffold(body: Center(child: CircularProgressIndicator()))
          : (_user == null
              ? LoginScreen(
                  api: _api,
                  onLoggedIn: (u) => setState(() => _user = u),
                )
              : HomeShell(
                  api: _api,
                  user: _user!,
                  lang: _lang,
                  isDark: _themeMode == ThemeMode.dark,
                  onToggleTheme: _toggleTheme,
                  onToggleLang: _toggleLang,
                  onLogout: () async {
                    await _api.clearSession();
                    if (mounted) setState(() => _user = null);
                  },
                )),
    );
  }
}
