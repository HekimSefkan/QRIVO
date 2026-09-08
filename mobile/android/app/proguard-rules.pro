# QRIVO — R8 keep rules for the QR scanner.
#
# WHY THIS FILE EXISTS
# The release build failed at runtime with:
#
#     genericError
#     Attempt to invoke virtual method 'q8 d11.a(r8)' on a null object reference
#
# Those one- and two-letter names are R8-obfuscated identifiers, so something
# resolved reflectively at runtime had been renamed or removed.
#
# THE DEFECT IS UPSTREAM, IN mobile_scanner 5.2.3
# Its consumer rules (android/proguard-rules.pro, applied automatically via
# consumerProguardFiles) read:
#
#     -keep class com.google.mlkit.* { *; }
#     -keep class com.google.android.libraries.barhopper.** { *; }
#     -keep class com.google.photos.* { *; }
#
# In ProGuard/R8 syntax a single-star wildcard matches only classes DIRECTLY in
# that package; it does not cross package separators. ML Kit's barcode classes
# live in com.google.mlkit.vision.barcode.**, which the rule above therefore
# does NOT keep. Only the barhopper rule uses the correct double star.
#
# The rules below are the plugin's own, with the wildcard corrected, plus the
# internal ML Kit packages that ML Kit's own AAR rules reference by name (see
# barcode-scanning-17.2.0's consumer rules, which keep members of
# com.google.android.gms.internal.mlkit_vision_barcode_bundled.zzed -- naming
# that package explicitly, which means R8 must not rename it).
#
# Nothing here is invented: every entry either mirrors a rule shipped by the
# library itself or corrects a wildcard in one.
#
# These rules only prevent RENAMING AND REMOVAL of third-party camera and
# barcode classes. They do not disable minification, they do not touch QRIVO's
# own code, and they change no security behaviour: every attendance decision is
# still made by the server.

# ── mobile_scanner's own rules, with the wildcard corrected ─────────────────
-keep class com.google.mlkit.** { *; }
-keep class com.google.photos.** { *; }
-keep class com.google.android.libraries.barhopper.** { *; }

# ── ML Kit internals that its own AAR rules name explicitly ─────────────────
-keep class com.google.android.gms.internal.mlkit_vision_barcode.** { *; }
-keep class com.google.android.gms.internal.mlkit_vision_barcode_bundled.** { *; }
-keep class com.google.android.gms.internal.mlkit_vision_common.** { *; }

# ML Kit loads its model through native code and reflection; without this the
# entry points can be stripped even when the classes survive.
-keepclasseswithmembernames class * {
    native <methods>;
}

# ── CameraX ─────────────────────────────────────────────────────────────────
# camera-camera2 and camera-core ship these themselves; repeated here because
# the failure is a null reference from exactly this initialisation path and a
# missing default provider produces precisely that symptom.
-keep public class androidx.camera.camera2.Camera2Config$DefaultProvider { *; }
-keep,allowobfuscation,allowshrinking class ** implements androidx.camera.core.impl.Quirk

# ── Warnings ────────────────────────────────────────────────────────────────
# These packages reference optional APIs that are not present in this build.
# Silencing the warnings does not keep or remove anything.
-dontwarn com.google.mlkit.**
-dontwarn com.google.android.gms.internal.mlkit_**
