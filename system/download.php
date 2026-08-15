<?php
/**
 * =====================================================================
 *  İNDİRME UÇ NOKTASI
 *  cilginyazilim.com – Güvenli Dosya Yükleme Sistemi
 * ---------------------------------------------------------------------
 *  Neden GET ile çalışır (diğer projelerdeki export.php POST idi)?
 *  Burada dışa aktarılan "tüm kayıtlar" değil, KULLANICININ TIKLADIĞI
 *  TEK BİR dosyadır; bir <a href> etiketiyle açılması en doğal ve
 *  erişilebilir yoldur (yeni sekmede açma, sağ tık > Farklı Kaydet
 *  gibi tarayıcı özellikleri GET olmadan çalışmaz). CSRF riski
 *  yoktur çünkü bu istek hiçbir veri DEĞİŞTİRMEZ, yalnızca okur.
 *
 *  NEDEN ContentType HER ZAMAN "application/octet-stream"?
 *  Dosyanın GERÇEK MIME türünü göndermek (örn. text/html) tarayıcının
 *  içeriği SAYFA İÇİNDE ÇALIŞTIRMASINA yol açabilir. Kullanıcı
 *  kötü niyetle ".txt" uzantılı ama içi HTML/JS olan bir dosya
 *  yükleseydi (whitelist bunu zaten MIME'den engeller, ama savunmayı
 *  ÇOK KATMANLI tutuyoruz), "application/octet-stream" + "attachment"
 *  ikilisi tarayıcıyı HER ZAMAN indirmeye zorlar, asla render etmez.
 * =====================================================================
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/function.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($id === false || $id === null) {
    http_response_code(400);
    exit('Geçersiz dosya numarası.');
}

$file = find_file($db, $id);

if ($file === null) {
    http_response_code(404);
    exit('Dosya bulunamadı.');
}

// safe_upload_path(): göreli yolu doğrular ve mutlak yola çevirir.
// Yükleme klasörünün dışına çıkan hiçbir yol buradan geçemez
// (ayrıntılı gerekçe: function.php).
$path = safe_upload_path($file['stored_path']);

if ($path === null) {
    http_response_code(404);
    exit('Dosya diskte bulunamadı.');
}

// İNDİRME SAYACI: dosya gerçekten gönderilmeden hemen önce artırılır.
// Yukarıdaki tüm doğrulamalar geçilmiş olduğu için burada sayılan her
// artış GERÇEK bir indirmeye karşılık gelir; 404'ler sayaca yazılmaz.
increment_download_count($db, (int) $file['id']);

while (ob_get_level() > 0) {
    ob_end_clean();
}

/* ---------------------------------------------------------------------
 *  DOSYA ADINI BAŞLIĞA GÜVENLE YERLEŞTİRME
 * ---------------------------------------------------------------------
 *  BURASI ÖLÇÜLMÜŞ BİR AÇIĞIN KAPATILDIĞI YERDİR — basename() TEK
 *  BAŞINA YETMEZ. basename() yalnızca YOL bilgisini atar; tırnak
 *  işaretini TEMİZLEMEZ.
 *
 *  Saldırı (gerçekten denendi ve çalıştı): çok parçalı (multipart)
 *  gövdede dosya adı ters bölü ile kaçırılarak gönderilirse
 *      filename="a\"; filename=kurulum.exe.png"
 *  PHP'nin yükleme çözümleyicisi bunu
 *      a"; filename=kurulum.exe.png
 *  olarak okur ve original_name'e YAZAR. Eski kod bu değeri doğrudan
 *  başlığa koyduğu için sunucu şu yanıtı üretiyordu:
 *      Content-Disposition: attachment; filename="a"; filename=kurulum.exe.png"
 *  Yani saldırgan başlığa İKİNCİ bir filename parametresi enjekte
 *  ediyordu; bazı istemciler sonuncuyu dikkate alır ve dosya
 *  kullanıcının beklemediği bir adla (örn. .exe) kaydedilir.
 *
 *  Çözüm iki parçalıdır:
 *    1) filename="..."  → yalnızca ASCII, tırnak/ters bölü/kontrol
 *       karakterleri temizlenmiş GÜVENLİ bir ad (eski istemciler için)
 *    2) filename*=UTF-8''… → RFC 5987/6266 biçimi; Türkçe karakterleri
 *       DOĞRU taşır. Modern tarayıcılar varsa bunu tercih eder.
 *
 *  NEDEN İKİSİ BİRDEN? HTTP başlıkları tarihsel olarak ISO-8859-1
 *  kabul edilir; "Şirket Faturası.png" gibi bir adın ham UTF-8
 *  baytları düz filename="..." içinde bozuk görünür (Åirket…).
 *  filename* bunu çözer, düz filename ise geriye dönük uyumluluk
 *  için kalır.
 * ------------------------------------------------------------------ */
$downloadName = download_filename_header(basename((string) $file['original_name']));

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; ' . $downloadName);
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

readfile($path);
exit;
