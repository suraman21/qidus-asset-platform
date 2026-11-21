package org.qidus.asset.qidus_asset_app

import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyPermanentlyInvalidatedException
import android.security.keystore.KeyProperties
import android.util.Base64
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
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
        private const val ALIAS_PREFIX = "qidus_asset_ec_v2_"
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
                                "publicKeyPem" to pem,
                                "biometricBound" to true
                            )
                        )
                    } catch (e: Exception) {
                        result.error("KEYSTORE_ERROR", e.message ?: "Failed to ensure authentication-bound EC keypair", null)
                    }
                }

                "signWithBiometric" -> {
                    val deviceUuid = call.argument<String>("deviceUuid")?.trim().orEmpty()
                    val payload = call.argument<String>("payload") ?: ""
                    val reason = call.argument<String>("reason") ?: "Authenticate to sign custody transaction"
                    if (deviceUuid.isEmpty() || payload.isEmpty()) {
                        result.error("INVALID_ARGS", "deviceUuid and payload are required", null)
                        return@setMethodCallHandler
                    }
                    signWithBiometricPrompt(ALIAS_PREFIX + deviceUuid, payload, reason, result)
                }

                else -> result.notImplemented()
            }
        }
    }

    private fun canAuthenticateStrongOrCredential(): Boolean {
        val mgr = BiometricManager.from(this)
        val res = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            mgr.canAuthenticate(
                BiometricManager.Authenticators.BIOMETRIC_STRONG or BiometricManager.Authenticators.DEVICE_CREDENTIAL
            )
        } else {
            @Suppress("DEPRECATION")
            mgr.canAuthenticate()
        }
        return res == BiometricManager.BIOMETRIC_SUCCESS
    }

    private fun ensureEcKeyPairPem(alias: String): String {
        if (!canAuthenticateStrongOrCredential()) {
            throw IllegalStateException("Device biometric or screen lock authentication must be enrolled before creating a signing key.")
        }
        val keyStore = KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }
        val publicKey = if (keyStore.containsAlias(alias)) {
            keyStore.getCertificate(alias)?.publicKey
                ?: throw IllegalStateException("Keystore entry missing certificate for $alias")
        } else {
            val kpg = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, ANDROID_KEYSTORE)
            val builder = KeyGenParameterSpec.Builder(
                alias,
                KeyProperties.PURPOSE_SIGN or KeyProperties.PURPOSE_VERIFY
            )
                .setAlgorithmParameterSpec(ECGenParameterSpec("secp256r1"))
                .setDigests(KeyProperties.DIGEST_SHA256)
                .setUserAuthenticationRequired(true)

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
                builder.setInvalidatedByBiometricEnrollment(true)
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                builder.setUserAuthenticationParameters(
                    0,
                    KeyProperties.AUTH_BIOMETRIC_STRONG or KeyProperties.AUTH_DEVICE_CREDENTIAL
                )
            } else {
                @Suppress("DEPRECATION")
                builder.setUserAuthenticationValidityDurationSeconds(-1)
            }

            kpg.initialize(builder.build())
            kpg.generateKeyPair().public
        }

        val b64 = Base64.encodeToString(publicKey.encoded, Base64.NO_WRAP)
        val wrapped = b64.chunked(64).joinToString("\n")
        return "-----BEGIN PUBLIC KEY-----\n$wrapped\n-----END PUBLIC KEY-----"
    }

    private fun signWithBiometricPrompt(
        alias: String,
        payload: String,
        reason: String,
        result: MethodChannel.Result
    ) {
        if (!canAuthenticateStrongOrCredential()) {
            result.error("AUTH_UNAVAILABLE", "Device biometric or screen lock authentication is not enrolled.", null)
            return
        }
        try {
            val keyStore = KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }
            if (!keyStore.containsAlias(alias)) {
                ensureEcKeyPairPem(alias)
                keyStore.load(null)
            }
            val privateKey = keyStore.getKey(alias, null) as? PrivateKey
                ?: throw IllegalStateException("Keystore private key not found for $alias")
            val signature = Signature.getInstance("SHA256withECDSA")
            signature.initSign(privateKey)

            val executor = ContextCompat.getMainExecutor(this)
            var replied = false
            val prompt = BiometricPrompt(
                this,
                executor,
                object : BiometricPrompt.AuthenticationCallback() {
                    override fun onAuthenticationSucceeded(authResult: BiometricPrompt.AuthenticationResult) {
                        if (replied) return
                        replied = true
                        try {
                            val unlockedSig = authResult.cryptoObject?.signature ?: signature
                            unlockedSig.update(payload.toByteArray(Charsets.UTF_8))
                            val sigB64 = Base64.encodeToString(unlockedSig.sign(), Base64.NO_WRAP)
                            result.success(sigB64)
                        } catch (e: Exception) {
                            result.error("SIGN_ERROR", e.message ?: "CryptoObject signing failed", null)
                        }
                    }

                    override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
                        if (replied) return
                        replied = true
                        result.error("AUTH_CANCELLED", errString.toString(), null)
                    }

                    override fun onAuthenticationFailed() {
                        // Prompt remains open for retry
                    }
                }
            )

            val promptInfoBuilder = BiometricPrompt.PromptInfo.Builder()
                .setTitle("QIDUS ASSET Signature")
                .setSubtitle(reason)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                promptInfoBuilder.setAllowedAuthenticators(
                    BiometricManager.Authenticators.BIOMETRIC_STRONG or BiometricManager.Authenticators.DEVICE_CREDENTIAL
                )
            } else {
                @Suppress("DEPRECATION")
                promptInfoBuilder.setDeviceCredentialAllowed(true)
            }

            prompt.authenticate(promptInfoBuilder.build(), BiometricPrompt.CryptoObject(signature))
        } catch (e: KeyPermanentlyInvalidatedException) {
            result.error("KEY_INVALIDATED", "Biometric enrollment changed; re-register this device key.", null)
        } catch (e: Exception) {
            result.error("SIGN_ERROR", e.message ?: "Failed to initialize biometric signature", null)
        }
    }
}
