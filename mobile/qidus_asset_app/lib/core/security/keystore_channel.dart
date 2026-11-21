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

  Future<Map<String, dynamic>> ensureKeyPair() async {
    final deviceUuid = await getOrCreateDeviceUuid();
    final res = await _channel.invokeMapMethod<String, dynamic>(
      'ensureKeyPair',
      {'deviceUuid': deviceUuid},
    );
    return {
      'deviceUuid': deviceUuid,
      'algorithm': (res?['algorithm'] ?? 'EC_P256').toString(),
      'publicKeyPem': (res?['publicKeyPem'] ?? '').toString(),
      'biometricBound': res?['biometricBound'] == true,
    };
  }

  /// Strict biometric / device-credential check: NEVER silently returns true when unsupported.
  Future<bool> isBiometricOrCredentialAvailable() async {
    try {
      final supported = await _localAuth.isDeviceSupported();
      final canCheck = await _localAuth.canCheckBiometrics;
      return supported || canCheck;
    } catch (_) {
      return false;
    }
  }

  Future<bool> authenticateBiometric({String reason = 'Verify identity to sign asset transaction'}) async {
    try {
      final available = await isBiometricOrCredentialAvailable();
      if (!available) {
        return false;
      }
      return await _localAuth.authenticate(
        localizedReason: reason,
        options: const AuthenticationOptions(biometricOnly: false, stickyAuth: true),
      );
    } catch (_) {
      return false;
    }
  }

  /// Canonical Payload v2 matching PHP AssetWorkflowService + BiometricVerifier.
  static String buildCanonicalPayload({
    required int assetId,
    required int quantity,
    required String handoverType,
    required String receiverName,
    required String clientTxUuid,
    required String deviceUuid,
    int? actorUserId,
    String? externalMemberId,
    int? variantId,
    int? eventId,
    int? stationId,
    String? leaseJti,
    String? deviceTxAt,
  }) {
    final ordered = <String, dynamic>{
      'actor_user_id': actorUserId,
      'asset_id': assetId,
      'client_tx_uuid': clientTxUuid,
      'device_tx_at': deviceTxAt ?? DateTime.now().toUtc().toIso8601String(),
      'device_uuid': deviceUuid,
      'event_id': eventId,
      'external_member_id': externalMemberId,
      'handover_type': handoverType,
      'lease_jti': leaseJti,
      'quantity': quantity,
      'receiver_display_name': receiverName,
      'station_id': stationId,
      'v': 2,
      'variant_id': variantId,
    };
    return jsonEncode(ordered);
  }

  /// Unlock the authentication-bound Keystore EC_P256 private key via Android BiometricPrompt CryptoObject.
  Future<String> signCanonicalPayload(
    String canonicalPayload, {
    String reason = 'Confirm custody transaction signature',
  }) async {
    final deviceUuid = await getOrCreateDeviceUuid();
    final sig = await _channel.invokeMethod<String>('signWithBiometric', {
      'deviceUuid': deviceUuid,
      'payload': canonicalPayload,
      'reason': reason,
    });
    if (sig == null || sig.isEmpty) {
      throw PlatformException(code: 'SIGN_EMPTY', message: 'Keystore returned empty signature');
    }
    return sig;
  }
}
