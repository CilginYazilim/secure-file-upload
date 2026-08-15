<?php
/**
 * =====================================================================
 *  YAPILANDIRMA DOSYASI
 *  cilginyazilim.com – Güvenli Dosya Yükleme Sistemi
 * ---------------------------------------------------------------------
 *  Bu dosya üç iş yapar:
 *    1. Oturumu (session) başlatır  → CSRF anahtarını saklamak için
 *    2. Ayarları ve İZİN VERİLEN DOSYA TÜRLERİNİ sabit olarak tanımlar
 *    3. Veritabanı bağlantısını ($db) kurar
 * =====================================================================
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------------------------------------------------------------------
 *  1) VERİTABANI AYARLARI
 * ------------------------------------------------------------------ */
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'cy_upload');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

/* ---------------------------------------------------------------------
 *  2) UYGULAMA AYARLARI
 * ------------------------------------------------------------------ */
define('APP_DEBUG', true); // Canlıya alırken MUTLAKA false yapın.

error_reporting(APP_DEBUG ? E_ALL : 0);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

/* ---------------------------------------------------------------------
 *  3) YÜKLEME KLASÖRÜ
 * ---------------------------------------------------------------------
 *  dirname(__DIR__): Bu dosya "system/" içinde olduğu için bir üst
 *  klasörü (proje kökünü) verir. Sonuç: .../secure-file-upload/uploads/
 * ------------------------------------------------------------------ */
define('UPLOAD_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR);
define('UPLOAD_URL', 'uploads/');

/* ---------------------------------------------------------------------
 *  SINIRLARIN "TAVANI" (ayarlarla AŞILAMAZ)
 * ---------------------------------------------------------------------
 *  Aşağıdaki iki değer, ayarlar ekranından girilebilecek EN BÜYÜK
 *  değerlerdir. Yönetici arayüzden 8 MB yerine 4 MB seçebilir ama
 *  500 MB seçemez — çünkü ayarlar okunurken bu tavanla sınırlanır
 *  (bkz. function.php setting_int()). Kod tarafında bir tavan
 *  olmasaydı, ayarlar tablosuna erişen biri sunucunun diskini
 *  doldurabilirdi.
 *
 *  Artırırsanız php.ini içindeki upload_max_filesize ve post_max_size
 *  değerlerini de artırmayı unutmayın.
 * ------------------------------------------------------------------ */
define('UPLOAD_MAX_BYTES', 8 * 1024 * 1024);

// Tek istekte en fazla kaç dosya kabul edilir. Sınırsız kabul etmek,
// kötü niyetli birinin binlerce küçük dosya göndererek diski/CPU'yu
// yormasına ("kaynak tükenmesi" saldırısı) kapı aralar.
define('UPLOAD_MAX_FILES', 10);

/**
 * DESTEKLENEN DOSYA TÜRLERİ KATALOĞU – BU PROJENİN GÜVENLİK TEMELİ.
 * ---------------------------------------------------------------------
 * Anahtar: sunucunun DOSYA İÇERİĞİNDEN tespit ettiği gerçek MIME türü.
 * Değer : diske yazılacak GÜVENLİ uzantı.
 *
 * ÇOK ÖNEMLİ: Yeni dosyanın uzantısı kullanıcının gönderdiği dosya
 * adından DEĞİL, buradaki eşlemeden alınır (bkz. function.php
 * store_upload()). Böylece "fatura.pdf.php" gibi çift uzantılı bir
 * dosya, PDF olarak algılansa bile diskte SONUÇTA ".pdf" olarak
 * kaydedilir; ".php" hiçbir zaman diske ulaşmaz.
 *
 * Her giriş ayrıca 'category' taşır: 'image' ise getimagesize() ile
 * EK bir doğrulamadan geçer (bkz. validate_and_store()); 'document'
 * için bu mümkün değildir, MIME + boyut kontrolüyle yetinilir.
 *
 * SVG BİLEREK YOK: image/svg+xml aslında bir METİN/XML formatıdır ve
 * içine <script> gömülebilir; tarayıcıda açıldığında JavaScript
 * çalıştırabilir (XSS). "Resim" gibi görünen bu format, güvenlik
 * açısından belge kategorisine bile girmez, tamamen dışarıda bırakılır.
 *
 * ---------------------------------------------------------------------
 * KATALOG ↔ AYAR AYRIMI (yeni)
 * ---------------------------------------------------------------------
 * Buradaki liste KATALOGDUR: "bu uygulamanın teknik olarak kabul
 * edebileceği türlerin tamamı". Ayarlar ekranından yönetici bu
 * kataloğun bir ALT KÜMESİNİ etkinleştirir (settings tablosu).
 *
 * Etkin liste HER ZAMAN şöyle hesaplanır:
 *     etkin = katalog ∩ ayarlar
 *
 * NEDEN BÖYLE? Ayarlar veritabanında durur ve veritabanı, kodun
 * aksine, bir SQL açığıyla veya yanlış bir yedek geri yüklemesiyle
 * değişebilir. Kesişim kuralı sayesinde ayarlara "application/x-php"
 * yazılsa bile o tür ETKİNLEŞMEZ — katalogda olmadığı için elenir.
 * Yani tehlikeli bir türü açmanın TEK yolu bu dosyayı düzenlemektir.
 *
 * Etkin listeyi okumak için: allowed_upload_types() (function.php)
 */
define('SUPPORTED_UPLOAD_TYPES', [
    // 'label' yalnızca ayarlar ekranındaki insan-okur etikettir;
    // güvenlik kararına KATILMAZ.
    'image/jpeg' => ['ext' => 'jpg',  'category' => 'image', 'label' => 'JPEG görsel'],
    'image/png'  => ['ext' => 'png',  'category' => 'image', 'label' => 'PNG görsel'],
    'image/gif'  => ['ext' => 'gif',  'category' => 'image', 'label' => 'GIF görsel'],
    'image/webp' => ['ext' => 'webp', 'category' => 'image', 'label' => 'WebP görsel'],

    'application/pdf' => ['ext' => 'pdf', 'category' => 'document', 'label' => 'PDF belge'],
    'text/plain'      => ['ext' => 'txt', 'category' => 'document', 'label' => 'Düz metin'],
    'application/zip' => ['ext' => 'zip', 'category' => 'document', 'label' => 'ZIP arşiv'],

    // Word/Excel (.docx/.xlsx) aslında ZIP paketleridir; finfo bunları
    // çoğu zaman "application/zip" olarak tanır, bazen aşağıdaki tam
    // adlarıyla. İkisini de kabul ediyoruz ki gerçek bir .docx dosyası
    // reddedilmesin.
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['ext' => 'docx', 'category' => 'document', 'label' => 'Word belgesi'],
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => ['ext' => 'xlsx', 'category' => 'document', 'label' => 'Excel tablosu'],
]);

/* ---------------------------------------------------------------------
 *  4) VERİTABANI BAĞLANTISI (PDO)
 * ------------------------------------------------------------------ */
try {
    $db = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');

    echo APP_DEBUG
        ? 'Veritabanı bağlantı hatası: ' . $e->getMessage()
        : 'Veritabanına bağlanılamadı. Lütfen daha sonra tekrar deneyin.';

    exit;
}
