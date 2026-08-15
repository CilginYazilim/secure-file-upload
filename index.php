<?php
/**
 * =====================================================================
 *  ANA SAYFA (Sunum Katmanı)
 *  cilginyazilim.com – Güvenli Dosya Yükleme Sistemi
 * ---------------------------------------------------------------------
 *  Bu dosya SADECE arayüzü çizer. Gerçek güvenlik işi (MIME doğrulama,
 *  rastgele isimlendirme, vs.) system/function.php içindeki
 *  store_upload() fonksiyonundadır — arayüzü okumadan önce oraya
 *  bakmanızı öneririz.
 * =====================================================================
 */

declare(strict_types=1);

require __DIR__ . '/system/config.php';
require __DIR__ . '/system/function.php';

$csrfToken = csrf_token();

// ETKİN türler = katalog ∩ ayarlar. Arayüzdeki ipucu metni ve dosya
// seçicinin "accept" özniteliği bu TEK kaynaktan beslenir; elle
// yazılsaydı yönetici ayarlardan bir türü kapattığında arayüz metni
// yalan söylemeye devam ederdi.
$allowedTypes      = allowed_upload_types($db);
$allowedExtensions = array_unique(array_column($allowedTypes, 'ext'));
sort($allowedExtensions);

// Sınırlar da ayarlardan okunur (config.php'deki tavanla kelepçeli).
$maxBytes = setting_int($db, 'max_bytes', UPLOAD_MAX_BYTES, UPLOAD_MAX_BYTES);
$maxFiles = setting_int($db, 'max_files', UPLOAD_MAX_FILES, UPLOAD_MAX_FILES);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="Çılgın Yazılım - cilginyazilim.com">
    <meta name="description" content="PHP ile katmanlı güvenlik uygulanmış dosya yükleme sistemi: MIME doğrulama, rastgele isimlendirme, .htaccess çalıştırma kilidi.">

    <meta name="csrf-token" content="<?= e($csrfToken) ?>">

    <title>Güvenli Dosya Yükleme | Çılgın Yazılım</title>

    <link rel="icon" type="image/png" href="assets/images/logo.png">

    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/cilginyazilim.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>

<body class="cy-app">

    <div class="cy-topbar"></div>

    <div class="container py-4 py-lg-5">

        <div class="cy-card">

            <div class="cy-card__header">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <a class="cy-brand" href="https://cilginyazilim.com" target="_blank" rel="noopener">
                        <span class="cy-brand__mark">
                            <img src="assets/images/logo.png" alt="Çılgın Yazılım logosu">
                        </span>
                        <div>
                            <h1 class="cy-brand__title">Güvenli Dosya Yükleme</h1>
                            <p class="cy-brand__subtitle">
                                MIME doğrulama &middot; Rastgele isimlendirme &middot; cilginyazilim.com
                            </p>
                        </div>
                    </a>

                    <div class="d-flex align-items-center gap-2">
                        <span class="cy-badge cy-badge--glass">
                            Toplam <strong id="total_files">0</strong> dosya
                        </span>

                        <button type="button" class="btn btn-light cy-btn btn-sm" id="open_settings"
                                title="Ayarlar" aria-label="Ayarları aç">
                            &#9881; Ayarlar
                        </button>
                    </div>
                </div>
            </div>

            <div class="cy-card__body">

                <!-- ============================================================
                     SÜRÜKLE-BIRAK YÜKLEME ALANI
                     ------------------------------------------------------------
                     NEDEN dragover/drop olayları? HTML5'in yerleşik
                     DataTransfer API'sidir; dosyanın kendisini (bayt
                     içeriğiyle) tarayıcıdan JavaScript'e taşımanın tek
                     standart yoludur. Bu, todo-drag-drop projesindeki
                     KART sürüklemesinden TAMAMEN FARKLI bir mekanizmadır:
                     orada DOM öğeleri taşınıyordu (Pointer Events), burada
                     işletim sisteminden dosya taşınıyor (Drag & Drop API).
                     ============================================================ -->
                <div id="dropzone" class="cy-dropzone" tabindex="0" role="button"
                     aria-label="Dosya yüklemek için tıklayın veya sürükleyip bırakın">
                    <div class="cy-dropzone__icon" aria-hidden="true">⬆</div>
                    <p class="cy-dropzone__title mb-1">Dosyaları buraya sürükleyin</p>
                    <p class="cy-dropzone__hint mb-0" id="dropzone_hint">
                        veya tıklayarak seçin &middot;
                        <?= e(strtoupper(implode(', ', $allowedExtensions))) ?> &middot;
                        en fazla <?= e(format_bytes($maxBytes)) ?>/dosya &middot;
                        en fazla <?= (int) $maxFiles ?> dosya
                    </p>
                    <input type="file" id="file_input" class="visually-hidden" multiple
                           accept="<?= e(implode(',', array_keys($allowedTypes))) ?>">
                </div>

                <!-- Yükleme sırasındaki dosya bazlı ilerleme listesi -->
                <div id="upload_queue" class="cy-upload-queue"></div>

                <hr class="my-4">

                <!-- ============================================================
                     ÖZET ŞERİDİ
                     ------------------------------------------------------------
                     Toplamlar SQL'de hesaplanır (COUNT/SUM), PHP'de değil;
                     binlerce kayıtta tüm satırları çekip saymak gereksiz
                     bellek ve süre harcardı. (bkz. fetch_file_stats())
                     ============================================================ -->
                <div class="cy-stats" id="stats_bar">
                    <div class="cy-stat">
                        <span class="cy-stat__value" id="stat_files">0</span>
                        <span class="cy-stat__label">Dosya</span>
                    </div>
                    <div class="cy-stat">
                        <span class="cy-stat__value" id="stat_size">0 B</span>
                        <span class="cy-stat__label">Toplam Boyut</span>
                    </div>
                    <div class="cy-stat">
                        <span class="cy-stat__value" id="stat_downloads">0</span>
                        <span class="cy-stat__label">İndirme</span>
                    </div>
                    <div class="cy-stat">
                        <span class="cy-stat__value" id="stat_folders">0</span>
                        <span class="cy-stat__label">Klasör (Ay)</span>
                    </div>
                </div>

                <!-- ============================================================
                     SÜZGEÇ ÇUBUĞU
                     ------------------------------------------------------------
                     Süzgeçler SUNUCUDA uygulanır (SQL WHERE), tarayıcıda
                     değil. Nedeni: 10.000 kayıtlık bir arşivde tüm listeyi
                     indirip JavaScript'te filtrelemek hem yavaş hem
                     gereksiz veri transferidir.
                     ============================================================ -->
                <div class="cy-filterbar">
                    <span class="cy-filterbar__label">Tür</span>
                    <div class="d-flex flex-wrap gap-1" id="filter_category">
                        <button type="button" class="cy-chip cy-chip--active" data-category="">Tümü</button>
                        <button type="button" class="cy-chip" data-category="image">🖼 Görseller</button>
                        <button type="button" class="cy-chip" data-category="document">📄 Belgeler</button>
                    </div>

                    <span class="cy-filterbar__label ms-lg-2">Klasör</span>
                    <div class="d-flex flex-wrap gap-1" id="filter_period">
                        <button type="button" class="cy-chip cy-chip--active" data-period="">Tümü</button>
                        <!-- Ay düğmeleri JavaScript ile, gerçek verilerden üretilir -->
                    </div>

                    <span class="cy-filterbar__label ms-lg-2">Sırala</span>
                    <select class="form-select form-select-sm w-auto" id="filter_sort" aria-label="Sıralama">
                        <option value="newest">En yeni</option>
                        <option value="oldest">En eski</option>
                        <option value="largest">En büyük</option>
                        <option value="popular">En çok indirilen</option>
                        <option value="name">Ada göre</option>
                    </select>
                </div>

                <!-- ---------- Yüklenmiş dosyalar listesi ---------- -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h6 mb-0">Yüklenen Dosyalar</h2>
                </div>

                <div id="file_list" class="cy-file-grid">
                    <div class="cy-file-grid__empty">Yükleniyor…</div>
                </div>
            </div>

            <div class="cy-card__footer d-flex flex-wrap justify-content-between gap-2">
                <span>CSRF korumalı AJAX &middot; MIME içerikten doğrulanır &middot; .htaccess çalıştırma kilidi</span>
                <span>PHP <?= e(PHP_VERSION) ?></span>
            </div>
        </div>

        <div class="cy-footer-note mt-4">
            <p class="mb-1">
                Bu açık kaynak örnek, <a href="https://cilginyazilim.com" target="_blank" rel="noopener">cilginyazilim.com</a>
                tarafından geliştirilmiştir. MIT lisanslıdır.
            </p>
            <p class="mb-0">
                Kaynak kod:
                <a href="https://github.com/CilginYazilim/secure-file-upload"
                   target="_blank" rel="noopener">github.com/CilginYazilim/secure-file-upload</a>
            </p>
        </div>
    </div>


    <!-- ================================================================
         MODAL – SİLME ONAYI
         ================================================================ -->
    <div class="modal fade cy-modal" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h6 mb-0" id="deleteModalLabel">Dosyayı Sil</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="delete-icon" aria-hidden="true">!</div>
                    <p class="mb-0"><strong id="delete_label"></strong> dosyası <u>kalıcı olarak</u> silinecek.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary cy-btn btn-sm" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="button" class="btn btn-danger cy-btn btn-sm" id="confirm_delete">Evet, Sil</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================
         MODAL – AYARLAR
         ----------------------------------------------------------------
         Buradan seçilen türler `settings` tablosuna yazılır. ÖNEMLİ:
         bu ekran yalnızca config.php'deki KATALOĞU açıp kapatabilir,
         katalog dışına çıkamaz. Yani ayarlardan ".php" yüklemeyi
         etkinleştirmek MÜMKÜN DEĞİLDİR — güvenlik sınırı kodda,
         veritabanında değil.
         ================================================================ -->
    <div class="modal fade cy-modal" id="settingsModal" tabindex="-1" aria-labelledby="settingsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h6 mb-0" id="settingsModalLabel">&#9881; Yükleme Ayarları</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>

                <div class="modal-body">

                    <div class="cy-settings-group">
                        <div class="cy-settings-group__title">İzin verilen dosya türleri</div>
                        <div class="cy-type-grid" id="settings_types">
                            <!-- Kutucuklar JavaScript ile katalogdan üretilir -->
                        </div>
                    </div>

                    <div class="cy-settings-group">
                        <div class="cy-settings-group__title">Sınırlar</div>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label small mb-1" for="settings_max_mb">
                                    Dosya başına en büyük boyut (MB)
                                </label>
                                <input type="number" class="form-control form-control-sm"
                                       id="settings_max_mb" min="1" step="1">
                                <div class="form-text small" id="settings_max_mb_hint"></div>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small mb-1" for="settings_max_files">
                                    Tek seferde en fazla dosya
                                </label>
                                <input type="number" class="form-control form-control-sm"
                                       id="settings_max_files" min="1" step="1">
                                <div class="form-text small" id="settings_max_files_hint"></div>
                            </div>
                        </div>
                    </div>

                    <p class="cy-settings-note mb-0">
                        <strong>Güvenlik sınırı:</strong> Bu ekran yalnızca
                        <code>system/config.php</code> içindeki <code>SUPPORTED_UPLOAD_TYPES</code>
                        kataloğunda <u>zaten var olan</u> türleri açıp kapatır. Katalogda olmayan bir
                        tür (örneğin <code>.php</code>) buradan <u>etkinleştirilemez</u>; sunucu her
                        yüklemede ayarları katalogla kesiştirir. Boyut/sayı değerleri de
                        <code>config.php</code>'deki tavanı aşamaz.
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary cy-btn btn-sm" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="button" class="btn btn-primary cy-btn btn-sm" id="save_settings">Kaydet</button>
                </div>
            </div>
        </div>
    </div>

    <div class="toast-container cy-toast-container position-fixed top-0 end-0 p-3" id="toast_container"></div>

    <script src="assets/js/jquery-3.7.0.js"></script>
    <script src="assets/js/bootstrap.bundle.js"></script>
    <script src="assets/js/upload.js?v=<?= filemtime(__DIR__ . '/assets/js/upload.js') ?>"></script>
    <script>
        CyUpload.init({
            endpoint:   'system/ajax.php',
            csrfToken:  <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>,
            maxBytes:   <?= (int) $maxBytes ?>,
            maxFiles:   <?= (int) $maxFiles ?>,
            acceptMime: <?= json_encode(array_keys($allowedTypes)) ?>
        });
    </script>
</body>
</html>
