package org.qidus.asset.qidus_asset_app

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.PrivateKey
import java.security.Signature
import java.security.spec.ECGenParameterSpec

class MainActivity : FlutterFragmentActivity() {
    companion object {
        private const val CHANNEL = "org.qidus.asset/keystore"
        private const val ANDROID_KEYSTORE = "AndroidKeyStore"
        private const val ALIAS_PREFIX = "qidus_asset_ec_"
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, CHANNEL).setMethodCallHandler { call, result ->
            when (call.method) {
                "ensureKeyPair" -> {
                    val deviceUuid = call.argument<String>("deviceUuid")?.trim().orEmpty()
                    if (deviceUuid.isEmpty()) {
                        result.error("INVALID_ARGS", "deviceUuid is required", null)
                        return@setMethodCallHandler
                    }
                    try {
                        val pem = ensureEcKeyPairPem(ALIAS_PREFIX + deviceUuid)
                        result.success(
                            mapOf(
                                "algorithm" to "EC_P256",
                                "publicKeyPem" to pem
                            )
                        )
                    } catch (e: Exception) {
                        result.error("KEYSTORE_ERROR", e.message ?: "Failed to ensure EC keypair", null)
                    }
                }

                "signPayload" -> {
                    val deviceUuid = call.argument<String>("deviceUuid")?.trim().orEmpty()
                    val payload = call.argument<String>("payload") ?: ""
                    if (deviceUuid.isEmpty() || payload.isEmpty()) {
                        result.error("INVALID_ARGS", "deviceUuid and payload are required", null)
                        return@setMethodCallHandler
                    }
                    try {
                        val sigB64 = signWithEcKey(ALIAS_PREFIX + deviceUuid, payload)
                        result.success(sigB64)
                    } catch (e: Exception) {
                        result.error("SIGN_ERROR", e.message ?: "Failed to sign payload", null)
                    }
                }

                else -> result.notImplemented()
            }
        }
    }

    private fun ensureEcKeyPairPem(alias: String): String {
        val keyStore = KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }
        val publicKey = if (keyStore.containsAlias(alias)) {
            keyStore.getCertificate(alias)?.publicKey
                ?: throw IllegalStateException("Keystore entry missing certificate for $alias")
        } else {
            val kpg = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, ANDROID_KEYSTORE)
            val spec = KeyGenParameterSpec.Builder(
                alias,
                KeyProperties.PURPOSE_SIGN or KeyProperties.PURPOSE_VERIFY
            )
                .setAlgorithmParameterSpec(ECGenParameterSpec("secp256r1"))
                .setDigests(KeyProperties.DIGEST_SHA256)
                .build()
            kpg.initialize(spec)
            kpg.generateKeyPair().public
        }

        val b64 = Base64.encodeToString(publicKey.encoded, Base64.NO_WRAP)
        val wrapped = b64.chunked(64).joinToString("\n")
        return "-----BEGIN PUBLIC KEY-----\n$wrapped\n-----END PUBLIC KEY-----"
    }

    private fun signWithEcKey(alias: String, payload: String): String {
        val keyStore = KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }
        if (!keyStore.containsAlias(alias)) {
            ensureEcKeyPairPem(alias)
            keyStore.load(null)
        }
        val privateKey = keyStore.getKey(alias, null) as? PrivateKey
            ?: throw IllegalStateException("Keystore private key not found for $alias")
        val signature = Signature.getInstance("SHA256withECDSA")
        signature.initSign(privateKey)
        signature.update(payload.toByteArray(Charsets.UTF_8))
        return Base64.encodeToString(signature.sign(), Base64.NO_WRAP)
    }
}
