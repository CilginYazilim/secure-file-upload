-- ===============================================================
--  Güvenli Dosya Yükleme Sistemi  |  Veritabanı Kurulum Dosyası
--  cilginyazilim.com
-- ---------------------------------------------------------------
--  KURULUM (iki yoldan biri):
--    1) Terminal :  mysql -u root -p < cy_upload.sql
--    2) phpMyAdmin > İçe Aktar > Dosya seç > cy_upload.sql > Başlat
--
--  NOT: Bu dosya veritabanını da oluşturur, ayrıca elle
--       "cy_upload" veritabanı açmanıza gerek yoktur.
--
--  ADLANDIRMA KURALI: Çılgın Yazılım projelerinde veritabanları
--  "cy_" önekiyle adlandırılır ve KURULUM DOSYASI VERİTABANIYLA
--  AYNI ADI TAŞIR (cy_upload → cy_upload.sql). Böylece bir sunucuda
--  onlarca .sql dosyası arasında hangisinin hangi veritabanına ait
--  olduğu tek bakışta anlaşılır.
-- ===============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+03:00";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `cy_upload`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `cy_upload`;

-- ---------------------------------------------------------------
--  TABLO: files – yüklenen her dosyanın kaydı
-- ---------------------------------------------------------------
DROP TABLE IF EXISTS `files`;

CREATE TABLE `files` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Kullanıcının yüklediği ORİJİNAL dosya adı. YALNIZCA GÖRÜNTÜLEME
  -- içindir; diskte bu adla HİÇBİR ŞEY yapılmaz (bkz. function.php
  -- store_upload()). "../../config.php" gibi bir ad bile burada
  -- güvenle durur çünkü asla dosya sistemi işlemine girmez.
  --
  -- DİKKAT: Bu sütun başlığa (Content-Disposition) yazılırken MUTLAKA
  -- download_filename_header() ile temizlenir — ham hâliyle başlığa
  -- konursa başlık enjeksiyonu oluşur (ölçülmüş açık, kapatıldı).
  `original_name` VARCHAR(255) NOT NULL,

  -- Diskteki GERÇEK göreli yol: kategori/yıl/ay/rastgele.uzantı
  -- Örn: image/2026/08/9f2c...a1.png
  --
  -- NEDEN YOL, sadece ad değil? Dosyalar tek bir düz klasörde
  -- birikirse on binlerce dosyada dosya sistemi yavaşlar ve klasör
  -- elle yönetilemez hâle gelir. Tür + tarih klasörlemesi hem
  -- performansı korur hem de "2026 Ağustos'ta yüklenen belgeler"
  -- gibi bir arşivi dosya sisteminde doğrudan görünür kılar.
  --
  -- Bu değer HER ZAMAN sunucu tarafından üretilir; kullanıcı girdisi
  -- buraya asla karışmaz. Yine de okunurken safe_upload_path() ile
  -- yükleme klasörünün dışına çıkmadığı doğrulanır.
  `stored_path`   VARCHAR(255) NOT NULL,

  -- Sunucunun DOSYA İÇERİĞİNDEN tespit ettiği gerçek MIME türü
  -- (finfo_file). Tarayıcının gönderdiği Content-Type BAŞLIĞINA
  -- asla güvenilmez; bu sütun her zaman sunucu tespitini tutar.
  `mime`          VARCHAR(127) NOT NULL,

  -- Uzantı, MIME'den TÜRETİLİR (whitelist üzerinden); kullanıcının
  -- dosya adından ASLA alınmaz. Böylece "resim.jpg.php" gibi çift
  -- uzantı numaraları diskte hiçbir zaman .php olarak kaydolmaz.
  `extension`     VARCHAR(10)  NOT NULL,

  `size_bytes`    INT UNSIGNED NOT NULL,

  -- Arayüzde doğru simge/önizlemeyi seçmek ve klasörlemek için:
  -- 'image' ise küçük resim gösterilir, 'document' ise tür simgesi.
  `category`      ENUM('image','document') NOT NULL,

  -- İNDİRME SAYACI: dosya her indirildiğinde bir artar.
  -- Ayrı bir "indirmeler" tablosu yerine tek sütun tutuldu; bu demo
  -- için kim/ne zaman değil YALNIZCA KAÇ KEZ bilgisi yeterli ve bu
  -- yaklaşım her indirmede satır eklemenin maliyetini doğurmaz.
  `download_count`     INT UNSIGNED NOT NULL DEFAULT 0,
  `last_downloaded_at` TIMESTAMP NULL DEFAULT NULL,

  `uploaded_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_files_stored_path` (`stored_path`),
  KEY `idx_files_uploaded_at` (`uploaded_at`),
  KEY `idx_files_category` (`category`)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------
--  TABLO: settings – çalışma anında değiştirilebilen ayarlar
-- ---------------------------------------------------------------
--  NEDEN AYRI TABLO?
--  İzin verilen dosya türlerini config.php'den değiştirmek için
--  koda dokunmak gerekir. Bu tablo, yöneticinin arayüzden
--  açıp kapatmasına izin verir.
--
--  GÜVENLİK SINIRI — ÇOK ÖNEMLİ:
--  Bu tablo yalnızca config.php'deki SUPPORTED_UPLOAD_TYPES
--  kataloğunda ZATEN VAR OLAN türleri AÇIP KAPATABİLİR. Buraya
--  "application/x-php" yazmak o türü etkinleştirmez; kod, ayarları
--  her zaman katalogla kesiştirir (bkz. function.php
--  allowed_upload_types()). Yani veritabanı ele geçirilse bile
--  tehlikeli bir tür etkinleştirilemez.
-- ---------------------------------------------------------------
DROP TABLE IF EXISTS `settings`;

CREATE TABLE `settings` (
  -- Ayarın adı (örn. 'allowed_mimes', 'max_bytes')
  `name`  VARCHAR(64) NOT NULL,

  -- Değer JSON metni olarak saklanır; böylece tek bir tablo hem
  -- liste hem sayı hem metin ayarları taşıyabilir.
  `value` TEXT NOT NULL,

  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`name`)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- Varsayılan ayarlar: kataloğun tamamı açık, 8 MB sınır, 10 dosya.
INSERT INTO `settings` (`name`, `value`) VALUES
('allowed_mimes', '["image\\/jpeg","image\\/png","image\\/gif","image\\/webp","application\\/pdf","text\\/plain","application\\/zip","application\\/vnd.openxmlformats-officedocument.wordprocessingml.document","application\\/vnd.openxmlformats-officedocument.spreadsheetml.sheet"]'),
('max_bytes', '8388608'),
('max_files', '10');
