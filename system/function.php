<?php
/**
 * =====================================================================
 *  YARDIMCI FONKSİYONLAR
 *  cilginyazilim.com – Güvenli Dosya Yükleme Sistemi
 * ---------------------------------------------------------------------
 *  Bu dosyanın kalbi store_upload()'dur: DOSYA YÜKLEME, WEB'İN EN
 *  TEHLİKELİ KISMIDIR. Saldırgan "shell.php" yükleyip sunucuyu ele
 *  geçirebilir. Bu yüzden burada ÇOK KATMANLI bir savunma vardır;
 *  her katman, DİĞERİ ATLATILSA BİLE tek başına yeterli olacak
 *  şekilde tasarlanmıştır ("defense in depth"):
 *
 *    1. Dosyanın İÇERİĞİNDEN gerçek MIME türü tespit edilir
 *       (istemcinin gönderdiği Content-Type başlığına GÜVENİLMEZ)
 *    2. MIME, bir BEYAZ LİSTEYLE karşılaştırılır (config.php)
 *    3. Yeni dosyanın uzantısı bu listeden ALINIR, kullanıcının
 *       dosya adından DEĞİL
 *    4. Yeni dosya adı RASTGELE üretilir (tahmin edilemez)
 *    5. Resimler için getimagesize() ile İKİNCİ bir doğrulama yapılır
 *    6. uploads/.htaccess ile o klasörde PHP ÇALIŞTIRMA kapatılır
 *    7. İndirmede Content-Disposition: attachment + nosniff başlığı
 *       zorlanır (tarayıcı içeriği ASLA "çalıştırmaz", yalnızca indirir)
 * =====================================================================
 */

declare(strict_types=1);


/* =====================================================================
 *  BÖLÜM 1 – ÇIKTI VE YANIT
 * ================================================================== */

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_success(string $description, array $extra = []): void
{
    json_response(array_merge([
        'success'     => true,
        'type'        => 'success',
        'description' => $description,
    ], $extra));
}

function json_error(string $description, int $status = 400, array $extra = []): void
{
    json_response(array_merge([
        'success'     => false,
        'type'        => 'danger',
        'description' => $description,
    ], $extra), $status);
}


/* =====================================================================
 *  BÖLÜM 2 – CSRF KORUMASI
 * ================================================================== */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($token) || $token === ''
        || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $token)) {

        /* NEDEN 403, 419 DEĞİL?
         * 419 ("Page Expired") Laravel'in icat ettiği, RESMİ OLMAYAN bir
         * durum kodudur. Bu kurulumda ÖLÇÜLDÜ: Apache 419'u tanımıyor ve
         * yanıtı sessizce 500'e çeviriyor — yani istemci "sunucu çöktü"
         * sanıyor, oysa sorun sadece süresi dolmuş bir oturum. 403
         * (Forbidden) standarttır, her sunucu/vekil doğru geçirir ve
         * anlamı da doğrudur: istek anlaşıldı ama yetkilendirilmedi. */
        json_error('Oturum doğrulaması başarısız. Lütfen sayfayı yenileyin.', 403);
    }
}


/* =====================================================================
 *  BÖLÜM 2b – AYARLAR (çalışma anında değiştirilebilir)
 * =====================================================================
 *  Ayarlar veritabanındaki `settings` tablosunda JSON olarak durur.
 *  Kod, ayarlara ASLA körü körüne güvenmez: her okuma bir "kelepçeden"
 *  geçer (katalog kesişimi veya tavan sınırı). Ayrıntı için
 *  config.php'deki SUPPORTED_UPLOAD_TYPES açıklamasına bakın.
 * ------------------------------------------------------------------ */

/**
 * Tüm ayarları tek seferde okur ve istek boyunca önbellekte tutar.
 *
 * NEDEN ÖNBELLEK? allowed_upload_types() bir yükleme isteğinde her
 * dosya için çağrılır; 10 dosyalık bir yüklemede aynı sorguyu 10 kez
 * çalıştırmanın anlamı yok. static değişken, istek bitene kadar yaşar.
 */
function settings_all(PDO $db): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [];

    try {
        foreach ($db->query('SELECT name, value FROM settings') as $row) {
            $cache[$row['name']] = json_decode((string) $row['value'], true);
        }
    } catch (PDOException $e) {
        // Ayarlar tablosu yoksa (eski kurulum) varsayılanlarla devam et;
        // uygulamanın tamamen çökmesindense güvenli varsayılan daha iyidir.
        error_log('[UPLOAD] settings okunamadi: ' . $e->getMessage());
    }

    return $cache;
}

/**
 * Sayısal bir ayarı, config.php'deki TAVAN değeriyle sınırlayarak okur.
 *
 * min(ayar, tavan) kuralı kritiktir: ayarlar tablosuna 500 MB yazılsa
 * bile uygulama UPLOAD_MAX_BYTES tavanını aşmaz.
 */
function setting_int(PDO $db, string $name, int $default, int $ceiling): int
{
    $value = settings_all($db)[$name] ?? null;

    if (!is_int($value) && !is_numeric($value)) {
        return $default;
    }

    $value = (int) $value;

    // 1 ile tavan arasına kelepçele.
    return max(1, min($value, $ceiling));
}

/**
 * ETKİN dosya türlerini döndürür: KATALOG ∩ AYARLAR.
 *
 * Bu fonksiyon, projenin "ayarlar güvenliği zayıflatamaz" sözünün
 * tutulduğu yerdir. Ayarlarda ne yazarsa yazsın, sonuç her zaman
 * config.php kataloğunun bir alt kümesidir.
 *
 * @return array<string,array{ext:string,category:string,label:string}>
 */
function allowed_upload_types(PDO $db): array
{
    $selected = settings_all($db)['allowed_mimes'] ?? null;

    // Ayar yoksa veya bozuksa: kataloğun tamamı etkin (güvenli varsayılan,
    // çünkü katalogda zaten tehlikeli tür bulunmaz).
    if (!is_array($selected) || $selected === []) {
        return SUPPORTED_UPLOAD_TYPES;
    }

    // KESİŞİM: yalnızca hem katalogda hem ayarlarda olan türler kalır.
    $allowed = array_intersect_key(SUPPORTED_UPLOAD_TYPES, array_flip($selected));

    // Yönetici her şeyi kapattıysa yükleme tamamen durur; bu bilinçli
    // bir tercihtir (bakım modu gibi kullanılabilir).
    return $allowed;
}

/**
 * Ayarları kaydeder. Yalnızca TANINAN ayar adları yazılır; istemcinin
 * gönderdiği rastgele anahtarlar sessizce yok sayılır.
 */
function settings_save(PDO $db, array $allowedMimes, int $maxBytes, int $maxFiles): void
{
    // Gelen MIME listesini katalogla kesiştir: katalog dışı bir değer
    // veritabanına HİÇ YAZILMASIN (savunmayı kaynağa en yakın yerde kur).
    $clean = array_values(array_intersect($allowedMimes, array_keys(SUPPORTED_UPLOAD_TYPES)));

    $rows = [
        'allowed_mimes' => $clean,
        'max_bytes'     => max(1, min($maxBytes, UPLOAD_MAX_BYTES)),
        'max_files'     => max(1, min($maxFiles, UPLOAD_MAX_FILES)),
    ];

    // INSERT ... ON DUPLICATE KEY UPDATE: kayıt varsa günceller, yoksa
    // ekler. Böylece "önce SELECT sonra karar ver" turuna gerek kalmaz.
    $stmt = $db->prepare(
        'INSERT INTO settings (name, value) VALUES (:name, :value)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );

    foreach ($rows as $name => $value) {
        $stmt->execute([
            ':name'  => $name,
            ':value' => json_encode($value, JSON_UNESCAPED_UNICODE),
        ]);
    }
}


/* =====================================================================
 *  BÖLÜM 3 – GÜVENLİ DOSYA YÜKLEME (bu projenin asıl konusu)
 * ================================================================== */

/**
 * Tek bir yüklenen dosyayı doğrular, güvenli şekilde diske taşır ve
 * veritabanına kaydeder.
 *
 * @param  array<string,mixed> $file $_FILES['files']['...'][$index] biçiminde tek dosya
 * @return array<string,mixed> Eklenen veritabanı satırı
 * @throws RuntimeException Doğrulama başarısız olursa (mesaj kullanıcıya gösterilir)
 */
function store_upload(PDO $db, array $file): array
{
    /* --- 1) PHP'NİN KENDİ YÜKLEME HATALARI --------------------------
     * Bunlar dosya İÇERİĞİYLE değil, yükleme İŞLEMİYLE ilgilidir
     * (bağlantı koptu, php.ini limiti aşıldı, dosya seçilmedi...). */
    switch ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new RuntimeException('Dosya boyutu sunucu limitini aşıyor.');
        case UPLOAD_ERR_NO_FILE:
            throw new RuntimeException('Dosya seçilmedi.');
        default:
            throw new RuntimeException('Dosya yüklenirken bir hata oluştu.');
    }

    /* --- 2) BOYUT KONTROLÜ (kendi kuralımız) ------------------------
     * php.ini limitinden BAĞIMSIZ olarak kendi üst sınırımızı da
     * uygularız; sunucu ayarları gevşek olsa bile uygulama kendi
     * kuralını korur. */
    $size     = (int) ($file['size'] ?? 0);
    $maxBytes = setting_int($db, 'max_bytes', UPLOAD_MAX_BYTES, UPLOAD_MAX_BYTES);

    if ($size <= 0 || $size > $maxBytes) {
        throw new RuntimeException(
            'Dosya boyutu en fazla ' . format_bytes($maxBytes) . ' olabilir.'
        );
    }

    /* --- 3) GERÇEKTEN HTTP YÜKLEMESİYLE Mİ GELDİ? -------------------
     * Bu kontrol olmazsa saldırgan tmp_name alanına sunucudaki başka
     * bir dosyanın (örn. /etc/passwd) yolunu yazıp onu "yüklenmiş"
     * gibi kopyalatabilirdi. is_uploaded_file() dosyanın GERÇEKTEN
     * PHP'nin bu istek için oluşturduğu geçici dosya olduğunu doğrular. */
    $tmpPath = (string) ($file['tmp_name'] ?? '');

    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Geçersiz dosya kaynağı.');
    }

    /* --- 4) GERÇEK MIME TÜRÜNÜ TESPİT ET (EN KRİTİK ADIM) -----------
     * finfo_file() dosyanın İLK BAYTLARINA bakarak türünü belirler
     * ("magic bytes" / dosya imzası). Bu, tarayıcının gönderdiği
     * Content-Type başlığından TAMAMEN BAĞIMSIZDIR ve kolayca taklit
     * edilemez. Saldırgan dosyasını "image/png" diye işaretlese bile,
     * içeriği gerçekten PNG değilse burada yakalanır. */
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($tmpPath);

    // ETKİN liste = katalog ∩ ayarlar. Yönetici bir türü ayarlardan
    // kapattıysa, katalogda olsa bile burada reddedilir.
    $allowedTypes = allowed_upload_types($db);

    if (!array_key_exists($mime, $allowedTypes)) {
        $labels = implode(', ', array_map(
            static fn(array $t): string => strtoupper($t['ext']),
            $allowedTypes
        ));

        throw new RuntimeException(
            'Bu dosya türü desteklenmiyor (algılanan tür: ' . $mime . '). '
            . 'İzin verilen türler: ' . ($labels !== '' ? $labels : 'yok (yükleme kapalı)') . '.'
        );
    }

    $meta      = $allowedTypes[$mime];
    $extension = $meta['ext'];
    $category  = $meta['category'];

    /* --- 5) RESİMLER İÇİN İKİNCİ DOĞRULAMA (SERTLEŞTİRİLDİ) ---------
     * BU KATMAN ÖLÇÜMLE GÜÇLENDİRİLDİ. Eskiden yalnızca getimagesize()
     * kullanılıyordu ve BU YETERSİZDİ:
     *
     *   Test: içeriği  GIF89a<?php system($_GET["c"]); ?>  olan dosya
     *   Sonuç: finfo → image/gif (ilk 6 bayt GIF imzası),
     *          getimagesize() → GEÇTİ, üstelik 16188x26736 gibi uydurma
     *          bir boyut döndürdü (PHP kodunun baytlarını genişlik/
     *          yükseklik sanmıştı). Dosya diske YAZILIYORDU.
     *
     * Çünkü getimagesize() dosyayı ÇÖZMEZ, yalnızca BAŞLIĞINI okur.
     * imagecreatefromstring() ise görüntüyü GERÇEKTEN çözer (decode);
     * sahte/polyglot dosyalar bu adımda düşer. Aynı testte:
     *          imagecreatefromstring() → REDDETTİ.
     *
     * Neden hâlâ getimagesize() de var? Ucuz bir ön eleme yapar ve
     * boyut sınırını ÇÖZMEDEN ÖNCE kontrol etmemizi sağlar — dev
     * piksel boyutlu bir "decompression bomb" belleği doldurmadan
     * burada reddedilir. */
    if ($category === 'image') {
        $imageInfo = @getimagesize($tmpPath);

        if ($imageInfo === false) {
            throw new RuntimeException('Dosya bir görsel gibi görünüyor ama içeriği bozuk.');
        }

        // Piksel bombası koruması: 50 MP üzeri görseller çözülmeye
        // çalışılırken yüzlerce MB bellek tüketebilir (genişlik ×
        // yükseklik × 4 bayt). ÇÖZMEDEN ÖNCE reddet.
        if (((int) $imageInfo[0] * (int) $imageInfo[1]) > 50_000_000) {
            throw new RuntimeException('Görselin piksel boyutu çok büyük.');
        }

        // GERÇEK ÇÖZÜMLEME. GD eklentisi yoksa bu katman atlanır;
        // atlanması diğer katmanları geçersiz kılmaz (.htaccess kilidi
        // ve uzantı türetme hâlâ yürürlüktedir).
        if (function_exists('imagecreatefromstring')) {
            $raw   = (string) file_get_contents($tmpPath);
            $image = @imagecreatefromstring($raw);

            if ($image === false) {
                throw new RuntimeException(
                    'Dosya geçerli bir görsel değil (içerik çözümlenemedi).'
                );
            }

            imagedestroy($image); // Belleği hemen bırak.
        }
    }

    /* --- 6) HEDEF KLASÖRÜ HAZIRLA (TÜR + TARİH AĞACI) ---------------
     * Dosyalar artık düz bir klasöre değil,
     *      uploads/<kategori>/<yıl>/<ay>/
     * ağacına yazılır. Örn: uploads/image/2026/08/
     *
     * NEDEN? Üç somut fayda:
     *   (a) PERFORMANS: tek klasörde on binlerce dosya biriktiğinde
     *       dosya sistemi dizin taramasında yavaşlar.
     *   (b) YÖNETİLEBİLİRLİK: "2025'in tamamını arşivle/sil" gibi
     *       işlemler tek komuta iner.
     *   (c) YEDEKLEME: aylık artımlı yedek almak kolaylaşır.
     *
     * GÜVENLİK NOTU: Bu yolun HİÇBİR parçası kullanıcı girdisinden
     * gelmez — kategori katalogdan, yıl/ay sunucu saatinden, dosya adı
     * random_bytes()'tan gelir. Dolayısıyla burada dizin aşımı (path
     * traversal) girdisi taşıyacak bir kanal yoktur.
     *
     * uploads/.htaccess ALT KLASÖRLERE DE UYGULANIR (Apache .htaccess
     * kurallarını alt dizinlere miras verir); bu ölçülerek doğrulandı. */
    $relativeDir = $category . '/' . date('Y') . '/' . date('m');
    $targetDir   = UPLOAD_DIR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);

    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Yükleme klasörü oluşturulamadı.');
    }

    /* --- 7) RASTGELE, TAHMİN EDİLEMEZ DOSYA ADI ---------------------
     * Üç fayda: (a) aynı adlı dosyanın üzerine yazılmasını önler,
     * (b) orijinal dosya adındaki bilgilerin URL'de sızmasını önler,
     * (c) "hangi dosya kimin?" tahminini imkânsız kılar. */
    do {
        $storedPath = $relativeDir . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $absolute   = UPLOAD_DIR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath);
    } while (file_exists($absolute));

    /* move_uploaded_file(): copy() DEĞİL bunu kullanın; PHP ek olarak
     * dosyanın gerçekten bir HTTP yüklemesinden geldiğini bir kez
     * daha doğrular (is_uploaded_file() ile aynı denetimin tekrarı,
     * "iki kere ölç bir kere kes" prensibi). */
    if (!move_uploaded_file($tmpPath, $absolute)) {
        throw new RuntimeException('Dosya kaydedilemedi.');
    }

    /* --- 8) VERİTABANINA KAYDET --------------------------------------
     * Orijinal ad SADECE görüntüleme için saklanır; basename() ile
     * yol bilgisi (varsa) atılır. Diskteki gerçek ad hep $storedName'dir. */
    $stmt = $db->prepare(
        'INSERT INTO files (original_name, stored_path, mime, extension, size_bytes, category)
         VALUES (:original_name, :stored_path, :mime, :extension, :size_bytes, :category)'
    );

    $stmt->execute([
        // mb_substr ile 255'e kırpılır: sütun sınırını aşan bir ad,
        // MySQL katı kipte (STRICT_TRANS_TABLES) tüm yüklemeyi hataya
        // düşürürdü. Çok baytlı karakterin ortadan bölünmemesi için
        // strlen değil mb_strlen kullanılır.
        ':original_name' => mb_substr(basename((string) ($file['name'] ?? 'dosya')), 0, 255, 'UTF-8'),
        ':stored_path'   => $storedPath,
        ':mime'          => $mime,
        ':extension'     => $extension,
        ':size_bytes'    => $size,
        ':category'      => $category,
    ]);

    $id = (int) $db->lastInsertId();

    return find_file($db, $id) ?? throw new RuntimeException('Kayıt oluşturuldu ama okunamadı.');
}

/**
 * Veritabanındaki göreli yolu, diskteki MUTLAK yola GÜVENLE çevirir.
 *
 * DİZİN AŞIMI (PATH TRAVERSAL) SAVUNMASI:
 * Artık stored_path içinde eğik çizgi VAR (image/2026/08/abc.png),
 * bu yüzden eski basename() yaklaşımı kullanılamaz — o, yolu tamamen
 * düzleştirip dosyayı bulunamaz hâle getirirdi. Yerine iki katman:
 *
 *   1. ŞEKİL DENETİMİ: yol yalnızca <kategori>/<yıl>/<ay>/<32 hex>.<uzantı>
 *      kalıbına uyuyorsa kabul edilir. ".." bu kalıba UYAMAZ.
 *   2. GERÇEK KONUM DENETİMİ: realpath() ile sembolik bağlar dâhil
 *      çözülür ve sonucun UPLOAD_DIR ile BAŞLADIĞI doğrulanır.
 *      Bu, kalıp denetimini aşan teorik bir durumda bile dosyanın
 *      yükleme klasörünün dışına çıkmasını imkânsız kılar.
 *
 * Neden iki katman? stored_path'i biz üretiyoruz, yani bugün güvenli.
 * Ama bir gün başka bir kod bu sütunu yazarsa savunma yerinde kalsın
 * diye ("asla tek katmana güvenme").
 *
 * @return string|null Güvenliyse mutlak yol, değilse null
 */
function safe_upload_path(?string $storedPath): ?string
{
    $storedPath = trim((string) $storedPath);

    // Ters bölüyü normalle: Windows'ta üretilmiş bir kayıt da geçsin.
    $storedPath = str_replace('\\', '/', $storedPath);

    // 1) ŞEKİL DENETİMİ — beyaz liste kalıbı.
    if (!preg_match('#^(image|document)/\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9]{1,10}$#', $storedPath)) {
        return null;
    }

    $absolute = UPLOAD_DIR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath);

    if (!is_file($absolute)) {
        return null;
    }

    // 2) GERÇEK KONUM DENETİMİ.
    $real = realpath($absolute);
    $base = realpath(UPLOAD_DIR);

    if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $real;
}

/**
 * Diskteki bir dosyayı GÜVENLE siler ve arkasında kalan boş
 * tür/yıl/ay klasörlerini temizler.
 */
function delete_stored_file(?string $storedPath): void
{
    $path = safe_upload_path($storedPath);

    if ($path === null) {
        return;
    }

    @unlink($path);

    /* Boş kalan klasörleri yukarı doğru topla: .../08, .../2026,
     * .../image. rmdir() yalnızca BOŞ klasörü siler, doluysa sessizce
     * başarısız olur — bu yüzden ayrıca "boş mu?" kontrolü gerekmez.
     * UPLOAD_DIR'in kendisine asla dokunulmaz (döngü sınırı). */
    $base = realpath(UPLOAD_DIR);
    $dir  = dirname($path);

    for ($i = 0; $i < 3 && $base !== false && $dir !== $base && str_starts_with($dir, $base); $i++) {
        if (!@rmdir($dir)) {
            break; // Klasör boş değil; yukarısı da boş olamaz.
        }

        $dir = dirname($dir);
    }
}

/**
 * İndirme sayacını bir artırır.
 *
 * NEDEN TEK SORGU (SELECT + UPDATE değil)? "Oku, 1 ekle, yaz" üç adımlı
 * yapılsaydı aynı anda gelen iki indirme birbirinin artışını ezerdi
 * (race condition). SQL'in kendi içinde artırması bunu imkânsız kılar.
 *
 * Sayaç hatası indirmeyi ENGELLEMEMELİDİR: istatistik uğruna kullanıcıyı
 * dosyasından etmeyiz, bu yüzden hata yalnızca günlüğe yazılır.
 */
function increment_download_count(PDO $db, int $id): void
{
    try {
        $db->prepare(
            'UPDATE files
                SET download_count = download_count + 1,
                    last_downloaded_at = CURRENT_TIMESTAMP
              WHERE id = :id'
        )->execute([':id' => $id]);
    } catch (PDOException $e) {
        error_log('[UPLOAD] Indirme sayaci guncellenemedi: ' . $e->getMessage());
    }
}


/**
 * Bir dosya adını Content-Disposition başlığına GÜVENLE gömülebilecek
 * hâle getirir ve başlığın parametre kısmını hazır olarak döndürür.
 *
 * Örnek çıktı:
 *   filename="Sirket Faturasi.png"; filename*=UTF-8''%C5%9Eirket%20...
 *
 * NEDEN BU FONKSİYON VAR?
 * Dosya adı KULLANICI GİRDİSİDİR ve başlık değerinin içine girer.
 * Temizlenmezse saldırgan tırnak işaretiyle başlıktan "kaçıp" kendi
 * parametresini ekleyebilir (ölçülmüş açık; ayrıntı download.php'de).
 * Bu yüzden burada İKİ ayrı temsil üretilir:
 *
 *   1. $ascii → Yalnızca güvenli ASCII karakterler. Tırnak ("),
 *      ters bölü (\), noktalı virgül (;) ve TÜM kontrol karakterleri
 *      (satır sonu dâhil) alt çizgiye çevrilir. Bu dize başlıktan
 *      kaçamaz; en kötü ihtimalle çirkin görünür.
 *
 *   2. $utf8 → RFC 5987 yüzde kodlaması. Türkçe karakterler burada
 *      kayıpsız taşınır; tarayıcı bu parametreyi görürse onu kullanır.
 *
 * @param  string $name Ham (kullanıcıdan gelen) dosya adı
 * @return string Başlığın parametre kısmı
 */
function download_filename_header(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        $name = 'dosya';
    }

    // Aşırı uzun adlar bazı sunucularda başlık sınırını zorlar; kırp.
    // mb_substr kullanılır ki çok baytlı bir karakter ORTADAN bölünüp
    // bozuk UTF-8 üretilmesin.
    if (mb_strlen($name, 'UTF-8') > 150) {
        $name = mb_substr($name, 0, 150, 'UTF-8');
    }

    /* --- 1) Geriye dönük uyumlu ASCII sürüm --------------------------
     * Türkçe karakterler okunabilir ASCII karşılıklarına çevrilir
     * (Ş→S, ı→i ...) ki eski istemcilerde ad tanınmaz hâle gelmesin. */
    $ascii = strtr($name, [
        'ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I',
        'ö' => 'o', 'Ö' => 'O', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U',
    ]);

    // Kalan ASCII olmayan her şeyi ve BAŞLIKTAN KAÇMAYA yarayan tüm
    // karakterleri ( " \ ; ve kontrol karakterleri ) alt çizgi yap.
    $ascii = preg_replace('/[^\x20-\x7E]|["\\\\;]/', '_', $ascii) ?? 'dosya';
    $ascii = trim($ascii);

    if ($ascii === '') {
        $ascii = 'dosya';
    }

    /* --- 2) RFC 5987 UTF-8 sürümü ------------------------------------
     * rawurlencode() boşluğu %20 yapar ve tırnak/noktalı virgül dâhil
     * tüm özel karakterleri kodlar; bu parametre yapı gereği başlıktan
     * kaçamaz. */
    $utf8 = rawurlencode($name);

    return sprintf('filename="%s"; filename*=UTF-8\'\'%s', $ascii, $utf8);
}


/* =====================================================================
 *  BÖLÜM 4 – VERİ ERİŞİMİ
 * ================================================================== */

/* Sorgularda sütun listesi tek yerde dursun: yeni bir sütun
 * eklendiğinde iki ayrı SELECT'i güncellemeyi unutma riski kalmasın. */
const FILE_COLUMNS = 'id, original_name, stored_path, mime, extension, size_bytes,
                      category, download_count, last_downloaded_at, uploaded_at';

function find_file(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT ' . FILE_COLUMNS . ' FROM files WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);

    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Dosyaları listeler. İsteğe bağlı süzgeçler arayüzdeki klasör/tür
 * gezinmesini besler.
 *
 * Süzgeç değerleri ASLA doğrudan SQL'e gömülmez; hazırlanmış ifade
 * parametresi olarak geçer (SQL enjeksiyonu savunması). Sıralama
 * sütunu ise parametre olamayacağı için BEYAZ LİSTEDEN seçilir.
 *
 * @param array{category?:string,period?:string,sort?:string} $filters
 */
function fetch_files(PDO $db, array $filters = []): array
{
    $where  = [];
    $params = [];

    // Kategori süzgeci (image / document)
    if (!empty($filters['category']) && in_array($filters['category'], ['image', 'document'], true)) {
        $where[]             = 'category = :category';
        $params[':category'] = $filters['category'];
    }

    // Dönem süzgeci: "2026-08" biçimi. Kalıba uymayan değer yok sayılır.
    if (!empty($filters['period']) && preg_match('/^\d{4}-\d{2}$/', (string) $filters['period'])) {
        $where[]           = "DATE_FORMAT(uploaded_at, '%Y-%m') = :period";
        $params[':period'] = $filters['period'];
    }

    // Sıralama: kullanıcı girdisi sütun adı olarak kullanılamayacağı
    // için sabit bir eşlemeden seçilir.
    $sortMap = [
        'newest'    => 'uploaded_at DESC, id DESC',
        'oldest'    => 'uploaded_at ASC, id ASC',
        'largest'   => 'size_bytes DESC',
        'popular'   => 'download_count DESC, id DESC',
        'name'      => 'original_name ASC',
    ];

    $orderBy = $sortMap[$filters['sort'] ?? 'newest'] ?? $sortMap['newest'];

    $sql = 'SELECT ' . FILE_COLUMNS . ' FROM files'
         . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY ' . $orderBy;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Arayüzdeki klasör ağacını beslemek için özet çıkarır:
 * hangi kategoride kaç dosya, hangi ay kaç dosya.
 *
 * Bu bilgiyi PHP'de tüm kayıtları çekip saymak yerine SQL'e
 * hesaplatıyoruz; binlerce kayıtta fark belirgindir.
 */
function fetch_file_stats(PDO $db): array
{
    $byCategory = $db->query(
        'SELECT category, COUNT(*) AS total FROM files GROUP BY category'
    )->fetchAll();

    $byPeriod = $db->query(
        "SELECT DATE_FORMAT(uploaded_at, '%Y-%m') AS period,
                COUNT(*) AS total
           FROM files
          GROUP BY period
          ORDER BY period DESC"
    )->fetchAll();

    $totals = $db->query(
        'SELECT COUNT(*) AS files,
                COALESCE(SUM(size_bytes), 0)     AS bytes,
                COALESCE(SUM(download_count), 0) AS downloads
           FROM files'
    )->fetch();

    return [
        'by_category' => $byCategory,
        'by_period'   => $byPeriod,
        'totals'      => $totals ?: ['files' => 0, 'bytes' => 0, 'downloads' => 0],
    ];
}


/* =====================================================================
 *  BÖLÜM 5 – BİÇİMLENDİRME
 * ================================================================== */

/** Bayt sayısını "2,4 MB" gibi okunabilir hâle getirir. */
function format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    // KB, MB, GB sırasıyla dener; her adımda 1024'e böler.
    $units = ['KB', 'MB', 'GB'];
    $value = $bytes / 1024;

    foreach ($units as $unit) {
        if ($value < 1024 || $unit === end($units)) {
            return number_format($value, $value < 10 ? 1 : 0, ',', '.') . ' ' . $unit;
        }

        $value /= 1024;
    }

    return $bytes . ' B'; // Buraya asla ulaşılmaz; statik analiz için.
}

function format_date(?string $value): string
{
    if (empty($value)) {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))->format('d.m.Y H:i');
    } catch (Exception $e) {
        return (string) $value;
    }
}

/**
 * Uzantıya göre dosya türü simgesi (emoji) döndürür.
 * Sadece görsel bir ipucudur; güvenlik kararı VERMEZ.
 */
function file_icon(string $extension): string
{
    return match ($extension) {
        'pdf'          => '📕',
        'txt'          => '📄',
        'zip'          => '🗜️',
        'docx'         => '📝',
        'xlsx'         => '📊',
        default        => '📁',
    };
}
