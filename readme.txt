=== Just GCS Offload ===
Contributors: ivanusto
Tags: google cloud storage, gcs, offload, media library, cdn
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

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

= 1.5.0 =
* Fixed: uploading a single image issued far more GCS requests than it had files. WordPress saves the attachment metadata once per generated sub-size, and the plugin re-uploaded every file already on disk each time, so the request count grew with the square of the sub-size count - a stock install measured 35 uploads for 7 files, with the full-size original sent 8 times. Offloading now happens once per request, at the end, and each file is uploaded exactly once.
* Fixed: with "Delete Local Files" enabled, the original was uploaded and deleted on the first metadata save, which happens *before* WordPress generates the sub-sizes. Sub-size generation then had no source image and silently produced nothing, leaving attachments with no thumbnails at all - every size fell back to the full-size original. Local files are now removed only after sub-size generation has finished.
* New: WordPress 7.1 companion files are offloaded and deleted alongside the attachment - `source_image` (the HEIC kept next to its JPEG derivative) and `animated_video` / `animated_video_poster` (the MP4/WebM an animated GIF is converted to in the browser, and its poster frame).
* Fixed: a sub-size file registered under several size names is uploaded and deleted once instead of once per name. WordPress 7.1 deduplicates sizes that share dimensions, so this is now common.
* New: `just_wp_gcs_companion_meta_keys` filter over the attachment metadata keys treated as companion files.
* Changed: `upload_attachment_files()` is replaced by `queue_attachment_offload()` and `offload_attachment()`. The `_wp_gcs_processing` post meta flag is no longer used; existing rows are harmless leftovers.
* Tested against WordPress 7.1, including the client-side media processing upload flow (`POST /wp/v2/media/{id}/sideload` and `/finalize`) and the `source_image`, `animated_video` and `animated_video_poster` companion files it introduces.

= 1.4.1 =
* Fixed: opening the Media Library could issue one full-size GCS download per attachment on screens that only needed to list files. `get_attached_file` fires on read-only paths too, including `wp_prepare_attachment_for_js()`, which core runs once per attachment for the grid view, the block editor media picker and similar browsers. On a site with "Delete Local Files" enabled, a single page of results turned into dozens of bucket downloads. Rehydration now defaults to off and only runs for WP-CLI and the built-in image editor.
* New: `just_wp_gcs_rehydrate` filter, so tools that genuinely need the local original (thumbnail regenerators, for example) can opt back in.
* Fixed: a failed rehydration is now remembered for an hour instead of being retried on every request, so an object that is missing from the bucket no longer generates repeated 404s.
* Fixed: an attachment carrying GCS metadata but no file path produced a URL of `https://storage.googleapis.com/{bucket}/`, which addresses the bucket rather than an object and is accounted for by GCS as a ListObjects request. Such attachments now fall back to their local URL.
* Fixed: object keys are percent-encoded per path segment, so file names containing `#`, `?` or `%` produce a working URL. Sites using a CDN will see a one-off wave of cache misses for any affected file names.

= 1.4.0 =
* Fixed: requests for a size given as `array( width, height )` always returned the full-size original while reporting the requested dimensions as if they were real. Size resolution is now delegated to WordPress core, so the correct sub-size is served. This was most visible on the site icon, where all four `<head>` icon links pointed at the full-size image.
* New: the site icon is no longer offloaded. Its URLs are printed into the document head on every page load, so it now always stays on the local site rather than depending on the bucket or CDN being reachable.
* New: `just_wp_gcs_skip_attachment` filter to exclude arbitrary attachments from offload. Honored by the media handler, the WP-CLI commands and the bulk sync tools.
* Note: existing installs keep serving an already-offloaded site icon from GCS. To move it back, clear its offload marker with `wp post meta delete <id> _wp_gcs_info`, or simply set the site icon again.

= 1.3.0 =
* New: on-demand rehydration. When a local file is missing but the attachment is offloaded (e.g. after enabling "Delete Local Files"), the plugin automatically downloads it back from GCS the moment WordPress needs the local path — so the built-in image editor and thumbnail regeneration keep working. Downloads only trigger in admin and WP-CLI contexts, never on the front end.
* New: GCS client download support (streamed to disk via a temp file, so failed downloads never leave partial files).
* Updated the "Delete Local Files" setting description to reflect the new behavior.

= 1.2.2 =
* Offload the pre-conversion original image (`original_image` in attachment metadata, e.g. the JPEG source of a WebP conversion or the pre-scaled original) in automatic uploads, the Bulk Upload UI, and WP-CLI sync-all, so the Media Library "original file" link resolves on GCS.
* Delete the original image object from GCS when an attachment is permanently deleted.

= 1.2.1 =
* Bulk Operations UI: the live log now keeps only the most recent 300 lines and renders each batch in a single write, preventing severe browser slowdown on large media libraries (tens of thousands of items).
* Bulk Operations UI: log output is rendered as plain text instead of HTML.
* WP-CLI: sync-metadata and sync-all now process attachments in chunks with meta-cache preloading, and release the in-process object cache between chunks so memory usage stays flat on large media libraries.

= 1.2.0 =
* Added Bulk Operations UI to GCS Offload Settings page (Sync Database Metadata Only and Batch Upload Local Files to GCS) using secure, sequential AJAX requests with progress bar and live log output.

= 1.1.0 =
* Renamed the plugin to "Just GCS Offload" (slug: `just-gcs-offload`) to comply with WordPress.org naming guidelines. Existing settings are preserved.
* Escaping, sanitization, and internationalization improvements throughout.
* Replaced direct `unlink()` calls with `wp_delete_file()`.
* Added a direct file access guard to the WP-CLI integration file.

= 1.0.0 =
* Initial release.
