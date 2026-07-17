# WordPress on GCS Implementation Guide (with just-wp-gcs)

[繁體中文版說明請參見 GUIDE.zh-TW.md](GUIDE.zh-TW.md)

This guide provides a comprehensive path to migrating your WordPress Media Library to Google Cloud Storage (GCS) using the `just-wp-gcs` plugin.

The primary goal of this project is to provide a lightweight, high-performance solution that avoids PHP timeouts and memory constraints when dealing with massive media libraries (e.g., tens of GiBs / 40,000+ files).

---

## 1. Google Cloud IAM Permissions (Quick Command)

To allow WordPress to authenticate and perform operations on your GCS bucket, you must grant the **Storage Admin** (`roles/storage.admin`) role to your Google Service Account. This ensures the plugin can read and write objects as well as verify bucket metadata.

Run the following `gcloud` command to quickly bind the role:

```bash
gcloud projects add-iam-policy-binding [PROJECT_ID] \
    --member="serviceAccount:[SERVICE_ACCOUNT_EMAIL]" \
    --role="roles/storage.admin"
```
*(Replace `[PROJECT_ID]` and `[SERVICE_ACCOUNT_EMAIL]` with your actual project ID and Service Account email address.)*

---

## 2. First-Time Media Library Migration (rsync Command)

Before activating the plugin, it is recommended to sync your existing local `uploads` folder to GCS. **Ensuring correct path alignment is critical!**

Run this command from your WordPress root directory:

```bash
# Using gsutil (multi-threaded, recommended)
gsutil -m rsync -r wp-content/uploads/ gs://[BUCKET_NAME]/[PREFIX]

# Or using the newer gcloud storage CLI
gcloud storage rsync wp-content/uploads/ gs://[BUCKET_NAME]/[PREFIX] --recursive
```
*(Replace `[BUCKET_NAME]` and `[PREFIX]` with your GCS bucket name and folder path prefix configured in the plugin settings.)*

**Why use rsync?**
Using `rsync` via CLI is extremely fast, utilizes parallel threads, and guarantees that your year/month directory structures (e.g., `/2025`, `/2026`) are preserved exactly.

---

## 3. Cloudflare DNS & CDN Configuration

To serve your GCS files using a custom domain (e.g., `static.yblog.org`) through Cloudflare CDN, configure it as follows:

1. **Bucket Naming**: Your GCS bucket name **MUST** be identical to your domain/subdomain name (e.g., `static.yblog.org`).
2. **DNS Record in Cloudflare**:
   * **Type**: CNAME
   * **Name**: `static` (or your chosen subdomain)
   * **Target**: `c.storage.googleapis.com`
   * **Proxy status**: Proxied (Orange Cloud Enabled)
3. **SSL/TLS Encryption Mode**: Set Cloudflare SSL/TLS encryption mode to **Full** or **Full (Strict)**.

**Advantages**:
Cloudflare will handle the SSL handshake and cache content at edge locations. You don't need to configure SSL certificates in Google Cloud, and it completely hides the raw `storage.googleapis.com` URL from public visitors, enhancing security.

---

## 4. Domain Ownership Verification (Troubleshooting)

If you receive a `403 Forbidden` error (verification failed) when attempting to create a GCS bucket named after a domain:

1. Go to [Google Search Console](https://search.google.com/search-console).
2. Add your domain property and verify ownership using a **DNS TXT record**.
3. Once ownership is verified under your Google account, GCS will permit you to create buckets containing dots (e.g. `static.yblog.org`).

---

## Developer Insights

Traditional media offload plugins often run into Redis object cache conflicts and PHP request timeouts when processing huge media libraries on-the-fly. 

Our recommended methodology:
1. Delegate **initial/bulk file synchronization** to the high-performance CLI utility `gcloud rsync`.
2. Let **`just-wp-gcs`** handle dynamic new uploads and real-time database URL rewrites.
This hybrid approach keeps your WordPress site light, fast, and completely error-free.
