package org.qidus.asset.qidus_asset_app

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.Signature
import java.security.spec.ECGenParameterSpec

/**
 * Hardware-backed Android Keystore EC P-256 bridge (FR-19).
 * Generates non-exportable private keys inside AndroidKeyStore, exports X.509 PEM public keys
 * compatible with PHP openssl_verify(..., OPENSSL_ALGO_SHA256), and signs canonical JSON payloads.
 */
class MainActivity : FlutterFragmentActivity() {
    private val channelName = "org.qidus.asset/keystore"
    private val keyAliasPrefix = "qidus_asset_ec_"

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, channelName)
            .setMethodCallHandler { call, result ->
                try {
                    when (call.method) {
                        "ensureKeyPair" -> {
                            val deviceUuid = call.argument<String>("deviceUuid")
                                ?: return@setMethodCallHandler result.error("ARG", "deviceUuid required", null)
                            val pem = ensureEcKeyPair(keyAliasPrefix + deviceUuid)
                            result.success(mapOf("algorithm" to "EC_P256", "publicKeyPem" to pem))
                        }
                        "signPayload" -> {
                            val deviceUuid = call.argument<String>("deviceUuid")
                                ?: return@setMethodCallHandler result.error("ARG", "deviceUuid required", null)
                            val payload = call.argument<String>("payload")
                                ?: return@setMethodCallHandler result.error("ARG", "payload required", null)
                            val sigB64 = signWithKey(keyAliasPrefix + deviceUuid, payload.toByteArray(Charsets.UTF_8))
                            result.success(sigB64)
                        }
                        else -> result.notImplemented()
                    }
                } catch (e: Exception) {
                    result.error("KEYSTORE_ERROR", e.message ?: "Keystore operation failed", null)
                }
            }
    }

    private fun ensureEcKeyPair(alias: String): String {
        val ks = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        if (!ks.containsAlias(alias)) {
            val kpg = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, "AndroidKeyStore")
            val spec = KeyGenParameterSpec.Builder(
                alias,
                KeyProperties.PURPOSE_SIGN or KeyProperties.PURPOSE_VERIFY
            )
                .setAlgorithmParameterSpec(ECGenParameterSpec("secp256r1"))
                .setDigests(KeyProperties.DIGEST_SHA256)
                .build()
            kpg.initialize(spec)
            kpg.generateKeyPair()
        }
        val cert = ks.getCertificate(alias)
        val der = cert.publicKey.encoded
        val b64 = Base64.encodeToString(der, Base64.NO_WRAP)
        val wrapped = b64.chunked(64).joinToString("\n")
        return "-----BEGIN PUBLIC KEY-----\n$wrapped\n-----END PUBLIC KEY-----"
    }

    private fun signWithKey(alias: String, data: ByteArray): String {
        val ks = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        val entry = ks.getEntry(alias, null) as? KeyStore.PrivateKeyEntry
            ?: throw IllegalStateException("Key alias $alias not initialized")
        val signer = Signature.getInstance("SHA256withECDSA")
        signer.initSign(entry.privateKey)
        signer.update(data)
        val raw = signer.sign()
        return Base64.encodeToString(raw, Base64.NO_WRAP)
    }
}
