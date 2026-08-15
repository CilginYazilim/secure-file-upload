<div align="center">

<img src="assets/images/logo.png" alt="Çılgın Yazılım" width="90">

# Güvenli Dosya Yükleme Sistemi

PHP ile **katmanlı güvenlik** uygulanmış dosya yükleme örneği.
Sürükle-bırak · İlerleme çubuğu · Tür/tarih klasörleme · İndirme sayacı · Ayarlanabilir tür beyaz listesi

**[cilginyazilim.com](https://cilginyazilim.com)** · MIT Lisansı

🇹🇷 Türkçe · [🇬🇧 English](README.en.md)

<img src="assets/images/ekran-goruntusu.png" alt="Güvenli Dosya Yükleme arayüzü" width="820">

</div>

---

## İçindekiler

- [Bu proje ne yapıyor?](#bu-proje-ne-yapıyor)
- [Katmanlı güvenlik savunması](#katmanlı-güvenlik-savunması)
- [Ölçülmüş bulgular](#ölçülmüş-bulgular-ve-kapatılan-açıklar)
- [Dosya yapısı ve hangi dosya ne yapar](#dosya-yapısı-ve-hangi-dosya-ne-yapar)
- [Fonksiyon referansı](#fonksiyon-referansı)
- [Klasör yapısı](#diskteki-klasör-yapısı)
- [Ayarlar ekranı](#ayarlar-ekranı)
- [API uç noktaları](#api-uç-noktaları)
- [Veritabanı şeması](#veritabanı-şeması)
- [Kurulum](#kurulum)
- [Özelleştirme](#özelleştirme)
- [Örnek kullanım alanları](#örnek-kullanım-alanları)

---

## Bu proje ne yapıyor?

Genel amaçlı bir dosya yükleme sistemi: görseller (JPG/PNG/GIF/WEBP) ve belgeler (PDF/TXT/ZIP/DOCX/XLSX) yükler, tür ve tarihe göre klasörler, listeler, süzer, indirir, sayar ve siler.

Odak noktası **arayüz değil güvenliktir.** Dosya yükleme, bir web uygulamasının en tehlikeli özelliğidir: yanlış yapılırsa saldırgan sunucuya kod yükleyip çalıştırabilir (*remote code execution*). Bu depo, o riski katman katman nasıl kapatacağınızı **ölçülmüş kanıtlarla** gösterir.

> **Bu depo hem öğretici hem portfolyodur.** Koddaki her kararın gerekçesi, kodun içinde Türkçe yorum olarak yazılıdır. "Şunu yaptık" değil, **"neden böyle yaptık, alternatifi neden yetersizdi"** anlatılır.

---

## Katmanlı güvenlik savunması

Her katman, **diğerleri atlatılsa bile** tek başına anlamlı bir engel oluşturacak şekilde tasarlanmıştır (*defense in depth*). Aşağıdaki tabloda her katmanın **neden orada olduğu** yazılıdır.

| # | Katman | Nerede | Neden orada? |
|---|--------|--------|--------------|
| 1 | **İçerikten MIME tespiti** | `store_upload()` — `finfo` | İstemcinin gönderdiği `Content-Type` başlığı **saldırganın yazdığı bir metindir**, doğrulama değildir. `finfo` dosyanın ilk baytlarına ("magic bytes") bakar; bu, dosyanın içeriğini gerçekten değiştirmeden taklit edilemez. |
| 2 | **Katalog beyaz listesi** | `config.php` | Kara liste ("şunlar yasak") her zaman eksiktir — unutulan tek uzantı sistemi çökertir. Beyaz liste ("yalnızca şunlar serbest") varsayılanı *reddetmek* yapar. SVG bilerek dışarıdadır: içine `<script>` gömülebilen bir XML formatıdır. |
| 3 | **Uzantı MIME'den türetilir** | `store_upload()` | Kullanıcının dosya adı **hiçbir zaman** diske yazılan adı belirlemez. `fatura.pdf.php` gönderilse bile, içerik PDF ise diskte `.pdf` olur; `.php` diske asla ulaşmaz. Çift uzantı saldırısı bu satırda ölür. |
| 4 | **Rastgele dosya adı** | `random_bytes(16)` | Üç fayda: üzerine yazmayı önler, orijinal addaki bilgilerin URL'de sızmasını engeller, "başkasının dosyasının adresini tahmin etme"yi imkânsız kılar. |
| 5 | **Görselin gerçekten çözülmesi** | `imagecreatefromstring()` | `getimagesize()` dosyayı **çözmez**, yalnızca başlığını okur — sahte başlıklı bir dosyayı geçirir (bkz. [ölçüm](#2-görsel-doğrulama-baypası-getimagesize-yetersiz)). GD ile gerçek çözümleme, polyglot dosyaları eler. |
| 6 | **Piksel bombası sınırı** | `store_upload()` | 50 MP üzeri bir görsel çözülürken yüzlerce MB bellek ister. Çözmeden **önce** reddedilir; küçük bir dosya sunucuyu düşüremesin diye. |
| 7 | **`.htaccess` çalıştırma kilidi** | `uploads/.htaccess` | 1–6 katmanlarının **hepsi** atlatılıp bir `.php` diske yazılsa bile Apache o klasörde hiçbir betiği çalıştırmaz. Son çare savunması. Alt klasörlere de miras kalır (ölçüldü). |
| 8 | **`nosniff` + CSP** | `uploads/.htaccess` | Bazı tarayıcılar `Content-Type`'a rağmen içeriğe bakıp "bu aslında HTML" diyebilir (*MIME sniffing*) ve dosyayı **sitenin kendi alan adında** çalıştırır → depolanmış XSS. `nosniff` bunu yasaklar, CSP `sandbox` ikinci kemerdir. |
| 9 | **Zorunlu indirme başlıkları** | `download.php` | `application/octet-stream` + `attachment`: tarayıcı içeriği **asla** sayfa olarak render etmez, yalnızca indirir. |
| 10 | **Başlık enjeksiyonu temizliği** | `download_filename_header()` | Dosya adı bir HTTP başlığının içine giriyorsa **kullanıcı girdisi başlığa yazılıyor** demektir. Tırnak/noktalı virgül temizlenmezse saldırgan başlıktan kaçabilir (bkz. [ölçüm](#1-content-disposition-başlık-enjeksiyonu-uzaktan-erişilebilir)). |
| 11 | **CSRF anahtarı** | `require_csrf()` | Durum değiştiren her istek (yükleme, silme, ayar) oturuma bağlı bir anahtar ister; başka bir sitenin sizin adınıza istek göndermesini engeller. |
| 12 | **Ayar ≠ güvenlik sınırı** | `allowed_upload_types()` | Ayarlar veritabanındadır ve veritabanı değişebilir. Etkin liste her zaman `katalog ∩ ayarlar` olarak hesaplanır — ayarlara `.php` yazılsa bile etkinleşmez. |

---

## Ölçülmüş bulgular ve kapatılan açıklar

Bu bölümdeki her madde **tahmin değil, ölçümdür**: gerçek HTTP istekleriyle denendi, sonucu kaydedildi, düzeltildi, tekrar denendi.

### 1) `Content-Disposition` başlık enjeksiyonu (uzaktan erişilebilir)

**Bulgu.** `download.php`, veritabanındaki `original_name` değerini doğrudan başlığa yazıyordu. `basename()` yol bilgisini atar ama **tırnak işaretini temizlemez**.

PHP'nin çok parçalı (*multipart*) yükleme çözümleyicisi normalde tırnağı geçirmez — ancak ters bölü ile kaçırılırsa geçirir:

```http
Content-Disposition: form-data; name="files[]"; filename="a\"; filename=kurulum.exe.png"
```

Bu istek sıradan bir yükleme olarak kabul edildi ve veritabanına şu ad yazıldı:

```
a"; filename=kurulum.exe.png
```

Sunucunun ürettiği indirme başlığı (ölçüldü):

```http
Content-Disposition: attachment; filename="a"; filename=kurulum.exe.png"
                                            ↑ başlıktan kaçış, İKİNCİ filename parametresi
```

Yani **kimlik doğrulaması olmayan bir yükleyici**, indirme başlığına ikinci bir `filename` parametresi enjekte edebiliyordu. Bazı istemciler sonuncuyu dikkate alır; kullanıcı beklemediği bir adla (örn. `.exe`) dosya kaydedebilir.

**Düzeltme.** `download_filename_header()` iki temsil üretir: temizlenmiş ASCII (`" \ ;` ve kontrol karakterleri `_` olur) + RFC 5987 yüzde kodlu UTF-8.

**Düzeltme sonrası ölçüm:**

```http
Content-Disposition: attachment; filename="a__ filename=kurulum.exe.png"; filename*=UTF-8''a%22%3B%20filename%3Dkurulum.exe.png
                                          ↑ tek parametre, kaçış yok
```

### 2) Görsel doğrulama baypası (`getimagesize()` yetersiz)

**Bulgu.** 5. katman `getimagesize()` kullanıyordu. Bu fonksiyon dosyayı **çözmez**, yalnızca başlığını okur.

İçeriği `GIF89a<?php system($_GET["c"]); ?>` olan bir dosya gönderildiğinde:

| Denetim | Sonuç |
|---|---|
| `finfo` | `image/gif` (ilk 6 bayt GIF imzası) |
| `getimagesize()` | **GEÇTİ** — üstelik `16188x26736` gibi uydurma bir boyut döndürdü (PHP kodunun baytlarını genişlik/yükseklik sandı) |
| Sonuç | Dosya **diske yazıldı** |

Daha sinsi bir sürümde (geçerli 100×100 başlık + PHP kodu) sonuç aynıydı: `getimagesize()` → `GEÇTİ (100x100)`.

**Düzeltme.** `imagecreatefromstring()` ile **gerçek çözümleme** eklendi + 50 MP piksel sınırı.

**Düzeltme sonrası ölçüm** (aynı 100×100 polyglot):

```
getimagesize          -> GECTI (100x100)     ← eski katman hâlâ kanardı
imagecreatefromstring -> RED                 ← yeni katman yakaladı
sunucu yaniti         -> {"success":false,"description":"... Dosya geçerli bir görsel değil (içerik çözümlenemedi)."}
```

Meşru bir PNG aynı testte sorunsuz yüklendi — düzeltme normal kullanımı bozmadı.

### 3) Yükleme klasöründe güvenlik başlığı yokluğu

**Bulgu.** `download.php` yanıtlarında `nosniff` vardı, ama küçük resim önizlemelerinin kullandığı **doğrudan erişim** yolunda hiçbir güvenlik başlığı yoktu:

```http
GET /uploads/....gif
Content-Type: image/gif        ← başka başlık YOK
```

Bir GIF/PNG içine HTML gömülüp tarayıcı içerik tahmini yaparsa, betik **sitenin kendi alan adında** çalışır (depolanmış XSS).

**Düzeltme + ölçüm:**

```http
X-Content-Type-Options: nosniff
Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox
X-Frame-Options: DENY
```

### 4) CSRF reddi HTTP 500 döndürüyordu

**Bulgu.** `require_csrf()` başarısızlıkta `419` döndürüyordu. `419` resmî bir HTTP durum kodu değildir (Laravel'in icadı) ve **bu kurulumda Apache onu sessizce `500`'e çeviriyordu** — istemci "sunucu çöktü" sanıyordu.

```
bozuk token -> HTTP 500      (düzeltme öncesi)
bozuk token -> HTTP 403      (düzeltme sonrası)
```

### 5) `.htaccess` kilidi — doğrulandı, açık bulunamadı

Bu katman **atlatılamadı**. Denenen ve hepsi `403` dönen yollar:

```
uploads/zz.php            uploads/zz.php/         uploads/./zz.php
uploads//zz.php           uploads/ZZ.PHP          uploads/zz.php.
uploads/zz.phtml          uploads/zz.php%00.png   (404)
uploads/image/2026/08/zz.php   ← alt klasörlerde de geçerli
```

Dizin listeleme de her seviyede kapalı (`403`).

### 6) Dizin aşımı (path traversal) — savunma doğrulandı

`safe_upload_path()` şu girdilerin **tamamını** reddetti; meşru yolu kabul etti:

```
../../system/config.php                      -> REDDEDILDI
image/2026/08/../../../../system/config.php  -> REDDEDILDI
image/2026/08/../../../.htaccess             -> REDDEDILDI
..\..\system\config.php                      -> REDDEDILDI
image/2026/08/%2e%2e%2fconfig.php            -> REDDEDILDI
document/2026/08/AAAA.php                    -> REDDEDILDI
image/2026/08/deada12d...474.png             -> kabul  ← meşru
```

### 7) SQL enjeksiyonu — savunma doğrulandı

Sıralama parametresine `id; DROP TABLE files; --` gönderildi. Sıralama sütunu parametre olamayacağı için **beyaz listeden** seçilir; girdi eşleşmediğinden varsayılana düştü, tablo yerinde kaldı.

### 8) Ayarlar güvenlik sınırı — doğrulandı

Ayar kaydetme isteğine `application/x-php` ve `application/x-httpd-php` eklendi, boyut `500 MB`, dosya sayısı `9999` istendi:

```
ETKIN turler: image/png                    ← .php türleri sessizce elendi
max_bytes=8388608  max_files=10            ← tavana kelepçelendi
```

---

## Dosya yapısı ve hangi dosya ne yapar

```
secure-file-upload/
├── index.php                  ← Arayüz: sürükle-bırak, süzgeçler, özet, ayarlar penceresi
├── cy_upload.sql              ← Veritabanı kurulumu (files + settings tabloları)
│
├── system/
│   ├── config.php             ← Ayarlar, TÜR KATALOĞU, sınır tavanları, PDO bağlantısı
│   ├── function.php           ← Çekirdek: doğrulama, kaydetme, ayarlar, yol güvenliği
│   ├── ajax.php               ← JSON uç noktası (list / upload / delete / settings)
│   └── download.php           ← İndirme uç noktası (GET, zorunlu attachment, sayaç)
│
├── assets/
│   ├── css/cilginyazilim.css  ← ORTAK MARKA KALIBI — dokunulmaz
│   ├── css/style.css          ← Yalnızca bu sayfaya özel stiller
│   ├── js/upload.js           ← Sürükle-bırak, ilerleme, süzgeç, ayar arayüzü
│   └── images/                ← Logo + ekran görüntüleri
│
├── ornek-dosyalar/            ← Denemek için hazır örnekler (PNG/JPG/WEBP/GIF/PDF/TXT/ZIP)
│
└── uploads/                   ← Yüklenen dosyalar (tür/yıl/ay ağacı)
    └── .htaccess              ← Çalıştırma kilidi + güvenlik başlıkları
```

### Sorumluluk ayrımı

| Dosya | Sorumluluğu | Sorumluluğu **olmayan** |
|---|---|---|
| `index.php` | Yalnızca HTML çizer | Hiçbir güvenlik kararı vermez |
| `upload.js` | Yalnızca kullanıcı deneyimi | **Hiçbir kontrolü güvenlik önlemi değildir** — JS atlanabilir |
| `config.php` | Katalog + tavanlar | İş mantığı içermez |
| `function.php` | Tüm güvenlik kararları | Çıktı üretmez (JSON hariç yardımcılar) |
| `ajax.php` | İstek yönlendirme + yetki | Doğrulama mantığı içermez, `function.php`'ye devreder |

> **Altın kural:** `upload.js` içindeki tür ve boyut kontrolleri **güvenlik değildir.** Kötü niyetli biri JavaScript'i hiç çalıştırmadan doğrudan `system/ajax.php`'ye istek gönderebilir. Gerçek doğrulama her zaman sunucudadır.

---

## Fonksiyon referansı

### `system/function.php`

| Fonksiyon | Ne yapar |
|---|---|
| `e()` | HTML kaçışı (`htmlspecialchars`) — XSS'e karşı çıktı temizliği |
| `json_response()` / `json_success()` / `json_error()` | Tek biçimli JSON yanıtı üretir; her yanıta `nosniff` ekler |
| `csrf_token()` | Oturuma bağlı 32 baytlık anahtar üretir/döndürür |
| `require_csrf()` | Anahtarı `hash_equals()` ile sabit sürede doğrular; başarısızsa **403** |
| `settings_all()` | Ayarları okur, istek boyunca önbelleğe alır (10 dosyalık yüklemede 10 sorgu olmasın) |
| `setting_int()` | Sayısal ayarı `min(ayar, tavan)` ile kelepçeler |
| `allowed_upload_types()` | **Etkin türler = katalog ∩ ayarlar** — güvenlik sınırının kalbi |
| `settings_save()` | Ayarları yazar; katalog dışı türleri **yazmadan önce** eler |
| `store_upload()` | **Çekirdek.** Doğrulama → klasörleme → rastgele ad → taşıma → kayıt |
| `download_filename_header()` | Dosya adını başlığa güvenle gömer (ASCII + RFC 5987) |
| `safe_upload_path()` | Göreli yolu doğrular (kalıp + `realpath` sınır denetimi) |
| `delete_stored_file()` | Dosyayı siler, boş kalan tür/yıl/ay klasörlerini toplar |
| `increment_download_count()` | Sayacı **tek SQL sorgusuyla** artırır (yarış durumuna kapalı) |
| `find_file()` / `fetch_files()` / `fetch_file_stats()` | Veri erişimi; süzgeçler parametreli, sıralama beyaz listeli |
| `format_bytes()` / `format_date()` / `file_icon()` | Biçimlendirme (güvenlik kararı vermez) |

---

## Diskteki klasör yapısı

Dosyalar düz bir klasöre değil, **tür + tarih ağacına** yazılır:

```
uploads/
├── .htaccess
├── image/
│   └── 2026/
│       └── 08/
│           ├── deada12df99150cb71bc731822d9b474.png
│           └── 2516fdb120e4253593217a923eac0580.jpg
└── document/
    └── 2026/
        └── 08/
            ├── f4384d8cf06ccaa65b0e70da95f75036.pdf
            └── 372c1b698c106b7e94500fb620d138f2.zip
```

**Neden?**

1. **Performans** — Tek klasörde on binlerce dosya biriktiğinde dosya sistemi dizin taramasında yavaşlar.
2. **Yönetilebilirlik** — "2025'in tamamını arşivle" tek komuta iner.
3. **Yedekleme** — Aylık artımlı yedek almak kolaylaşır.

**Güvenlik notu:** Bu yolun hiçbir parçası kullanıcı girdisinden gelmez — kategori katalogdan, yıl/ay sunucu saatinden, dosya adı `random_bytes()`'tan. `uploads/.htaccess` alt klasörlere de miras kalır (ölçüldü); her derinlikte `.php` isteği `403` döner.

Bir dosya silindiğinde boş kalan `08/`, `2026/` klasörleri otomatik toplanır.

---

## Ayarlar ekranı

<div align="center">
<img src="assets/images/ekran-ayarlar.png" alt="Ayarlar ekranı" width="760">
</div>

Yönetici, arayüzden **izin verilen dosya türlerini** ve **boyut/sayı sınırlarını** koda dokunmadan değiştirebilir.

### Güvenlik sınırı — bu ekranın yapamadıkları

Ayarlar veritabanında durur ve bir veritabanı, koddan farklı olarak, yanlış bir yedek geri yüklemesiyle veya başka bir açıkla değişebilir. Bu yüzden **ayarlar hiçbir zaman güvenliği gevşetemez**:

```
etkin türler = SUPPORTED_UPLOAD_TYPES (config.php)  ∩  settings tablosu
```

| Deneme | Sonuç |
|---|---|
| Ayarlara `application/x-php` eklemek | Sessizce elenir — katalogda yok |
| Boyut sınırını `500 MB` yapmak | `8 MB`'a kelepçelenir (`config.php` tavanı) |
| Dosya sayısını `9999` yapmak | `10`'a kelepçelenir |
| Bir türü kapatmak | Gerçekten reddedilir (ölçüldü) |

Yani tehlikeli bir türü etkinleştirmenin **tek yolu** `system/config.php` dosyasını düzenlemektir — bu da sunucuya dosya yazma yetkisi gerektirir.

---

## API uç noktaları

Tümü `system/ajax.php` üzerinden **POST** ile çalışır ve **CSRF anahtarı zorunludur** (`csrf_token` alanı veya `X-CSRF-Token` başlığı).

### `action=list` — Dosyaları listele

| Parametre | Değer | Açıklama |
|---|---|---|
| `category` | `image` \| `document` \| boş | Tür süzgeci |
| `period` | `YYYY-AA` | Ay süzgeci |
| `sort` | `newest` \| `oldest` \| `largest` \| `popular` \| `name` | Sıralama (beyaz liste) |

```json
{
  "success": true,
  "total": 7,
  "files": [
    {
      "id": 1, "original_name": "ornek-gorsel-1.png",
      "extension": "png", "category": "image",
      "size": "8,7 KB", "uploaded_at": "15.08.2026 15:16",
      "downloads": 3, "folder": "image/2026/08",
      "download_url": "system/download.php?id=1",
      "thumb_url": "uploads/image/2026/08/deada12d....png"
    }
  ],
  "stats": {
    "by_category": [{"category": "image", "total": 4}],
    "by_period":   [{"period": "2026-08", "total": 7}],
    "totals":      {"files": 7, "bytes": 111923, "downloads": 3}
  }
}
```

### `action=upload` — Dosya yükle

`multipart/form-data` ile `files[]` alanı (çoklu). Her dosya **bağımsız** doğrulanır: biri reddedilse bile diğerleri kaydedilir.

```json
{ "success": true, "description": "7 dosya başarıyla yüklendi.", "uploaded": 7, "failed": 0 }
```

### `action=delete` — Dosya sil

| Parametre | Değer |
|---|---|
| `id` | Dosya numarası (pozitif tamsayı) |

### `action=settings` — Ayarları oku / kaydet

| Parametre | Değer |
|---|---|
| `mode` | `read` (varsayılan) \| `save` |
| `allowed_mimes[]` | Etkinleştirilecek MIME türleri |
| `max_bytes` | Dosya başına bayt sınırı |
| `max_files` | Tek istekte dosya sayısı |

### `system/download.php?id=N` — İndirme (GET)

Ayrı bir uç noktadır çünkü tarayıcının kendi indirme akışını kullanır (`<a href>`, "Farklı Kaydet", yeni sekme). **GET olması güvenli**, çünkü hiçbir veri değiştirmez — yalnızca indirme sayacını artırır.

### Durum kodları

| Kod | Anlamı |
|---|---|
| `200` | Başarılı |
| `403` | CSRF doğrulaması başarısız *(419 değil — Apache 419'u 500'e çeviriyor)* |
| `404` | Dosya bulunamadı |
| `405` | POST dışı yöntem |
| `422` | Doğrulama hatası (tür/boyut/sayı) |
| `500` | Beklenmeyen sunucu hatası |

---

## Veritabanı şeması

Veritabanı adı **`cy_upload`**, kurulum dosyası **`cy_upload.sql`**.

> **Adlandırma kuralı:** Çılgın Yazılım projelerinde veritabanları `cy_` önekiyle adlandırılır ve kurulum dosyası veritabanıyla **aynı adı taşır**. Bir sunucuda onlarca `.sql` arasında hangisinin nereye ait olduğu tek bakışta anlaşılsın diye.

### `files`

| Sütun | Tür | Açıklama |
|---|---|---|
| `id` | `INT UNSIGNED` | Birincil anahtar |
| `original_name` | `VARCHAR(255)` | Kullanıcının adı — **yalnızca gösterim**, dosya işlemine asla girmez |
| `stored_path` | `VARCHAR(255)` | Göreli yol: `kategori/yıl/ay/rastgele.uzantı` (benzersiz) |
| `mime` | `VARCHAR(127)` | **Sunucunun içerikten tespit ettiği** tür (istemci başlığı değil) |
| `extension` | `VARCHAR(10)` | MIME'den türetilen güvenli uzantı |
| `size_bytes` | `INT UNSIGNED` | Boyut |
| `category` | `ENUM('image','document')` | Klasörleme ve önizleme kararı |
| `download_count` | `INT UNSIGNED` | İndirme sayacı |
| `last_downloaded_at` | `TIMESTAMP NULL` | Son indirme zamanı |
| `uploaded_at` | `TIMESTAMP` | Yükleme zamanı |

### `settings`

| Sütun | Tür | Açıklama |
|---|---|---|
| `name` | `VARCHAR(64)` | Ayar adı (birincil anahtar) |
| `value` | `TEXT` | JSON değer — tek tablo hem liste hem sayı taşısın diye |
| `updated_at` | `TIMESTAMP` | Otomatik güncellenir |

---

## Kurulum

**Gereksinimler:** PHP 8.1+ (`fileinfo`, `pdo_mysql`, `gd`), MySQL/MariaDB, Apache (`mod_headers`, `AllowOverride All`).

```bash
cd C:/xampp/htdocs
git clone https://github.com/CilginYazilim/secure-file-upload.git

mysql -u root -p < secure-file-upload/cy_upload.sql
```

`system/config.php` içindeki `DB_*` satırlarını düzenleyin veya ortam değişkeni tanımlayın (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).

Ardından: **http://localhost/secure-file-upload/**

`ornek-dosyalar/` klasöründeki hazır dosyaları sürükle-bırak alanına bırakarak sistemi hemen deneyebilirsiniz.

### Canlıya alırken

1. `APP_DEBUG` → `false` (hata ayrıntıları kullanıcıya gösterilmesin)
2. `mod_headers` etkin olmalı — yoksa 8. katman (nosniff/CSP) sessizce devre dışı kalır
3. Sunucunuz `.htaccess` okumuyorsa (Nginx) eşdeğer kuralları sunucu yapılandırmasına taşıyın
4. Bu demoda indirme **kimlik doğrulamasızdır**; gerçek projede yetki kontrolü ekleyin

---

## Özelleştirme

### Yeni bir dosya türü eklemek

`system/config.php` içindeki `SUPPORTED_UPLOAD_TYPES` kataloğuna bir satır ekleyin:

```php
'audio/mpeg' => ['ext' => 'mp3', 'category' => 'document', 'label' => 'MP3 ses'],
```

Arayüzdeki ipucu metni, `accept` özniteliği, ayarlar ekranı ve sunucu doğrulaması **bu tek tanımdan** beslenir.

> **Dikkat:** `category => 'image'` yalnızca GD'nin çözebildiği formatlar için kullanılın; aksi hâlde 5. katman geçerli dosyaları reddeder.

### Sınırları değiştirmek

`config.php` içindeki `UPLOAD_MAX_BYTES` / `UPLOAD_MAX_FILES` **tavandır**. Ayarlar ekranından bu tavanın altında herhangi bir değer seçilebilir. Tavanı yükseltirseniz `php.ini` içindeki `upload_max_filesize` ve `post_max_size` değerlerini de yükseltin.

### Klasör düzenini değiştirmek

`store_upload()` içindeki `$relativeDir` satırı klasör düzenini belirler:

```php
$relativeDir = $category . '/' . date('Y') . '/' . date('m');   // image/2026/08
$relativeDir = date('Y/m/d');                                    // 2026/08/15
$relativeDir = $category;                                        // yalnızca tür
```

Değiştirirseniz `safe_upload_path()` içindeki kalıbı da güncelleyin — yoksa yeni yollar reddedilir.

### Görünümü değiştirmek

Sayfaya özel stiller `assets/css/style.css` içindedir. `assets/css/cilginyazilim.css` **ortak marka kalıbıdır, değiştirmeyin** — tüm Çılgın Yazılım projeleri onu paylaşır.

---

## Örnek kullanım alanları

Bu kod, "dosya kabul eden" hemen her işin başlangıç noktası olabilir:

| Alan | Nasıl kullanılır |
|---|---|
| **Kurumsal destek/talep sistemi** | Müşteri ekran görüntüsü ve fatura eki yükler. Tür beyaz listesi, gelen ekin gerçekten görsel/PDF olmasını garanti eder. |
| **İnsan kaynakları — CV toplama** | Yalnızca PDF/DOCX açılır; `.exe`/`.php` başvuru dosyası olarak gelemez. Tarih klasörleme, dönemsel arşivi kendiliğinden oluşturur. |
| **E-ticaret ürün görselleri** | Satıcı panelinden görsel yükleme. GD çözümleme katmanı, "görsel gibi görünen" zararlı dosyaları eler. |
| **Muhasebe / e-fatura arşivi** | XLSX/PDF kabul edilir, ay bazlı klasörlenir; yıl sonu arşivi tek klasör kopyalamaya iner. |
| **Okul / kurs ödev teslimi** | Öğrenci ödev yükler; indirme sayacı, dosyanın kaç kez alındığını gösterir. |
| **Ajans müşteri portalı** | Müşteri marka varlıklarını yükler, ekip indirir; en çok indirilen dosyalar sıralamayla görünür. |
| **İç dokümantasyon deposu** | Küçük ekipler için hafif bir dosya paylaşımı; ayarlardan yalnızca PDF açılarak "belge arşivi" moduna alınabilir. |
| **Eğitim materyali** | Güvenli dosya yükleme dersi: her katmanın **neden** var olduğu ve atlatıldığında ne olduğu kod içinde yazılıdır. |

### Bu kodu kullanırken eklemeniz gerekenler

Bu bir **demo**dur; gerçek projede ayrıca şunlar gerekir:

- **Yetkilendirme** — Şu an dosya numarasını bilen herkes indirebilir. `download.php` içine oturum/sahiplik kontrolü ekleyin.
- **Hız sınırı** — Aynı IP'den saniyede onlarca yükleme engellenmiyor.
- **Virüs taraması** — ClamAV gibi bir tarayıcı, MIME doğrulamanın yakalayamadığı zararlı içerikleri yakalar.
- **Depolama kotası** — Kullanıcı başına toplam boyut sınırı.

---

## Lisans

MIT — dilediğiniz gibi indirip kullanabilirsiniz.

Telif © **Çılgın Yazılım** ([cilginyazilim.com](https://cilginyazilim.com))

[github.com/CilginYazilim/secure-file-upload](https://github.com/CilginYazilim/secure-file-upload)
