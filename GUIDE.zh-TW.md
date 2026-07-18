# WordPress on GCS 實作指南 (搭配 just-gcs-offload)

[For English version, see GUIDE.md](GUIDE.md)

本指南提供使用 `just-gcs-offload` 外掛將 WordPress 媒體庫無縫遷移至 Google Cloud Storage (GCS) 的完整環境配置指南。

> [!NOTE]
> 需要 Amazon S3 或 S3 相容儲存（R2、B2、Spaces、MinIO）支援嗎？請參考我們的姊妹專案：[Just S3 Offload](https://github.com/ivanusto/just-s3-offload)。

本專案的核心目標在於提供一個極輕量、高效能的解決方案，避免在處理海量媒體庫（如數十 GiB 空間 / 40,000+ 個檔案）時，因背景掃描而產生 PHP 超時或記憶體溢出的問題。

---

## 1. Google Cloud IAM 權限設定 (最速指令)

為了讓 WordPress 能夠正常讀寫 GCS 儲存桶，建議為您的 Google 服務帳戶 (Service Account) 配置 **Storage Admin** (`roles/storage.admin`) 權限（以確保外掛能讀寫物件並正確讀取儲存桶中繼資料）。

請使用您的 `gcloud` 指令列工具執行以下最速綁定權限指令：

```bash
gcloud projects add-iam-policy-binding [PROJECT_ID] \
    --member="serviceAccount:[SERVICE_ACCOUNT_EMAIL]" \
    --role="roles/storage.admin"
```
*(請將 `[PROJECT_ID]` 替換為您的專案 ID，並將 `[SERVICE_ACCOUNT_EMAIL]` 替換為您的服務帳戶 Email 網址)*

---

## 2. 首次媒體庫搬移教學 (rsync 指令)

在啟用外掛前，建議先將本機現有的 `uploads` 資料夾完整同步到 GCS。**請注意：資料夾結構的路徑對齊非常重要！**

請在您的 WordPress 根目錄下執行以下指令：

```bash
# 使用 gsutil 同步（支援多執行緒，推薦）
gsutil -m rsync -r wp-content/uploads/ gs://[BUCKET_NAME]/[PREFIX]

# 或是使用較新的 gcloud storage CLI 工具
gcloud storage rsync wp-content/uploads/ gs://[BUCKET_NAME]/[PREFIX] --recursive
```
*(請將 `[BUCKET_NAME]` 與 `[PREFIX]` 替換為您在 GCS 建立的儲存桶名稱與設定的前綴路徑)*

**使用 rsync 的優點：**
雲端同步速度極快（多執行緒），且能確保所有年份/月份資料夾（如 `/2025`, `/2026`）的結構在儲存桶中完全對齊。

---

## 3. Cloudflare DNS 與 CDN 配置

若您希望使用自訂網域（例如 `static.yblog.org`）直接指向並分流 GCS 的檔案，請依照下列步驟設定：

1. **儲存桶命名**：您的 GCS 儲存桶名稱 **必須** 與您的自訂域名完全一致（例如：`static.yblog.org`）。
2. **Cloudflare DNS 設定**：
   * **類型 (Type)**：`CNAME`
   * **名稱 (Name)**：`static` (或您的子網域)
   * **目標 (Target)**：`c.storage.googleapis.com`
   * **代理狀態 (Proxy status)**：已代理 (開啟橘色雲朵)
3. **SSL/TLS 加密模式**：在 Cloudflare 中將 SSL/TLS 加密模式設定為 **Full** 或 **Full (Strict)**。

**設定優勢：**
透過 Cloudflare CDN 代理，您不需要在 Google Cloud 端處理繁瑣的 SSL/TLS 憑證申請與綁定，且能自動啟用快取分流、隱藏儲存桶的真實路徑，安全性大幅提升。

---

## 4. 網域所有權驗證 (Troubleshooting)

如果您在建立名稱包含網點的儲存桶（例如 `static.yblog.org`）時，遇到 `403 Forbidden` 錯誤（身分驗證失敗）：

1. 請前往 [Google Search Console](https://search.google.com/search-console)。
2. 新增您的網域資源，並使用 **DNS TXT 記錄** 驗證該網域的所有權。
3. 驗證通過後，Google 才會允許您在 GCS 中建立包含網點字元的域名儲存桶。

---

## 開發者心得

傳統 WordPress 媒體庫同步外掛常因在背景跑佇列或掃描，容易在大型網站上產生 Redis 快取衝突或 PHP execution time timeout。

我們強烈建議以下分流做法：
1. **歷史檔案與大批搬移**：交給高效率的命令列工具 `gcloud rsync` 處理。
2. **動態上傳與網址改寫**：交給極輕量的 **`just-gcs-offload`** 即時處理。
這套實作方法能確保您的 WordPress 主機保持最輕量、最穩定的運作狀態。

---

## 5. GCS 與 S3 之間的無縫轉移

因為 `just-gcs-offload` 和 `just-s3-offload` 的資料庫元數據 (Metadata) 結構是完全對稱的，這使得在 Google Cloud Storage 與 Amazon S3（或 S3 相容儲存）之間進行移轉變得無比簡單：

1. **GCS 中繼資料 (`_wp_gcs_info`)**：`['bucket' => ..., 'prefix' => ..., 'file' => ...]`
2. **S3 中繼資料 (`_wp_s3_info`)**：`['bucket' => ..., 'prefix' => ..., 'file' => ...]`

在使用 `rclone` 等工具同步完儲存桶中的實體檔案後，您只需要在 WordPress 資料庫中執行一行 SQL 指令，即可更新所有媒體庫關聯：

```sql
-- 從 GCS 轉移到 S3：
UPDATE wp_postmeta SET meta_key = '_wp_s3_info' WHERE meta_key = '_wp_gcs_info';

-- 從 S3 轉移到 GCS：
UPDATE wp_postmeta SET meta_key = '_wp_gcs_info' WHERE meta_key = '_wp_s3_info';
```

接著停用舊外掛、啟用新外掛並設定金鑰，整個移轉過程便能在數秒內無縫完成，完全不會有破圖問題！

