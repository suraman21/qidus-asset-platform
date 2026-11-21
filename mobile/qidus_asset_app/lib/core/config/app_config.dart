import 'package:flutter/material.dart';

class AppConfig {
  static const String defaultBaseUrl = 'https://qidusasset.arkeonethiopia.com/api/v1';

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
      scaffoldBackgroundColor: const Color(0xFFF3F4F6),
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
        surface: const Color(0xFF10131C),
      ),
      scaffoldBackgroundColor: const Color(0xFF07080D),
      appBarTheme: const AppBarTheme(
        backgroundColor: Color(0xFF10131C),
        foregroundColor: Color(0xFFF8FAFC),
        elevation: 0,
      ),
      cardTheme: CardTheme(
        color: const Color(0xFF161B28),
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: const BorderSide(color: Color(0x17FFFFFF)),
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
  String get homeTab => isAm ? 'መነሻ' : 'Home';
  String get scanTab => isAm ? 'ስካን' : 'Scan';
  String get handoverTab => isAm ? 'ርክክብ' : 'Custody';
  String get eventsTab => isAm ? 'ዝግጅት' : 'Events';
  String get outboxTab => isAm ? 'ማመሳሰያ' : 'Outbox';
  String get syncNow => isAm ? 'አመሳስል' : 'Sync Now';
  String get online => isAm ? 'መስመር ላይ' : 'Online';
  String get offline => isAm ? 'ከመስመር ውጭ' : 'Offline';
  String get readyOffline => isAm ? 'ከመስመር ውጭ ዝግጁ' : 'Ready Offline';
  String get incompletePkg => isAm ? 'ያልተሟላ ጥቅል' : 'Incomplete';
  String get scanMemberPrompt => isAm ? 'የአባል QR ይቃኙ (ምንም አባል አልተመረጠም)' : 'Scan Member QR (No member selected)';
  String get issueMode => isAm ? 'መስጫ (Issue)' : 'Issue Mode';
  String get returnMode => isAm ? 'መመለሻ (Return)' : 'Return Mode';
  String get confirmIssue => isAm ? 'ሁሉንም ስጥ እና ቀጣዩን ስካን አድርግ' : 'Confirm Issue & Scan Next';
  String get confirmReturn => isAm ? 'ተመለሰ ብለህ መዝግብ' : 'Mark Returned & Scan Next';
  String get selectAsset => isAm ? 'ንብረት ይምረጡ' : 'Select Asset';
  String get selectMember => isAm ? 'የተረጋገጠ አባል ይምረጡ (SSMS)' : 'Select Verified Member (SSMS)';
  String get nonMemberToggle => isAm ? 'አባል ያልሆነ / የሰራተኛ ርክክብ' : 'Explicit non-member / staff custody';
  String get confirmHandover => isAm ? 'የተፈረመ ርክክብ አረጋግጥ' : 'Confirm Signed Handover';
}
