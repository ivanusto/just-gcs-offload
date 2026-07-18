=== Just GCS Offload ===
Contributors: ivanusto
Tags: google cloud storage, gcs, offload, media library, cdn
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A lightweight, dependency-free plugin that offloads the WordPress Media Library to a Google Cloud Storage bucket.

== Description ==

Just GCS Offload is a lightweight WordPress plugin that offloads your Media Library to a Google Cloud Storage (GCS) bucket.

It implements a lightweight GCS REST client in pure PHP using the WordPress HTTP API and native OpenSSL JWT signing for Service Account authentication, completely bypassing the massive official Google Cloud SDK.

= Features =

* **Zero external dependencies**: Weighs only a few dozen kilobytes. No bulky `vendor/` folder or external libraries.
* **Service Account JWT authentication**: Authenticates securely using a Google Service Account JSON key, utilizing native OpenSSL (`openssl_sign`) for RS256 signing.
* **Automatic media offloading**: Automatically uploads new images and all generated sub-sizes (thumbnails) to GCS during upload.
* **URL and srcset rewriting**: Seamlessly rewrites image URLs and responsive `srcset` paths to point to GCS or a custom CDN domain.
* **Optional local cleanup**: Optionally deletes the local server copy of uploaded files to save disk space.
* **Automatic deletion**: Automatically deletes original and resized files from GCS when an attachment is permanently deleted from the WordPress admin.
* **WP-CLI integration**: Provides command-line tools to migrate existing media library items and sync database metadata.
* **Connection test**: A simple button in settings to test read/write/delete permissions.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/just-gcs-offload` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to **Settings -> GCS Offload** and paste your Google Service Account JSON key and bucket name.
4. Click **Run Connection Test** to verify your credentials and permissions.

== Frequently Asked Questions ==

= Does this plugin require the Google Cloud SDK? =

No. The plugin implements a minimal GCS REST client in pure PHP with no external dependencies.

= How should I configure my bucket for public access? =

Either enable Uniform bucket-level access and grant the Storage Object Viewer role to `allUsers` (recommended), or use Fine-grained access control and enable the "Set Public ACL" option in the plugin settings.

== Changelog ==

= 1.2.0 =
* Added Bulk Operations UI to GCS Offload Settings page (Sync Database Metadata Only and Batch Upload Local Files to GCS) using secure, sequential AJAX requests with progress bar and live log output.

= 1.1.0 =
* Renamed the plugin to "Just GCS Offload" (slug: `just-gcs-offload`) to comply with WordPress.org naming guidelines. Existing settings are preserved.
* Escaping, sanitization, and internationalization improvements throughout.
* Replaced direct `unlink()` calls with `wp_delete_file()`.
* Added a direct file access guard to the WP-CLI integration file.

= 1.0.0 =
* Initial release.
