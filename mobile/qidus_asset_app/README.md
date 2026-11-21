# QIDUS ASSET Android Mobile Companion (`mobile/qidus_asset_app`)

Offline-first Flutter Android application for Department 1 (`asset_dept`) covering PRD v1.0 `FR-02`, `FR-03`, `FR-04`, `FR-08`, `FR-09`, `FR-16`, `FR-18`, and `FR-19`.

## Features
- **Separated Light & Dark Themes** matching the web console (`#1E3A8A` + `#D97706`).
- **Offline-First SQLite + Durable Outbox** (`sqflite`) for handovers, stock movements, inspection scans, and rapid multi-station event distribution.
- **Hardware-Backed Android Keystore Signing** (`MainActivity.kt` `EC_P256` + `local_auth` biometric verification) exporting X.509 PEM public keys verified by PHP `BiometricVerifier`.
- **Dual Evidence Handovers**: Drawn signature canvas (`signature` PNG base64) and/or Keystore cryptographic signature.
- **Continuous QR Scanning & Rapid Event Mode**: Zero-latency local station stock deduction with automatic batch reconciliation (`POST /events/{id}/sync-batch`).

## Build
```bash
flutter pub get
flutter test
flutter build apk --release
```
