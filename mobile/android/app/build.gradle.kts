import java.io.FileInputStream
import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// ── Release signing ─────────────────────────────────────────────────────────
// The keystore and its passwords live OUTSIDE this repository and are never
// committed. The path to key.properties comes from the QRIVO_KEY_PROPERTIES
// environment variable; android/key.properties is a fallback for other
// developers. When neither is present the release build falls back to debug
// signing, so CI and anyone without the production key can still build.
val qrivoKeyPropsFile: File? =
    (System.getenv("QRIVO_KEY_PROPERTIES")?.let { File(it) }
        ?: rootProject.file("key.properties"))
        .takeIf { it.exists() }

val qrivoKeyProps = Properties().apply {
    qrivoKeyPropsFile?.let { f -> FileInputStream(f).use { load(it) } }
}

val hasReleaseSigning = qrivoKeyProps.getProperty("storeFile") != null

android {
    namespace = "com.qrivo.qrivo_mobile"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "com.qrivo.qrivo_mobile"

        // QRIVO's own API floor, independent of whatever Flutter defaults to:
        //
        //   21  mobile_scanner 5.2.3 (android/build.gradle: minSdkVersion 21)
        //   23  flutter_secure_storage 9.2.4 only uses EncryptedSharedPreferences
        //       from API 23 — see FlutterSecureStorage.java, which gates it on
        //       `Build.VERSION.SDK_INT >= Build.VERSION_CODES.M`. Below 23 it
        //       silently falls back to a weaker store, which would quietly break
        //       the storage guarantee mobile/README.md claims for the token pair.
        //
        // Flutter 3.47.2 already defaults to 24, so this raises nothing today; it
        // exists so a future Flutter default cannot drop us under 23 unnoticed.
        minSdk = maxOf(flutter.minSdkVersion, 23)
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (hasReleaseSigning) {
            create("release") {
                storeFile     = file(qrivoKeyProps.getProperty("storeFile"))
                storePassword = qrivoKeyProps.getProperty("storePassword")
                keyAlias      = qrivoKeyProps.getProperty("keyAlias")
                keyPassword   = qrivoKeyProps.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            // Signed with the real release key when key.properties is available,
            // otherwise with the debug key so the build still succeeds.
            signingConfig = if (hasReleaseSigning) {
                signingConfigs.getByName("release")
            } else {
                signingConfigs.getByName("debug")
            }
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
