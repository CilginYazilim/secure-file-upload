/* =====================================================================
 *  DOSYA YÜKLEME ARAYÜZÜ
 *  cilginyazilim.com – Güvenli Dosya Yükleme Sistemi
 * ---------------------------------------------------------------------
 *  ALTIN KURAL: Buradaki hiçbir kontrol GÜVENLİK ÖNLEMİ DEĞİLDİR.
 *  Dosya türü/boyutu kontrolleri yalnızca KULLANICI DENEYİMİ içindir —
 *  kötü niyetli biri bu JavaScript'i hiç çalıştırmadan doğrudan
 *  system/ajax.php'ye istek gönderebilir. Gerçek güvenlik SUNUCUDADIR
 *  (bkz. system/function.php store_upload()).
 * ================================================================== */

/* global jQuery, bootstrap */

var CyUpload = (function ($) {
    'use strict';

    var config = {
        endpoint:   'system/ajax.php',
        csrfToken:  '',
        maxBytes:   8 * 1024 * 1024,
        maxFiles:   10,
        acceptMime: []
    };

    var pendingDeleteId = null;
    var deleteModal     = null;
    var settingsModal   = null;
    var previewModal    = null;

    // Aramanın "debounce" zamanlayıcısı (bkz. bindEvents).
    var searchTimer = null;

    // Etkin süzgeçler. Sunucuya her listeleme isteğinde gönderilir;
    // filtreleme SQL tarafında yapılır (bkz. fetch_files()).
    var filters = { category: '', period: '', sort: 'newest', search: '' };

    // Tarayıcı depolamasında kullanılan anahtarlar. Gizli sekmede
    // localStorage erişimi HATA FIRLATABİLİR; bu yüzden her erişim
    // try/catch içine alınır ve başarısızlık sessizce yutulur —
    // görünüm tercihi uygulamanın çalışması için kritik değildir.
    var STORE_THEME = 'cy-theme';
    var STORE_VIEW  = 'cy-view';

    function storeGet(key) {
        try { return localStorage.getItem(key); } catch (e) { return null; }
    }

    function storeSet(key, value) {
        try { localStorage.setItem(key, value); } catch (e) { /* yok say */ }
    }

    /* =================================================================
     *  YARDIMCILAR
     * ============================================================== */

    function notify(message, type) {
        var $toast = $(
            '<div class="toast cy-toast cy-toast--' + (type || 'success') + '"' +
                 ' role="alert" aria-live="assertive" aria-atomic="true">' +
                '<div class="d-flex">' +
                    '<div class="toast-body"></div>' +
                    '<button type="button" class="btn-close btn-close-white me-2 m-auto"' +
                          ' data-bs-dismiss="toast" aria-label="Kapat"></button>' +
                '</div>' +
            '</div>'
        );

        // .text() KULLANILIR: sunucudan gelen mesaj (örn. reddedilen
        // dosya adı) kullanıcı girdisi içerebilir; .html() olsaydı XSS
        // açığı oluşurdu.
        $toast.find('.toast-body').text(message);
        $('#toast_container').append($toast);

        var toast = new bootstrap.Toast($toast[0], { delay: 5000 });
        $toast.on('hidden.bs.toast', function () { $toast.remove(); });
        toast.show();
    }

    function bytesToLabel(bytes) {
        if (bytes < 1024) { return bytes + ' B'; }
        if (bytes < 1024 * 1024) { return (bytes / 1024).toFixed(1) + ' KB'; }
        return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    }

    function post(data) {
        data.csrf_token = config.csrfToken;

        return $.ajax({ url: config.endpoint, method: 'POST', dataType: 'json', data: data });
    }


    /* =================================================================
     *  DOSYA LİSTESİ
     * ============================================================== */

    function loadFiles() {
        return post({
                action:   'list',
                category: filters.category,
                period:   filters.period,
                sort:     filters.sort,
                search:   filters.search
            })
            .done(function (response) {
                renderFiles(response.files);
                renderStats(response.stats);
                renderPeriodFilters(response.stats);
                $('#total_files').text(response.total);
            })
            .fail(function () {
                notify('Dosya listesi yüklenemedi.', 'danger');
            });
    }

    /**
     * Özet şeridini doldurur.
     * Sunucu ham sayıları gönderir; biçimlendirme burada yapılır ki
     * aynı sayı hem rozette hem şeritte tutarlı görünsün.
     */
    function renderStats(stats) {
        if (!stats) { return; }

        var totals = stats.totals || {};

        $('#stat_files').text(totals.files || 0);
        $('#stat_size').text(bytesToLabel(Number(totals.bytes) || 0));
        $('#stat_downloads').text(totals.downloads || 0);
        $('#stat_folders').text((stats.by_period || []).length);
    }

    /**
     * Ay (klasör) süzgeç düğmelerini GERÇEK verilerden üretir.
     *
     * NEDEN sabit bir liste değil? Hangi aylarda dosya olduğunu ancak
     * veritabanı bilir; elle yazılmış bir ay listesi boş klasörler
     * gösterir veya yeni ayı atlardı.
     */
    function renderPeriodFilters(stats) {
        var $wrap = $('#filter_period');

        // "Tümü" düğmesi HTML'de sabit durur; gerisini yeniden çiz.
        $wrap.find('[data-period]:not([data-period=""])').remove();

        $.each((stats && stats.by_period) || [], function (_, row) {
            $('<button>', {
                type: 'button',
                'class': 'cy-chip' + (filters.period === row.period ? ' cy-chip--active' : ''),
                'data-period': row.period,
                text: formatPeriod(row.period)
            })
            .append($('<span>', { 'class': 'cy-chip__count', text: row.total }))
            .appendTo($wrap);
        });
    }

    /** "2026-08" → "Ağustos 2026" */
    function formatPeriod(period) {
        var names = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
                     'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
        var parts = String(period).split('-');

        return (names[parseInt(parts[1], 10) - 1] || parts[1]) + ' ' + parts[0];
    }

    /**
     * Yüklenmiş dosyaları kart ızgarası olarak çizer.
     *
     * TÜM METİNLER .text() İLE YAZILIR: original_name kullanıcı
     * girdisidir (dosya adı olarak <script>.png gönderilebilir);
     * .html() kullanılsaydı XSS açığı oluşurdu.
     */
    function renderFiles(files) {
        var $grid = $('#file_list').empty();

        if (files.length === 0) {
            // Mesaj duruma göre değişir: süzgeç yüzünden mi boş, yoksa
            // gerçekten hiç dosya mı yok? "Henüz dosya yüklenmedi"
            // demek, aramada sonuç bulunamadığında YANILTICI olurdu.
            var filtered = filters.search !== '' || filters.category !== '' || filters.period !== '';

            $grid.append($('<div>', {
                'class': 'cy-file-grid__empty',
                text: filtered
                    ? 'Bu süzgeçlere uyan dosya bulunamadı.'
                    : 'Henüz dosya yüklenmedi.'
            }));
            return;
        }

        $.each(files, function (_, file) {
            var $card = $('<article>', { 'class': 'cy-file-card', 'data-id': file.id });

            var $thumb = $('<div>', { 'class': 'cy-file-card__thumb' }).appendTo($card);

            if (file.thumb_url) {
                // Görseller tıklanabilir: büyük önizleme penceresini açar.
                // Belgelerde önizleme yok, o yüzden yalnızca burada
                // düğme rolü ve klavye erişimi veriyoruz.
                $thumb.addClass('cy-file-card__thumb--clickable')
                      .attr({ role: 'button', tabindex: 0,
                              'aria-label': 'Önizle: ' + file.original_name })
                      .data('preview', {
                          url:  file.thumb_url,
                          name: file.original_name,
                          meta: file.size + ' · ' + file.uploaded_at,
                          download: file.download_url
                      });

                $('<img>', { src: file.thumb_url, alt: file.original_name, loading: 'lazy' }).appendTo($thumb);
            } else {
                $thumb.text(file.icon);
            }

            // İndirme sayacı rozeti: yalnızca en az bir kez indirilmişse
            // gösterilir; her kartta "0" yazmak gürültü olurdu.
            if (file.downloads > 0) {
                $('<span>', {
                    'class': 'cy-file-card__downloads',
                    title: file.downloads + ' kez indirildi',
                    text: '⬇ ' + file.downloads
                }).appendTo($thumb);
            }

            var $body = $('<div>', { 'class': 'cy-file-card__body' }).appendTo($card);

            $('<p>', { 'class': 'cy-file-card__name', text: file.original_name, title: file.original_name }).appendTo($body);
            $('<p>', { 'class': 'cy-file-card__meta', text: file.size + ' · ' + file.uploaded_at }).appendTo($body);

            // Dosyanın diskte hangi klasöre yazıldığını göster (öğretici):
            // kullanıcı tür/yıl/ay ağacını somut olarak görsün.
            $('<span>', {
                'class': 'cy-file-card__folder',
                title: 'Diskteki klasör: uploads/' + file.folder,
                text: file.folder
            }).appendTo($body);

            var $actions = $('<div>', { 'class': 'cy-file-card__actions' }).appendTo($card);

            // İndirme, standart bir <a href> bağlantısıdır (bkz.
            // system/download.php); AJAX ile "dosya indirme" taklit
            // etmeye ÇALIŞILMAZ, tarayıcının kendi indirme akışı kullanılır.
            $('<a>', {
                'class': 'cy-btn-icon cy-btn-icon--view',
                href: file.download_url,
                title: 'İndir', 'aria-label': 'İndir: ' + file.original_name,
                html: '&#11015;'
            }).appendTo($actions);

            $('<button>', {
                type: 'button', 'class': 'cy-btn-icon cy-btn-icon--delete js-delete',
                title: 'Sil', 'aria-label': 'Sil: ' + file.original_name,
                html: '&#128465;'
            }).appendTo($actions);

            $grid.append($card);
        });
    }


    /* =================================================================
     *  YÜKLEME KUYRUĞU (sürükle-bırak / dosya seçici)
     * ============================================================== */

    /**
     * Seçilen/dosürüklenen dosyaları doğrular (YALNIZCA KULLANICI
     * DENEYİMİ İÇİN) ve sunucuya yükler.
     *
     * @param {FileList|File[]} fileList
     */
    function handleFiles(fileList) {
        var files = Array.prototype.slice.call(fileList);

        if (files.length === 0) { return; }

        if (files.length > config.maxFiles) {
            notify('Tek seferde en fazla ' + config.maxFiles + ' dosya yükleyebilirsiniz.', 'danger');
            return;
        }

        var oversized = files.filter(function (f) { return f.size > config.maxBytes; });

        if (oversized.length > 0) {
            notify(
                oversized.map(function (f) { return f.name; }).join(', ')
                + ' dosyası çok büyük (en fazla ' + bytesToLabel(config.maxBytes) + ').',
                'danger'
            );
            return;
        }

        uploadFiles(files);
    }

    /**
     * Dosyaları FormData ile TEK bir istekte sunucuya gönderir ve
     * ilerleme çubuğunu XMLHttpRequest'in 'progress' olayıyla günceller.
     *
     * NEDEN $.ajax içinde xhr: fonksiyonu?
     * jQuery'nin kendi API'si yükleme ilerlemesini doğrudan vermez;
     * tarayıcının ham XMLHttpRequest nesnesine erişip 'progress'
     * olayını KENDİMİZ dinlememiz gerekir.
     */
    function uploadFiles(files) {
        var formData = new FormData();
        formData.append('action', 'upload');
        formData.append('csrf_token', config.csrfToken);

        $.each(files, function (_, file) {
            formData.append('files[]', file);
        });

        var $row = $(
            '<div class="cy-upload-row">' +
                '<span class="cy-upload-row__label"></span>' +
                '<div class="progress cy-upload-row__bar"><div class="progress-bar" role="progressbar"></div></div>' +
            '</div>'
        );

        var label = files.length === 1 ? files[0].name : files.length + ' dosya';
        $row.find('.cy-upload-row__label').text(label + ' yükleniyor…');
        $('#upload_queue').append($row);

        $.ajax({
            url: config.endpoint,
            method: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            xhr: function () {
                var xhr = $.ajaxSettings.xhr();

                if (xhr.upload) {
                    xhr.upload.addEventListener('progress', function (event) {
                        if (!event.lengthComputable) { return; }

                        var percent = Math.round((event.loaded / event.total) * 100);
                        $row.find('.progress-bar').css('width', percent + '%').text(percent + '%');
                    });
                }

                return xhr;
            }
        })
        .done(function (response) {
            notify(response.description, response.failed > 0 ? 'info' : 'success');
            loadFiles();
        })
        .fail(function (xhr) {
            var res = xhr.responseJSON || {};
            notify(res.description || 'Yükleme başarısız oldu.', 'danger');
        })
        .always(function () {
            $row.remove();
        });
    }


    /* =================================================================
     *  OLAY BAĞLAMA
     * ============================================================== */
    function bindEvents() {
        var dropzone = document.getElementById('dropzone');
        var fileInput = document.getElementById('file_input');

        /* --- Tıklayarak seçim --- */
        dropzone.addEventListener('click', function () { fileInput.click(); });

        // Erişilebilirlik: Enter/Space ile de dosya seçici açılabilsin
        // (dropzone bir <div role="button"> olduğu için tarayıcı bu
        // tuşları OTOMATİK yönetmez, kendimiz bağlamamız gerekir).
        dropzone.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                fileInput.click();
            }
        });

        fileInput.addEventListener('change', function () {
            handleFiles(fileInput.files);
            fileInput.value = ''; // Aynı dosyayı ikinci kez seçebilmek için sıfırla.
        });

        /* --- Sürükle-bırak ---
         * dragover'da preventDefault() ŞARTTIR; yoksa tarayıcı
         * "drop" olayını hiç tetiklemez, varsayılan davranışı
         * (dosyayı yeni sekmede açmak) uygular. */
        ['dragenter', 'dragover'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
                event.preventDefault();
                dropzone.classList.add('cy-dropzone--active');
            });
        });

        ['dragleave', 'drop'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
                event.preventDefault();
                dropzone.classList.remove('cy-dropzone--active');
            });
        });

        dropzone.addEventListener('drop', function (event) {
            var files = event.dataTransfer ? event.dataTransfer.files : [];
            handleFiles(files);
        });

        /* --- Görsel önizleme (lightbox) --- */
        $('#file_list').on('click', '.cy-file-card__thumb--clickable', function () {
            openPreview($(this).data('preview'));
        });

        // Klavye erişilebilirliği: küçük resim bir <div role="button">
        // olduğu için Enter/Space'i tarayıcı kendiliğinden yönetmez.
        $('#file_list').on('keydown', '.cy-file-card__thumb--clickable', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openPreview($(this).data('preview'));
            }
        });

        /* --- Arama (debounce'lu) ---
         * Her tuş vuruşunda istek atmak, 10 harflik bir aramada 10
         * gereksiz sorgu demektir. 300 ms'lik duraklama beklenir;
         * kullanıcı yazmayı bıraktığında TEK istek gider. */
        $('#filter_search').on('input', function () {
            var value = $(this).val();

            $('#clear_search').prop('hidden', String(value) === '');

            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                filters.search = String(value).trim();
                loadFiles();
            }, 300);
        });

        $('#clear_search').on('click', function () {
            $('#filter_search').val('').trigger('input').trigger('focus');
        });

        /* --- Izgara / liste görünümü --- */
        $('.cy-viewswitch').on('click', '[data-view]', function () {
            applyView($(this).data('view'));
        });

        /* --- Tema anahtarı --- */
        $('#toggle_theme').on('click', function () {
            var current = document.documentElement.getAttribute('data-cy-theme');

            // Kayıtlı bir tercih yoksa işletim sisteminin temasına
            // bakıp ONUN TERSİNE geçiyoruz; aksi hâlde koyu temadaki
            // bir kullanıcının ilk tıklaması hiçbir şey değiştirmezdi.
            if (!current) {
                current = window.matchMedia
                    && window.matchMedia('(prefers-color-scheme: dark)').matches
                    ? 'dark' : 'light';
            }

            applyTheme(current === 'dark' ? 'light' : 'dark');
        });

        /* --- Silme --- */
        $('#file_list').on('click', '.js-delete', function () {
            var $card = $(this).closest('.cy-file-card');

            pendingDeleteId = $card.data('id');
            $('#delete_label').text($card.find('.cy-file-card__name').text());
            deleteModal.show();
        });

        /* --- Süzgeçler ---
         * Olaylar kapsayıcıya bağlanır (event delegation): ay düğmeleri
         * sonradan JavaScript ile üretildiği için, tek tek bağlansaydı
         * yeni düğmeler tıklanamaz olurdu. */
        $('#filter_category').on('click', '[data-category]', function () {
            filters.category = $(this).data('category') || '';
            $('#filter_category .cy-chip').removeClass('cy-chip--active');
            $(this).addClass('cy-chip--active');
            loadFiles();
        });

        $('#filter_period').on('click', '[data-period]', function () {
            filters.period = $(this).data('period') || '';
            $('#filter_period .cy-chip').removeClass('cy-chip--active');
            $(this).addClass('cy-chip--active');
            loadFiles();
        });

        $('#filter_sort').on('change', function () {
            filters.sort = $(this).val();
            loadFiles();
        });

        /* --- Ayarlar --- */
        $('#open_settings').on('click', openSettings);
        $('#save_settings').on('click', saveSettings);

        $('#confirm_delete').on('click', function () {
            if (pendingDeleteId === null) { return; }

            var $button = $(this).prop('disabled', true);

            post({ action: 'delete', id: pendingDeleteId })
                .done(function (response) {
                    notify(response.description, 'success');
                    loadFiles();
                })
                .fail(function (xhr) {
                    var res = xhr.responseJSON || {};
                    notify(res.description || 'Dosya silinemedi.', 'danger');
                })
                .always(function () {
                    $button.prop('disabled', false);
                    deleteModal.hide();
                    pendingDeleteId = null;
                });
        });
    }


    /* =================================================================
     *  GÖRÜNÜM (tema / ızgara-liste / önizleme)
     * ============================================================== */

    /**
     * Görsel önizleme penceresini açar.
     *
     * Kaynak URL sunucudan gelir ve uploads/ altını gösterir; o klasör
     * .htaccess ile hem çalıştırmaya hem MIME tahminine kapalıdır.
     * Metinler yine .text()/attr ile yazılır — dosya adı kullanıcı
     * girdisidir.
     */
    function openPreview(data) {
        if (!data) { return; }

        $('#preview_image').attr({ src: data.url, alt: data.name });
        $('#previewModalLabel').text(data.name);
        $('#preview_meta').text(data.meta);
        $('#preview_download').attr('href', data.download);

        previewModal.show();
    }

    /** Izgara / liste görünümünü uygular ve tercihi saklar. */
    function applyView(view) {
        view = (view === 'list') ? 'list' : 'grid';

        $('#file_list').toggleClass('cy-file-grid--list', view === 'list');

        $('.cy-viewswitch [data-view]')
            .removeClass('cy-chip--active')
            .filter('[data-view="' + view + '"]')
            .addClass('cy-chip--active');

        storeSet(STORE_VIEW, view);
    }

    /** Açık/koyu temayı uygular ve tercihi saklar. */
    function applyTheme(theme) {
        theme = (theme === 'dark') ? 'dark' : 'light';

        document.documentElement.setAttribute('data-cy-theme', theme);
        storeSet(STORE_THEME, theme);

        // Simge, BİR SONRAKİ duruma işaret eder: koyu temadayken
        // "güneş" görünür, çünkü tıklayınca aydınlığa geçilecektir.
        $('#theme_icon').text(theme === 'dark' ? '☀️' : '🌙');

        // Mobil adres çubuğu rengi de temayla birlikte değişsin.
        $('meta[name="theme-color"]').attr('content', theme === 'dark' ? '#070f1a' : '#0b5cb5');
    }


    /* =================================================================
     *  AYARLAR
     * ==============================================================
     *  Ayar ekranı, kataloğu SUNUCUDAN ister — türler istemcide sabit
     *  yazılsaydı, config.php'ye yeni bir tür eklendiğinde bu ekran
     *  onu göstermezdi.
     * ============================================================== */

    function openSettings() {
        post({ action: 'settings', mode: 'read' })
            .done(function (response) {
                renderSettings(response.settings);
                settingsModal.show();
            })
            .fail(function () {
                notify('Ayarlar okunamadı.', 'danger');
            });
    }

    function renderSettings(settings) {
        var $grid = $('#settings_types').empty();

        $.each(settings.types, function (_, type) {
            var $label = $('<label>', { 'class': 'cy-type-option' });

            $('<input>', {
                type: 'checkbox',
                'class': 'form-check-input mt-0 js-type',
                value: type.mime,
                checked: type.enabled
            }).appendTo($label);

            var $text = $('<span>', { 'class': 'cy-type-option__label' }).appendTo($label);

            $('<span>', { 'class': 'cy-type-option__ext', text: '.' + type.ext }).appendTo($text);
            $text.append(document.createTextNode(' ' + type.label));

            // MIME türü kullanıcı girdisi değil ama alışkanlık olsun:
            // metin her zaman .text()/createTextNode ile yazılır.
            $('<span>', { 'class': 'cy-type-option__mime', text: type.mime }).appendTo($text);

            $grid.append($label);
        });

        // Baytı MB'a çevirip göster; kullanıcı bayt düşünmek zorunda kalmasın.
        $('#settings_max_mb').val(Math.round(settings.max_bytes / 1024 / 1024))
            .attr('max', Math.floor(settings.ceiling_bytes / 1024 / 1024));
        $('#settings_max_files').val(settings.max_files)
            .attr('max', settings.ceiling_files);

        $('#settings_max_mb_hint').text(
            'Üst sınır: ' + Math.floor(settings.ceiling_bytes / 1024 / 1024) + ' MB (config.php)'
        );
        $('#settings_max_files_hint').text(
            'Üst sınır: ' + settings.ceiling_files + ' dosya (config.php)'
        );
    }

    function saveSettings() {
        var mimes = $('#settings_types .js-type:checked').map(function () {
            return this.value;
        }).get();

        if (mimes.length === 0) {
            notify('En az bir dosya türü seçmelisiniz.', 'danger');
            return;
        }

        var $button = $('#save_settings').prop('disabled', true);

        post({
            action:         'settings',
            mode:           'save',
            allowed_mimes:  mimes,
            max_bytes:      Math.max(1, parseInt($('#settings_max_mb').val(), 10) || 1) * 1024 * 1024,
            max_files:      Math.max(1, parseInt($('#settings_max_files').val(), 10) || 1)
        })
        .done(function (response) {
            notify(response.description, 'success');

            // Sunucu KAYDEDİLMİŞ hâli geri gönderir; istemcideki
            // sınırları ondan güncelliyoruz. Kullanıcının yazdığı
            // değeri değil, sunucunun KABUL ETTİĞİ değeri kullanmak
            // önemli: tavanı aşan bir giriş sessizce kırpılmış olabilir.
            var saved = response.settings;

            config.maxBytes = saved.max_bytes;
            config.maxFiles = saved.max_files;

            renderSettings(saved);
            updateDropzoneHint(saved);
            settingsModal.hide();
        })
        .fail(function (xhr) {
            var res = xhr.responseJSON || {};
            notify(res.description || 'Ayarlar kaydedilemedi.', 'danger');
        })
        .always(function () {
            $button.prop('disabled', false);
        });
    }

    /** Sürükle-bırak alanındaki ipucu metnini yeni ayarlara göre tazeler. */
    function updateDropzoneHint(settings) {
        var extensions = settings.types
            .filter(function (t) { return t.enabled; })
            .map(function (t) { return t.ext.toUpperCase(); });

        // Aynı uzantı iki MIME'den gelebilir (docx/zip); tekrarı at.
        extensions = extensions.filter(function (v, i, a) { return a.indexOf(v) === i; }).sort();

        $('#dropzone_hint').text(
            'veya tıklayarak seçin · ' + extensions.join(', ')
            + ' · en fazla ' + bytesToLabel(settings.max_bytes) + '/dosya'
            + ' · en fazla ' + settings.max_files + ' dosya'
        );
    }


    /* =================================================================
     *  BAŞLANGIÇ
     * ============================================================== */
    function init(options) {
        $.extend(config, options || {});

        $(function () {
            deleteModal   = new bootstrap.Modal(document.getElementById('deleteModal'));
            settingsModal = new bootstrap.Modal(document.getElementById('settingsModal'));
            previewModal  = new bootstrap.Modal(document.getElementById('previewModal'));

            // Saklanan tercihleri geri yükle. Tema zaten <head> içindeki
            // erken betikle uygulanmıştır; burada yalnızca düğmenin
            // simgesini doğru duruma getiriyoruz.
            var savedTheme = storeGet(STORE_THEME);

            if (savedTheme === 'dark' || savedTheme === 'light') {
                applyTheme(savedTheme);
            } else {
                $('#theme_icon').text(
                    window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
                        ? '☀️' : '🌙'
                );
            }

            applyView(storeGet(STORE_VIEW) || 'grid');

            // Modal kapanınca görseli bellekten düşür: büyük bir resim
            // arka planda boşuna yüklü kalmasın.
            $('#previewModal').on('hidden.bs.modal', function () {
                $('#preview_image').attr('src', '');
            });

            bindEvents();
            loadFiles();
        });
    }

    return { init: init };

})(jQuery);
