# Just GCS Offload

[For English version, see README.md](README.md) | [WordPress on GCS Implementation Guide](GUIDE.md) | [WordPress on GCS 中文實作指南](GUIDE.zh-TW.md)

這是一個專門為 WordPress 開發的輕量級、無外部依賴（Dependency-free）的 Google Cloud Storage (GCS) 媒體庫異地同步外掛。

> [!NOTE]
> 需要 Amazon S3 或 S3 相容儲存（R2、B2、Spaces、MinIO）支援嗎？請參考我們的姊妹專案：[Just S3 Offload](https://github.com/ivanusto/just-s3-offload)。

相較於其他臃腫的雲端儲存外掛，**Just GCS Offload** 旨在保持極輕量與高效。我們完全繞過了體積龐大的官方 Google Cloud SDK，而是直接使用 PHP 原生的 cURL 以及 OpenSSL 的 JWT 簽章演算法來處理 Service Account 憑證驗證與 REST API 檔案上傳。

## 功能特色

* **零外部依賴**：整個外掛僅幾十 KB，不包含任何龐大的 `vendor/` 資料夾或第三方套件庫。
* **JWT Service Account 驗證**：貼上 Google 服務帳戶的 JSON 金鑰，利用原生 OpenSSL (`openssl_sign`) 進行 RS256 簽署，安全、快速且輕量。
* **自動圖片分流上傳**：上傳圖片時，會自動將原始檔案以及 WordPress 產生的所有子尺寸圖檔（縮圖）一併同步上傳至 GCS。
* **網址與 Srcset 改寫**：無縫改寫前台圖片網址與響應式 `srcset` 路徑，使其指向 GCS 儲存桶或您自訂的 CDN / 域名。
* **可選本機快取清理**：可設定在成功上傳至 GCS 後刪除本機主機上的實體檔案，以節省主機空間。
* **連動刪除**：在 WordPress 後台永久刪除媒體檔案時，會同步將儲存桶中對應的原始檔與所有尺寸圖片一併刪除。
* **WP-CLI 命令整合**：提供強大的命令列工具，供開發人員批量同步資料庫中介資料（Metadata）或批量上傳本機歷史檔案。
* **連線測試按鈕**：設定頁面提供「一鍵連線測試」功能，自動執行寫入-讀取-刪除驗證。

## 系統需求

* PHP 7.4 或更高版本
* 啟用 PHP OpenSSL 延伸模組（用於 JWT 簽名）
* WordPress 6.0 或更高版本

## 安裝步驟

1. 至本專案的 [Releases](https://github.com/ivanusto/just-gcs-offload/releases) 頁面下載最新版的 `just-gcs-offload.zip`。
2. 登入您的 WordPress 後台，前往 **「外掛」->「安裝外掛」->「上傳外掛」**，選擇下載的 ZIP 檔案並點擊 **「立即安裝」**。
3. 啟用外掛。

## GCS 儲存桶設定

為了讓瀏覽器能正常讀取圖片，請依據以下任一方法設定您的 Google Cloud Storage 儲存桶權限：

### 方法 A：統一儲存桶層級存取（Uniform Bucket-Level Access，推薦）
1. 在 GCS 控制台中，將您的 Bucket 存取控制權限設為 **「統一（Uniform）」**。
2. 在「權限」頁籤中新增主體，對 **`allUsers`** 授予 **「Storage Object Viewer」**（儲存區物件檢視者）角色。
3. 保持 WordPress 設定中的「設定 Public ACL」選項為 **關閉** 狀態。

### 方法 B：精細的存取控制（Fine-grained Access Control）
1. 在 GCS 控制台中，將您的 Bucket 存取控制權限設為 **「精細（Fine-grained）」**。
2. 在 WordPress 設定中，**啟用「設定 Public ACL」** 選項。外掛在上傳檔案時會自動為每個物件附帶 `publicRead` 的 ACL 設定。

## 後台參數設定

在 WordPress 後台前往 **「設定」->「GCS Offload」** 進行以下參數設定：

* **Service Account JSON Key**：貼上您從 Google Cloud 控制台下載的服務帳戶金鑰 JSON 檔案完整內容。此帳戶必須擁有該儲存桶的物件讀寫與刪除權限。
* **GCS Bucket Name**：輸入目標儲存桶名稱（例如：`my-wordpress-bucket`）。
* **Folder Path Prefix（資料夾前綴）**：*(選填)* 儲存桶內的子資料夾路徑（例如：`wp-content/uploads`）。開頭與結尾請勿包含斜線。
* **Custom Domain / CDN URL**：*(選填)* 指向該儲存桶的自訂網域或 CDN 網址（例如：`https://cdn.example.com`）。若留空，則會使用 GCS 預設的公開網址：`https://storage.googleapis.com/{儲存桶名稱}`。
* **Cache-Control Header**：套用於上傳物件的 Cache-Control 標頭值（預設為 `public, max-age=31536000`）。
* **設定 Public ACL**：如果您的 Bucket 使用精細控制權限，請勾選此項。
* **刪除本機檔案**：勾選後會在成功同步至 GCS 後刪除本機主機的檔案。*注意：刪除本機檔案會導致 WordPress 內建的圖片編輯器（如旋轉、剪裁）無法正常運作。*

設定完成後，點擊下方 **「Run Connection Test」** 測試連線，外掛會自動驗證權限是否正確。

## WP-CLI 搬移指令

針對開發者與系統管理員，本外掛提供了自訂的 WP-CLI 指令來處理批量轉移。

### 1. 僅同步資料庫欄位 (不重複上傳實體檔案)
如果您已經手動使用 `gsutil`、`rclone` 或其他工具將檔案同步至 GCS 儲存桶，可以使用此指令快速在資料庫中為舊媒體產生 GCS 同步欄位（`_wp_gcs_info`），使其網址直接改寫：
```bash
wp gcs-offload sync-metadata [--bucket=<bucket>] [--prefix=<prefix>] [--overwrite]
```

### 2. 批量上傳本機檔案至 GCS
自動掃描本機現有的媒體庫附件，將其上傳至 GCS，並更新資料庫紀錄。
```bash
wp gcs-offload sync-all [--delete-local] [--overwrite]
```
* 使用 `--delete-local` 在成功上傳後刪除本機的實體檔案。
* 使用 `--overwrite` 重新上傳已經標記同步過的檔案。

## 疑難排解

如果使用中（例如新圖片上傳後）媒體庫頁面出現嚴重錯誤或空白畫面：
1. 前往 `wp-config.php` 開啟 WordPress 除錯日誌：
   ```php
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   define( 'WP_DEBUG_DISPLAY', false );
   ```
2. 重新觸發一次錯誤。
3. 查看伺服器錯誤日誌定位問題：
   * WordPress 偵錯日誌：`wp-content/debug.log`
   * Web 伺服器日誌：`/var/log/apache2/error.log` 或 `/var/log/nginx/error.log`

## 授權條款

本專案基於 MIT 授權條款釋出。
