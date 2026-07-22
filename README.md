# Just GCS Offload

[繁體中文版說明請參見 README.zh-TW.md](README.zh-TW.md) | [WordPress on GCS Implementation Guide](GUIDE.md) | [WordPress on GCS 中文實作指南](GUIDE.zh-TW.md)

A lightweight, dependency-free WordPress plugin to offload your Media Library to a Google Cloud Storage (GCS) bucket.

> [!NOTE]
> Looking for Amazon S3 or S3-compatible (R2, B2, Spaces, MinIO) storage support instead? Check out our sister project: [Just S3 Offload](https://github.com/ivanusto/just-s3-offload).

Unlike other bloated cloud storage plugins, **Just GCS Offload** is designed to be as small and efficient as possible. It implements a lightweight GCS REST client in pure PHP using cURL and native OpenSSL JWT signing for Service Account authentication, completely bypassing the massive official Google Cloud SDK.

## Features

* **Zero External Dependencies**: Weighs only a few dozen kilobytes. No bulky `vendor/` folder or external libraries.
* **Service Account JWT Authentication**: Authenticates securely using a Google Service Account JSON key, utilizing native OpenSSL (`openssl_sign`) for RS256 signing.
* **Automatic Media Offloading**: Automatically uploads new images and all generated sub-sizes (thumbnails) to GCS during upload.
* **URL & Srcset Rewriting**: Seamlessly rewrites image URLs and responsive `srcset` paths to point to GCS or a custom CDN domain.
* **Optional Local Cleanup**: Optionally deletes the local server copy of uploaded files to save disk space.
* **Automatic Deletion**: Automatically deletes original and resized files from GCS when an attachment is permanently deleted from the WordPress admin.
* **WP-CLI Integration**: Provides powerful command-line tools to migrate existing media library items and sync database metadata.
* **Connection Test**: A simple button in settings to test read/write/delete permissions.

## Requirements

* PHP 7.4 or higher
* PHP OpenSSL extension enabled (for JWT signing)
* WordPress 6.0 or higher

## Installation

1. Download the latest `just-gcs-offload.zip` from the [Releases](https://github.com/ivanusto/just-gcs-offload/releases) page.
2. In your WordPress admin, go to **Plugins -> Add New -> Upload Plugin**, select the zip file, and click **Install Now**.
3. Activate the plugin.

## GCS Bucket Configuration

To ensure your offloaded images are publicly readable, configure your GCS bucket using one of the following methods:

### Method A: Uniform Bucket-Level Access (Recommended)
1. Enable **Uniform bucket-level access** in your bucket's permissions.
2. Grant the **Storage Object Viewer** (`roles/storage.objectViewer`) IAM role to the principal **`allUsers`**.
3. In WordPress settings, keep the "Set Public ACL" option **disabled**.

### Method B: Fine-grained Access Control
1. Enable **Fine-grained access control** in your bucket.
2. In WordPress settings, enable the **"Set Public ACL"** option. This will apply a `publicRead` ACL to every uploaded object.

## Configuration Settings

Navigate to **Settings -> GCS Offload** in your WordPress dashboard to configure the following settings:

* **Service Account JSON Key**: Paste the complete contents of your Service Account private key JSON file. The Service Account must have permissions to create and delete GCS objects.
* **GCS Bucket Name**: Enter the target GCS bucket name (e.g., `my-wordpress-bucket`).
* **Folder Path Prefix**: (Optional) Subfolder path inside the bucket (e.g., `wp-content/uploads`). Do not add leading or trailing slashes.
* **Custom Domain / CDN URL**: (Optional) Custom domain or CDN mapping (e.g., `https://cdn.example.com`). If empty, the default GCS URL will be used: `https://storage.googleapis.com/{bucket}`.
* **Cache-Control Header**: The Cache-Control header applied to uploaded objects (defaults to `public, max-age=31536000`).
* **Set Public ACL**: Check this if using Fine-grained access control on your bucket.
* **Delete Local Files**: Check this to delete local copies of files after uploading them to GCS. *Note: Deleting local files may prevent the built-in WordPress image editor (crop/rotate) from working.*

Click **Run Connection Test** to verify that your credentials and permissions are configured correctly.

## WP-CLI Commands

For developers and system administrators, the plugin provides custom WP-CLI commands to perform batch operations and migrations.

### 1. Sync Database Metadata
If you have already copied your files to the GCS bucket using tools like `gsutil` or `rclone`, use this command to generate the offload metadata (`_wp_gcs_info`) in the database so WordPress rewrites the URLs.
```bash
wp gcs-offload sync-metadata [--bucket=<bucket>] [--prefix=<prefix>] [--overwrite]
```

### 2. Batch Upload Local Files
Scan all existing media library attachments, upload them to GCS, and update their database records.
```bash
wp gcs-offload sync-all [--delete-local] [--overwrite]
```
* Use `--delete-local` to remove local copies after successful upload.
* Use `--overwrite` to re-upload files that are already marked as synced.

## Troubleshooting

If you encounter any issues (such as a fatal error or blank screen in the Media Library):
1. Enable debugging in your `wp-config.php`:
   ```php
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   define( 'WP_DEBUG_DISPLAY', false );
   ```
2. Re-trigger the error.
3. Check the logs:
   * WordPress Debug Log: `wp-content/debug.log`
   * Web Server Error Logs: `/var/log/apache2/error.log` or `/var/log/nginx/error.log`

## License

This project is licensed under the MIT License.
