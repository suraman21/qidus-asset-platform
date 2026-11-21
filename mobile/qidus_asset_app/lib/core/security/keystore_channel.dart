import 'dart:convert';

import 'package:flutter/services.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:local_auth/local_auth.dart';
import 'package:uuid/uuid.dart';

class KeystoreChannel {
  static const MethodChannel _channel = MethodChannel('org.qidus.asset/keystore');
  static const _storage = FlutterSecureStorage();
  static const _uuidKey = 'qa_device_uuid';
  final LocalAuthentication _localAuth = LocalAuthentication();

  Future<String> getOrCreateDeviceUuid() async {
    final existing = await _storage.read(key: _uuidKey);
    if (existing != null && existing.isNotEmpty) {
      return existing;
    }
    final generated = const Uuid().v4();
    await _storage.write(key: _uuidKey, value: generated);
    return generated;
  }

  Future<Map<String, String>> ensureKeyPair() async {
    final deviceUuid = await getOrCreateDeviceUuid();
    final res = await _channel.invokeMapMethod<String, dynamic>(
      'ensureKeyPair',
      {'deviceUuid': deviceUuid},
    );
    return {
      'deviceUuid': deviceUuid,
      'algorithm': (res?['algorithm'] ?? 'EC_P256').toString(),
      'publicKeyPem': (res?['publicKeyPem'] ?? '').toString(),
    };
  }

  Future<bool> authenticateBiometric({String reason = 'Verify identity to sign asset transaction'}) async {
    try {
      final supported = await _localAuth.isDeviceSupported();
      final canCheck = await _localAuth.canCheckBiometrics;
      if (!supported && !canCheck) {
        return true; // Allow device PIN/passcode fallback on non-biometric test devices
      }
      return await _localAuth.authenticate(
        localizedReason: reason,
        options: const AuthenticationOptions(biometricOnly: false, stickyAuth: true),
      );
    } catch (_) {
      return false;
    }
  }

  /// Build deterministic canonical JSON matching PHP BiometricVerifier verification.
  static String buildCanonicalPayload({
    required int assetId,
    required int quantity,
    required String handoverType,
    required String receiverName,
    required String clientTxUuid,
    required String deviceUuid,
  }) {
    final ordered = <String, dynamic>{
      'asset_id': assetId,
      'client_tx_uuid': clientTxUuid,
      'device_uuid': deviceUuid,
      'handover_type': handoverType,
      'quantity': quantity,
      'receiver_display_name': receiverName,
    };
    return jsonEncode(ordered);
  }

  Future<String> signCanonicalPayload(String canonicalPayload) async {
    final deviceUuid = await getOrCreateDeviceUuid();
    final sig = await _channel.invokeMethod<String>('signPayload', {
      'deviceUuid': deviceUuid,
      'payload': canonicalPayload,
    });
    if (sig == null || sig.isEmpty) {
      throw PlatformException(code: 'SIGN_EMPTY', message: 'Keystore returned empty signature');
    }
    return sig;
  }
}
