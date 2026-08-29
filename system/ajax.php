<?php
/**
 * =====================================================================
 *  AJAX UÇ NOKTASI (Endpoint)
 *  cilginyazilim.com – Güvenli Dosya Yükleme Sistemi
 * ---------------------------------------------------------------------
 *    action=list    → Yüklenmiş dosyaların listesi
 *    action=upload  → Bir veya birden fazla dosya yükle
 *    action=delete  → Dosyayı sil
 *
 *  DİKKAT: İNDİRME BURADA DEĞİLDİR. Bu dosya JSON döndürür; dosya
 *  indirme, tarayıcının "Content-Disposition: attachment" başlığını
 *  görüp kaydetme diyaloğu açabilmesi için AYRI bir uç noktadadır
 *  (system/download.php).
 * =====================================================================
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/function.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Yalnızca POST istekleri kabul edilir.', 405);
}

$action = isset($_POST['action']) ? strtolower(trim((string) $_POST['action'])) : 'list';

try {
    switch ($action) {
        case 'upload':
            handle_upload($db);
            break;

        case 'delete':
            handle_delete($db);
            break;

        case 'settings':
            handle_settings($db);
            break;

        case 'list':
        default:
            handle_list($db);
            break;
    }
} catch (PDOException $e) {
    error_log('[UPLOAD] Veritabani hatasi: ' . $e->getMessage());

    json_error(
        APP_DEBUG ? 'Veritabanı hatası: ' . $e->getMessage()
                  : 'Beklenmeyen bir veritabanı hatası oluştu.',
        500
    );
} catch (Throwable $e) {
    error_log('[UPLOAD] Hata: ' . $e->getMessage());

    json_error(
        APP_DEBUG ? 'Hata: ' . $e->getMessage() : 'Beklenmeyen bir hata oluştu.',
        500
    );
}


/* =====================================================================
 *  1) LİSTELEME
 * ================================================================== */
function handle_list(PDO $db): void
{
    require_csrf();

    // Süzgeçler istemciden gelir ama fetch_files() içinde beyaz listeden
    // geçer; buradan doğrudan SQL'e hiçbir şey sızmaz.
    $filters = [
        'category' => (string) ($_POST['category'] ?? ''),
        'period'   => (string) ($_POST['period'] ?? ''),
        'sort'     => (string) ($_POST['sort'] ?? 'newest'),
        'search'   => (string) ($_POST['search'] ?? ''),
    ];

    $files = array_map(static function (array $file): array {
        return [
            'id'            => (int) $file['id'],
            'original_name' => $file['original_name'],
            'extension'     => $file['extension'],
            'category'      => $file['category'],
            'size'          => format_bytes((int) $file['size_bytes']),
            'icon'          => $file['category'] === 'image' ? '' : file_icon($file['extension']),
            'uploaded_at'   => format_date($file['uploaded_at']),
            'downloads'     => (int) $file['download_count'],
            'download_url'  => 'system/download.php?id=' . $file['id'],

            // Klasör yolu artık eğik çizgi içeriyor. rawurlencode() eğik
            // çizgiyi de kodlardı (%2F) ve bağlantı bozulurdu; bu yüzden
            // her parçayı AYRI kodlayıp aralarına düz "/" koyuyoruz.
            'thumb_url'     => $file['category'] === 'image'
                ? UPLOAD_URL . implode('/', array_map('rawurlencode', explode('/', (string) $file['stored_path'])))
                : null,

            // Diskteki klasörü arayüzde göstermek için (öğretici amaçlı:
            // kullanıcı dosyanın nereye yazıldığını görsün).
            'folder'        => dirname((string) $file['stored_path']),
        ];
    }, fetch_files($db, $filters));

    json_response([
        'success' => true,
        'files'   => $files,
        'total'   => count($files),
        'stats'   => fetch_file_stats($db),
    ]);
}


/* =====================================================================
 *  4) AYARLAR (oku / kaydet)
 * =====================================================================
 *  GET yerine POST kullanılır ve CSRF zorunludur: ayar DEĞİŞTİRMEK
 *  durum değiştiren bir işlemdir, sahte istekle tetiklenmemelidir.
 * ------------------------------------------------------------------ */
function handle_settings(PDO $db): void
{
    require_csrf();

    $mode = strtolower(trim((string) ($_POST['mode'] ?? 'read')));

    if ($mode === 'save') {
        // Gelen liste dizi değilse boş kabul et; settings_save() zaten
        // katalogla kesiştirerek ikinci bir süzgeç uygular.
        $mimes = $_POST['allowed_mimes'] ?? [];

        if (!is_array($mimes)) {
            $mimes = [];
        }

        // Her öğe metin olmalı; dizi/nesne gelirse ele.
        $mimes = array_values(array_filter($mimes, 'is_string'));

        if ($mimes === []) {
            json_error('En az bir dosya türü seçmelisiniz.', 422);
        }

        settings_save(
            $db,
            $mimes,
            (int) ($_POST['max_bytes'] ?? UPLOAD_MAX_BYTES),
            (int) ($_POST['max_files'] ?? UPLOAD_MAX_FILES)
        );

        json_success('Ayarlar kaydedildi.', ['settings' => current_settings_payload($db)]);
    }

    json_response(['success' => true, 'settings' => current_settings_payload($db)]);
}

/**
 * Ayarlar ekranını çizmek için gereken her şeyi tek bir yapıda toplar:
 * kataloğun tamamı + hangilerinin etkin olduğu + sayısal sınırlar.
 */
function current_settings_payload(PDO $db): array
{
    // settings_all() istek içinde önbelleklenir; kaydetmenin hemen
    // ardından taze veri okuyabilmek için önbelleği atlayıp doğrudan
    // sorgulamak yerine, kaydetme sonrası yeni bir istek gelmesini
    // beklemek yeterli olmadığından burada tabloyu tekrar okuyoruz.
    $stmt = $db->query('SELECT name, value FROM settings');
    $raw  = [];

    foreach ($stmt as $row) {
        $raw[$row['name']] = json_decode((string) $row['value'], true);
    }

    $selected = is_array($raw['allowed_mimes'] ?? null) ? $raw['allowed_mimes'] : array_keys(SUPPORTED_UPLOAD_TYPES);

    $types = [];

    foreach (SUPPORTED_UPLOAD_TYPES as $mime => $meta) {
        $types[] = [
            'mime'     => $mime,
            'ext'      => $meta['ext'],
            'category' => $meta['category'],
            'label'    => $meta['label'],
            'enabled'  => in_array($mime, $selected, true),
        ];
    }

    return [
        'types'     => $types,
        'max_bytes' => setting_int($db, 'max_bytes', UPLOAD_MAX_BYTES, UPLOAD_MAX_BYTES),
        'max_files' => setting_int($db, 'max_files', UPLOAD_MAX_FILES, UPLOAD_MAX_FILES),

        // Tavan değerleri arayüzde göstermek için: kullanıcı neden
        // 8 MB'ın üstüne çıkamadığını görsün.
        'ceiling_bytes' => UPLOAD_MAX_BYTES,
        'ceiling_files' => UPLOAD_MAX_FILES,
    ];
}


/* =====================================================================
 *  2) YÜKLEME
 * =====================================================================
 *  Birden fazla dosya AYNI ANDA gönderilebilir ($_FILES['files'] bir
 *  dizi dizisidir: files.name[0], files.name[1], ...). Her dosya
 *  BAĞIMSIZ olarak doğrulanır; biri reddedilse bile diğerleri
 *  kaydedilir — kullanıcı 5 dosyadan 1'i hatalıysa 4'ünü kaybetmemelidir.
 * ------------------------------------------------------------------ */
function handle_upload(PDO $db): void
{
    require_csrf();

    if (!isset($_FILES['files']) || !is_array($_FILES['files']['name'] ?? null)) {
        json_error('Lütfen en az bir dosya seçin.', 422);
    }

    $count = count($_FILES['files']['name']);

    if ($count === 0) {
        json_error('Lütfen en az bir dosya seçin.', 422);
    }

    // Sınır artık ayarlardan gelir (tavanla kelepçelenmiş hâlde).
    $maxFiles = setting_int($db, 'max_files', UPLOAD_MAX_FILES, UPLOAD_MAX_FILES);

    if ($count > $maxFiles) {
        json_error('Tek seferde en fazla ' . $maxFiles . ' dosya yükleyebilirsiniz.', 422);
    }

    $saved  = [];
    $errors = [];

    for ($i = 0; $i < $count; $i++) {
        // PHP'nin çok dosyalı $_FILES yapısını TEK dosyalık bir
        // diziye çeviriyoruz; store_upload() böylece tek dosya
        // formatıyla çağrılabilir, kod tekrarlanmaz.
        $single = [
            'name'     => $_FILES['files']['name'][$i],
            'type'     => $_FILES['files']['type'][$i],
            'tmp_name' => $_FILES['files']['tmp_name'][$i],
            'error'    => $_FILES['files']['error'][$i],
            'size'     => $_FILES['files']['size'][$i],
        ];

        // Kullanıcı bazı slotları boş bıraktıysa (tarayıcı garipliği)
        // sessizce atla; bu bir hata değildir.
        if ((int) $single['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        try {
            $saved[] = store_upload($db, $single);
        } catch (RuntimeException $e) {
            $errors[] = basename((string) $single['name']) . ': ' . $e->getMessage();
        }
    }

    if ($saved === [] && $errors !== []) {
        json_error(implode(' | ', $errors), 422);
    }

    $description = count($saved) . ' dosya başarıyla yüklendi.';

    if ($errors !== []) {
        $description .= ' (' . count($errors) . ' dosya reddedildi: ' . implode(' | ', $errors) . ')';
    }

    json_success($description, ['uploaded' => count($saved), 'failed' => count($errors)]);
}


/* =====================================================================
 *  3) SİLME
 * ================================================================== */
function handle_delete(PDO $db): void
{
    require_csrf();

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($id === false || $id === null) {
        json_error('Geçersiz dosya numarası.');
    }

    $file = find_file($db, $id);

    if ($file === null) {
        json_error('Silinecek dosya bulunamadı.', 404);
    }

    $stmt = $db->prepare('DELETE FROM files WHERE id = :id');
    $stmt->execute([':id' => $id]);

    // Veritabanı kaydı gitti; şimdi diskteki dosyayı ve boş kalan
    // tür/yıl/ay klasörlerini de temizle.
    delete_stored_file($file['stored_path']);

    json_success('Dosya silindi.', ['id' => $id]);
}
