import 'package:flutter/material.dart';

class AppConfig {
  static const String defaultBaseUrl = 'https://your-school-domain.org/qidus-asset/api/v1';

  static ThemeData buildLightTheme() {
    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.light,
      colorScheme: ColorScheme.fromSeed(
        seedColor: const Color(0xFF1E3A8A),
        brightness: Brightness.light,
        primary: const Color(0xFF1E3A8A),
        secondary: const Color(0xFFD97706),
        surface: const Color(0xFFFFFFFF),
      ),
      scaffoldBackgroundColor: const Color(0xFFF8FAFC),
      appBarTheme: const AppBarTheme(
        backgroundColor: Color(0xFFFFFFFF),
        foregroundColor: Color(0xFF0F172A),
        elevation: 0,
      ),
      cardTheme: CardTheme(
        color: const Color(0xFFFFFFFF),
        elevation: 1,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: const BorderSide(color: Color(0xFFE2E8F0)),
        ),
      ),
    );
  }

  static ThemeData buildDarkTheme() {
    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.dark,
      colorScheme: ColorScheme.fromSeed(
        seedColor: const Color(0xFF3B82F6),
        brightness: Brightness.dark,
        primary: const Color(0xFF3B82F6),
        secondary: const Color(0xFFF59E0B),
        surface: const Color(0xFF1E293B),
      ),
      scaffoldBackgroundColor: const Color(0xFF0F172A),
      appBarTheme: const AppBarTheme(
        backgroundColor: Color(0xFF1E293B),
        foregroundColor: Color(0xFFF8FAFC),
        elevation: 0,
      ),
      cardTheme: CardTheme(
        color: const Color(0xFF1E293B),
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: const BorderSide(color: Color(0xFF334155)),
        ),
      ),
    );
  }
}

class AppStrings {
  final String lang;
  const AppStrings(this.lang);

  bool get isAm => lang == 'am';

  String get appTitle => isAm ? 'ቅዱስ ንብረት' : 'QIDUS ASSET';
  String get scanTab => isAm ? 'ስካን' : 'Scan';
  String get handoverTab => isAm ? 'ርክክብ' : 'Handover';
  String get eventsTab => isAm ? 'ዝግጅት' : 'Events';
  String get outboxTab => isAm ? 'ማመሳሰያ' : 'Outbox';
  String get syncNow => isAm ? 'አመሳስል' : 'Sync Now';
  String get online => isAm ? 'መስመር ላይ' : 'Online';
  String get offline => isAm ? 'ከመስመር ውጭ' : 'Offline';
}
