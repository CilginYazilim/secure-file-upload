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

/* ---------------------------------------------------------------------
 *  .env DESTEĞİ
 * ---------------------------------------------------------------------
 *  Veritabanı bilgileri bu dosyanın İÇİNDE durmak zorunda değil.
 *  Depo kökündeki ".env" dosyasına yazarsanız buradaki varsayılanlar
 *  devreye girmez — ve ".env" .gitignore içinde olduğu için parolanız
 *  depoya hiç girmez.
 *
 *  NEDEN AYRI BİR DOSYA?
 *  config.php DEPODA durur ve her dağıtımda depodaki sürümle
 *  DEĞİŞTİRİLİR; içine elle yazdığınız parola bir sonraki deploy'da
 *  silinir. .env ise deploy'un dokunmadığı bir dosyadır: bir kez
 *  oluşturursunuz, kalıcıdır.
 *
 *  DEĞER ARAMA SIRASI
 *      1. config.local.php içinde define() edilmişse o kazanır
 *         (bu dosyada varsa; aşağıdaki "! defined()" kontrolleri)
 *      2. .env dosyası
 *      3. Sunucunun gerçek ortam değişkeni (Apache SetEnv, systemd…)
 *      4. Bu dosyadaki varsayılan
 *
 *  cy_env() bilerek getenv() ile AYNI şeyi döndürür (değer ya da
 *  false). Böylece aşağıdaki satırlar olduğu gibi çalışmaya devam
 *  eder; "?:" ve "!== false" kalıplarının hiçbiri değişmedi.
 * ------------------------------------------------------------------ */
if (! function_exists('cy_env')) {
    /**
     * .env dosyasından (yoksa ortamdan) bir değer okur.
     *
     * @return string|false Değer yoksa false — getenv() ile aynı sözleşme.
     */
    function cy_env(string $key): string|false
    {
        static $env = null;

        if ($env === null) {
            $env  = [];
            $file = dirname(__DIR__) . '/.env';

            if (is_file($file) && is_readable($file)) {
                /* IGNORE_NEW_LINES + SKIP_EMPTY_LINES: satır sonlarını ve
                 * boş satırları baştan eler; ayrıştırma sadeleşir. */
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                foreach ($lines as $line) {
                    $line = trim($line);

                    // Yorum satırı ya da "=" içermeyen satır atlanır.
                    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                        continue;
                    }

                    [$name, $value] = explode('=', $line, 2);

                    $name  = trim($name);
                    $value = trim($value);

                    /* Tırnak içindeki değerlerden tırnakları at:
                     * DB_PASS="a b c" → a b c
                     * Tırnak zorunlu değildir; yalnızca boşluk içeren
                     * parolalar için gerekir. */
                    if (strlen($value) >= 2
                        && ($value[0] === '"' || $value[0] === "'")
                        && $value[strlen($value) - 1] === $value[0]
                    ) {
                        $value = substr($value, 1, -1);
                    }

                    if ($name !== '') {
                        $env[$name] = $value;
                    }
                }
            }
        }

        // .env'de varsa o; yoksa sunucunun gerçek ortam değişkeni.
        return $env[$key] ?? getenv($key);
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------------------------------------------------------------------
 *  1) VERİTABANI AYARLARI
 * ------------------------------------------------------------------ */
define('DB_HOST', cy_env('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', cy_env('DB_NAME') ?: 'cy_upload');
define('DB_USER', cy_env('DB_USER') ?: 'root');
define('DB_PASS', cy_env('DB_PASS') !== false ? (string) cy_env('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

/* ---------------------------------------------------------------------
 *  ZAMAN DİLİMİ
 * ---------------------------------------------------------------------
 *  ÖLÇÜLEN SORUN: php.ini'de date.timezone çoğu XAMPP kurulumunda
 *  sunucunun coğrafi diliminden farklıdır. Bu makinede PHP
 *  "Europe/Berlin", MySQL ise sistem dilimi (Europe/Istanbul)
 *  kullanıyordu; aynı anı anlatan iki satır BİR SAAT farklı görünüyordu:
 *
 *      worker günlüğü (PHP date)  : 14:03:17
 *      veritabanı  (MySQL NOW())  : 15:03:17
 *
 *  Bu depodaki zaman ARİTMETİĞİ bilinçli olarak SQL tarafında yapılır
 *  (NOW(), INTERVAL, TIMESTAMPDIFF), bu yüzden hesaplar zaten doğrudur.
 *  Kayan şey, PHP'nin ekrana/günlüğe bastığı saatti — ve demoyu
 *  deneyen biri için bu, "sistem yanlış çalışıyor" gibi görünür.
 *
 *  Çözüm: dilimi ORTAMA bırakmak yerine açıkça sabitliyoruz. Kendi
 *  sunucunuzda farklı bir dilim istiyorsanız APP_TIMEZONE ortam
 *  değişkenini tanımlamanız yeterlidir; kod değiştirmenize gerek yok.
 * ------------------------------------------------------------------ */
define('APP_TIMEZONE', cy_env('APP_TIMEZONE') ?: 'Europe/Istanbul');

// @ kullanmıyoruz: geçersiz bir dilim adı sessizce yutulmamalı.
if (in_array(APP_TIMEZONE, timezone_identifiers_list(), true)) {
    date_default_timezone_set(APP_TIMEZONE);
}

/* ---------------------------------------------------------------------
 *  2) UYGULAMA AYARLARI
 * ------------------------------------------------------------------ */
define('APP_DEBUG', true); // Canlıya alırken MUTLAKA false yapın.

/* Sürüm numarası. Arayüzün alt bilgisinde ve README'de aynı değer
 * görünsün diye TEK yerde tanımlanır; elle iki yerde güncellemek
 * er ya da geç birinin unutulmasıyla sonuçlanır. */
define('APP_VERSION', '1.1.0');

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
