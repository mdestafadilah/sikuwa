# WhatsApp Unofficial SDK (SIKUWA)

SDK PHP untuk beberapa gateway WhatsApp unofficial. Tujuannya satu antarmuka yang sama untuk banyak gateway,
sehingga penggantian provider atau otomatis pilih provider tidak mengubah kode pemanggil.

[![Rilis stabil](https://img.shields.io/packagist/v/mdestafadilah/sikuwa.svg?style=flat-square)](https://packagist.org/packages/mdestafadilah/sikuwa)
[![Total unduhan](https://img.shields.io/packagist/dt/mdestafadilah/sikuwa.svg?style=flat-square)](https://packagist.org/packages/mdestafadilah/sikuwa)
[![PHP](https://img.shields.io/packagist/php-v/mdestafadilah/sikuwa.svg?style=flat-square)](https://packagist.org/packages/mdestafadilah/sikuwa)
[![Lisensi](https://img.shields.io/packagist/l/mdestafadilah/sikuwa.svg?style=flat-square)](LICENSE)

Namespace `Sikuwa\Whatsapp\`, PSR-4, dibangun di atas [Guzzle](https://docs.guzzlephp.org/) 7,
membutuhkan PHP 8.1+.

## Gateway yang didukung

| Gateway | Jenis | Basis | Tautan |
| --- | --- | --- | --- |
| Fonnte | Berbayar (cloud) | — | <https://fonnte.com/> |
| OpenWA | Self-hosted | Node.js | <https://github.com/rmyndharis/OpenWA> |
| ApiMe | Self-hosted | Go / WhatsMeow | <https://github.com/open-apime/apime> |
| Evolution API | Self-hosted | Node.js / Baileys | <https://github.com/evolution-foundation/evolution-api> |
| Wuzapi | Self-hosted | Go / WhatsMeow | <https://github.com/asternic/wuzapi> |
| Wwebjs | Self-hosted | Node.js / whatsapp-web.js | <https://github.com/avoylenko/wwebjs-api> |

## Instalasi

```bash
composer require mdestafadilah/sikuwa
```

Butuh PHP 8.1+ dan `ext-mbstring`; Guzzle 7 ikut terpasang sebagai dependensi.

Untuk mengikuti `main` alih-alih rilis stabil:

```bash
composer require mdestafadilah/sikuwa:dev-main
```

Kalau butuh fork sendiri atau commit tertentu, daftarkan repositori GitHub-nya:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/mdestafadilah/sikuwa" }
    ],
    "require": {
        "mdestafadilah/sikuwa": "dev-main"
    }
}
```

## Pemakaian

```php
<?php

require 'vendor/autoload.php';

use Sikuwa\Whatsapp\Client;

$client = new Client([
    'provider' => 'OpenWA',
    'token'    => 'owa_k1_…',
    'url'      => 'http://localhost:2785',
    'session'  => 'my-session',
]);

echo $client->send([
    'destination' => '081234567890',
    'message'     => 'Halo dari SIKUWA',
]);
// Sukses, messageId: 3EB0…
```

Opsi yang dikosongkan akan dicari di environment (`WHATSAPP_*`), jadi di aplikasi
CodeIgniter cukup `new Client()` tanpa argumen apa pun:

```php
$provider = $client->provider();      // instance gateway yang terpilih
$result   = $provider->sendMessage($message);

log_message(
    str_starts_with($result, 'Sukses') ? 'info' : 'error',
    "Notifikasi {$provider->getProvider()}: {$result}"
);
```

### Bentuk pesan

Satu bentuk yang sama untuk semua gateway:

```php
['destination' => '081234567890', 'message' => 'Halo', 'delay' => 2]
```

Untuk pengiriman massal, kirim list dari array seperti itu:

```php
$client->send([
    ['destination' => '0811111111', 'message' => 'Pesan pertama'],
    ['destination' => '0822222222', 'message' => 'Pesan kedua', 'delay' => 5],
]);
```

Nomor boleh ditulis dalam format apa pun yang lazim di Indonesia
(`0812…`, `+62 812…`, `62812…`) — SDK menormalkannya sendiri. JID grup
(`…@g.us`) dan WID (`…@c.us`) diteruskan apa adanya.

`delay` dihitung dalam **detik** dan opsional: jeda sebelum pesan dikirim
(Fonnte, Evolution API) atau jeda antar pesan pada pengiriman berurutan. Bila
tidak diisi, nilainya diambil dari [pacing](#jeda-antar-pesan-pacing); kalau
pacing pun mati, tiap gateway memakai bawaannya sendiri — Fonnte 2 detik, OpenWA
3 detik, sisanya tanpa jeda.

### Kirim gambar & berkas

Dua method terpisah, satu bentuk pesan yang sama untuk semua gateway:

```php
$client->sendImage([
    'destination' => '081234567890',
    'image'       => 'data:image/png;base64,iVBORw0KGgo…',
    'caption'     => 'Bukti transfer',
]);

$client->sendFile([
    'destination' => '081234567890',
    'file'        => $base64Pdf,
    'filename'    => 'invoice-1209.pdf',
    'caption'     => 'Invoice bulan ini',
]);
```

| Kunci | Wajib | Keterangan |
| --- | --- | --- |
| `destination` | ya | Sama seperti `sendMessage()` |
| `image` / `file` | ya | Isi berkasnya. `media` diterima sebagai alias untuk keduanya |
| `filename` | tidak | Nama yang dilihat penerima. Kosong = disusun dari jenis berkasnya (`lampiran.png`) |
| `caption` | tidak | Teks yang menyertai berkas |

Isi `image`/`file` boleh salah satu dari tiga bentuk; SDK yang menyesuaikannya ke
bentuk yang diminta tiap gateway:

- **data URI** (`data:image/png;base64,…`) — paling aman, karena jenis berkasnya
  ikut terbawa;
- **base64 telanjang** — jenisnya lalu ditebak dari `filename`, dan menjadi
  `application/octet-stream` bila ekstensinya tidak dikenali;
- **URL publik** (`https://…`) — hanya bila gateway boleh mengunduhnya sendiri.

Yang menentukan sebuah berkas dikirim sebagai gambar atau dokumen adalah **jenis
berkasnya**, bukan method yang dipanggil: `sendFile()` dengan PNG tetap terkirim
sebagai gambar, dan `sendImage()` dengan PDF tetap terkirim sebagai dokumen.

Tiap gateway menempuh jalur yang berbeda, dan itu memang sifat gateway-nya:

| Gateway | Gambar | Berkas | Bentuk isi |
| --- | --- | --- | --- |
| Fonnte | `POST /send` | `POST /send` | multipart, byte mentah — atau kolom `url` bila sumbernya URL |
| OpenWA | `POST /api/sessions/{id}/messages/send-image` | `…/send-document` | JSON, base64 telanjang + `mimetype` terpisah |
| ApiMe | `POST /api/instances/{id}/messages/media` | `…/messages/document` | multipart, byte mentah |
| Evolution API | `POST /message/sendMedia/{instance}` | sama, `mediatype=document` | JSON, base64 telanjang atau URL |
| Wuzapi | `POST /chat/send/image` | `POST /chat/send/document` | JSON, data URI (wajib) |
| Wwebjs | `POST /client/sendMessage/{id}` | sama, `contentType=MessageMedia` | JSON, base64 telanjang — atau `MessageMediaFromURL` bila sumbernya URL |

Lima hal yang mudah menjebak, dan sudah ditangani SDK:

- **ApiMe dan Wuzapi hanya menerima isi berkasnya**, bukan URL — keduanya tidak
  mengunduh apa pun sendiri. Menyerahkan URL ke sana melempar
  `ConfigurationException` yang menyuruh mengunduh berkasnya lebih dulu.
- **Evolution API menolak data URI.** Pemeriksaannya memakai `isBase64()` yang
  tidak mengenali awalan `data:…;base64,`, jadi SDK mengirim base64 telanjang.
  Untuk dokumen, `fileName` wajib ada — tanpa itu Evolution membalas HTTP 400.
- **Wuzapi meminta dokumen sebagai `octet-stream`**, apa pun jenis berkas
  aslinya. Itu bentuk yang diminta dokumentasinya, jadi jenis berkasnya tidak
  diteruskan ke sana.
- **Fonnte baru bisa mengirim media pada paket berbayar**
  (super/advanced/ultra). Penolakannya datang sebagai `reason` biasa, jadi
  pesannya muncul apa adanya di log.
- **Wwebjs memisahkan caption dari berkasnya.** Caption tidak ikut masuk ke
  objek berkas: ia dititipkan di `options.caption`, sedangkan isinya di
  `content`. SDK yang menyusun keduanya, jadi satu berkas berteks pengantar
  tetap satu request.

### Indikator "sedang mengetik"

Balasan yang muncul seketika mudah dikenali sebagai robot. `sendTyping()` membuat
WhatsApp menampilkan "sedang mengetik…" lebih dulu:

```php
$client->sendTyping(['destination' => '081234567890', 'duration' => 3]);

$client->send(['destination' => '081234567890', 'message' => 'Halo']);

// Hapus indikatornya bila pesannya tidak jadi dikirim.
$client->sendTyping(['destination' => '081234567890', 'state' => 'paused']);
```

| Kunci | Wajib | Keterangan |
| --- | --- | --- |
| `destination` | ya | Sama seperti `sendMessage()` |
| `state` | tidak | `composing` (bawaan), `paused`, atau `recording` |
| `duration` | ya, kecuali `paused` | Lama indikator ditampilkan, dalam **detik** |

`state` boleh memakai istilah gateway mana pun; SDK memetakannya ke kosakata di
atas — `typing` menjadi `composing`, `stop` menjadi `paused`, `audio` menjadi
`recording`. Jadi kode yang sama jalan di semua gateway.

**`duration` wajib** saat menampilkan indikator, dan itu bukan sekadar
formalitas: Fonnte menuntutnya di sisi server, sedangkan Evolution API menahan
indikator selama durasi itu lalu menghapusnya sendiri. Dengan durasi 0 keduanya
tidak menampilkan apa pun — dan itu gagal tanpa pesan apa-apa, jadi SDK
menolaknya lebih dulu dengan `ConfigurationException`.

| Gateway | Endpoint | Keadaan | `duration` |
| --- | --- | --- | --- |
| Fonnte | `POST /typing` | ketik atau berhenti (`stop`) | dipakai; wajib |
| OpenWA | `POST /api/sessions/{id}/chats/typing` | `typing` \| `recording` \| `paused` | diabaikan |
| ApiMe | `POST /api/instances/{id}/whatsapp/presence` | `composing` \| `recording` \| `paused` | diabaikan |
| Evolution API | `POST /chat/sendPresence/{instance}` | `composing` \| `recording` \| `paused` | dipakai, satuan milidetik |
| Wuzapi | `POST /chat/presence` | `composing` + `Media: audio` \| `paused` | diabaikan |
| Wwebjs | `POST /chat/sendStateTyping` · `…/sendStateRecording` · `…/clearState` | endpoint berbeda per keadaan | diabaikan; indikatornya bertahan ~25 detik di server |

Empat hal yang mudah menjebak, dan sudah ditangani SDK:

- **Evolution API ikut menahan pemanggil.** Servernya yang mengirim
  `composing`, menunggu `delay`, lalu mengirim `paused` — jadi panggilan ini
  **memblokir** selama `duration`. Durasi di atas 20 detik dipotong Evolution
  menjadi beberapa siklus. Ini satu-satunya gateway yang membersihkan
  indikatornya sendiri di dalam satu request; yang lain menyimpan statusnya
  sampai dihapus dengan `paused` atau sampai ada pesan yang benar-benar
  terkirim — sedangkan indikator Wwebjs kedaluwarsa sendiri setelah ~25 detik.
- **Fonnte tidak punya indikator merekam suara**, hanya indikator mengetik.
  Meminta `recording` di sana melempar `ConfigurationException` — bukan
  diam-diam berubah menjadi indikator mengetik.
- **Wuzapi tidak punya keadaan `recording`.** Merekam suara dikirim sebagai
  `composing` dengan `Media` berisi `audio`, dan itu yang disusun SDK.
- **Wwebjs memakai endpoint berbeda untuk tiap keadaan**, bukan satu endpoint
  dengan kolom status. Karena tidak ada kolom durasi, `duration` di sana hanya
  menentukan berapa lama SDK menunggu sebelum pesannya dikirim.

### Jeda antar pesan (pacing)

Mengirim beruntun dengan jeda yang seragam mudah dikenali sebagai robot. Pacing
menyusun jeda dari tiga bagian, dan ketiganya bisa dipakai sendiri-sendiri maupun
bersamaan:

| Kunci | Arti |
| --- | --- |
| `WHATSAPP_PACING_CYCLE` | Daftar jeda tetap yang dipakai **bergiliran**, detik. `0,30` berarti pesan ke-1 tanpa jeda, ke-2 jeda 30 detik, ke-3 tanpa jeda, dan seterusnya |
| `WHATSAPP_PACING_INTERVAL` | Jitter acak yang **ditambahkan** ke tiap jeda siklus, detik. `20-30` berarti setiap jeda ditambah 20–30 detik acak |
| `WHATSAPP_PACING_LONG_CHARS` | Ambang pesan panjang, dalam **karakter**. Bawaannya 300 |
| `WHATSAPP_PACING_LONG_FACTOR` | Berapa kali jeda **dilipatkan** untuk pesan sepanjang itu. Bawaannya 3 |

Jadi jeda sebelum pesan ke-`i` adalah
`(siklus[i % jumlah siklus] + jitter) × pengali pesan panjang`. Dengan
`0,30` + `20-30` + ambang bawaan 300 karakter, jedanya berurutan 20–30, 50–60,
20–30, … detik untuk pesan pendek, dan 60–90, 150–180, 60–90, … detik untuk
pesan 300 karakter ke atas.

Pesan panjang sengaja ditunggu lebih lama: mengirim teks panjang beruntun lebih
mencurigakan daripada mengirim pesan pendek, dan pesan panjang juga lebih lama
"dibaca". Panjangnya dihitung dalam karakter, bukan byte — 250 huruf beraksen
tetap dianggap pesan pendek. Aturannya bisa dimatikan dengan
`WHATSAPP_PACING_LONG_CHARS=0`, atau dinetralkan dengan
`WHATSAPP_PACING_LONG_FACTOR=1`.

Bawaannya **mati**: selama kedua kunci pertama kosong, tiap gateway memakai jeda
bawaannya sendiri seperti sebelumnya. Ini disengaja — mengirim banyak pesan
menahan proses pemanggil selama total jeda itu, jadi pacing harus dinyalakan
dengan sadar.

Untuk satu panggilan saja, sertakan kuncinya bersama pesan. Yang disebutkan saja
yang ditimpa; sisanya tetap diambil dari `.env`:

```php
$client->send([
    'messages' => [
        ['destination' => '0811111111', 'message' => 'Pesan pertama'],
        ['destination' => '0822222222', 'message' => 'Pesan kedua'],
    ],
    'pacing' => ['cycle' => '0,45', 'interval' => '10-20', 'long_factor' => 5],
]);
```

`delay` pada satu pesan tetap menang atas pacing untuk pesan itu — termasuk
`'delay' => 0`, yang berarti "pesan ini tanpa jeda". Pesan pertama tidak pernah
ditunggu: jeda sebelum pengiriman pertama adalah urusan pemanggil, bukan SDK.

Siapa yang benar-benar mengerjakan jedanya berbeda per gateway, dan itu memang
sifat gateway-nya:

| Gateway | Jeda dikerjakan oleh |
| --- | --- |
| Fonnte | Server, lewat kolom `delay` tiap pesan |
| OpenWA | Server, lewat `delayBetweenMessages` — satu angka untuk seluruh batch, diambil dari jeda sebelum pesan kedua |
| Evolution API | Server, lewat kolom `delay` payload (dalam milidetik) |
| ApiMe, Wuzapi, Wwebjs | SDK, dengan `sleep()` di antara request |

Karena jedanya dititipkan ke server, Fonnte, OpenWA, dan Evolution API tidak
menahan klien dua kali. OpenWA juga menambahkan pengacakan di sisinya sendiri
(`randomizeDelay`).

### Indikator mengetik otomatis

`sendTyping()` di atas eksplisit — pemanggil yang memanggilnya. Kalau ingin
indikatornya muncul sendiri di setiap `send()`, nyalakan `WHATSAPP_TYPING` dan
SDK yang mengurusnya: ia menampilkan "sedang mengetik…" untuk tujuan itu, lalu
**menunggu selama indikatornya tampil** sebelum pesannya dikirim.

Lamanya mengikuti panjang pesan, sama seperti pacing. Pesan 300 karakter yang
"diketik" dalam satu detik sama tidak wajarnya dengan "Halo" yang diketipkan
sepuluh detik:

```
lama = panjang pesan ÷ kecepatan ketik, dijepit antara min dan max
```

| Kunci | Arti |
| --- | --- |
| `WHATSAPP_TYPING` | Saklar on/off. Bawaannya **mati** |
| `WHATSAPP_TYPING_SPEED` | Kecepatan ketik, dalam **karakter per detik**. Bawaannya 15 |
| `WHATSAPP_TYPING_MIN` | Lama tampil paling singkat, detik. Bawaannya 2 |
| `WHATSAPP_TYPING_MAX` | Lama tampil paling lama, detik. Bawaannya 20 |

Dengan bawaannya, 10 karakter menjadi 2 detik, 60 karakter 4 detik, 150 karakter
10 detik, dan 300 karakter ke atas berhenti di 20 detik.

Bawaannya **mati** karena fitur ini menyisipkan satu request tambahan ke jalur
kirim dan menahan pemanggil selama durasinya. Hanya saklarnya yang menyalakan —
mengisi `WHATSAPP_TYPING_SPEED` saja di `.env` tidak mengubah apa pun.

```php
$client->send(['destination' => '081234567890', 'message' => 'Halo']);
// 1. POST /chat/presence  { "State": "composing" }
// 2. tunggu 2 detik
// 3. POST /chat/send/text
```

Untuk satu panggilan saja, sertakan kunci `typing`. Berbeda dari `.env` yang
jadi saklar global, menulis kunci ini di dalam array pesan sudah berarti
permintaan — jadi ia menyalakan fiturnya tanpa perlu `WHATSAPP_TYPING`:

```php
$client->send([
    'destination' => '081234567890',
    'message'     => 'Laporan harian sudah siap',
    'typing'      => ['speed' => 8, 'max' => 30],   // atau ['enabled' => false]
]);
```

Kuncinya boleh juga ditaruh di tiap pesan dalam pengiriman massal, jadi satu
pesan bisa dibiarkan tanpa indikator sementara yang lain memakainya:

```php
$client->send([
    ['destination' => '0811111111', 'message' => 'Pesan pertama'],
    ['destination' => '0822222222', 'message' => 'Pesan kedua', 'typing' => ['enabled' => false]],
]);
```

Siapa yang benar-benar menunggu berbeda per gateway, dan itu memang sifat
gateway-nya:

| Gateway | Yang terjadi |
| --- | --- |
| Evolution API | Servernya sudah menunggu dan menghapus indikatornya sendiri, jadi SDK **tidak** menunggu lagi — kalau tidak, pemanggil menunggu dua kali |
| ApiMe, Wuzapi, OpenWA, Fonnte, Wwebjs | Indikator hanya menyimpan status, jadi SDK yang menghabiskan durasinya |

Tiga hal yang mudah menjebak, dan sudah ditangani SDK:

- **Fonnte dan OpenWA mengirim seluruh batch dalam satu request.** SDK tidak
  punya kesempatan menyisipkan indikator di antara pesan, jadi yang dimunculkan
  hanya indikator untuk **tujuan pesan pertama**. Memunculkan untuk semua tujuan
  sekaligus justru membuat penerima terakhir melihat "sedang mengetik" lalu diam
  lama sebelum pesannya datang — lebih buruk daripada tanpa indikator. ApiMe,
  Evolution API, wuzapi, dan Wwebjs mengirim satu per satu, jadi tiap pesan
  dapat indikatornya sendiri.
- **Berkas tidak didahului indikator.** `sendImage()` dan `sendFile()` tidak
  melewati indikator "sedang mengetik" — yang dikirim bukan ketikan, dan
  mengatakannya akan berbohong. Pakai `sendTyping()` sendiri kalau memang mau.
- **Indikator yang gagal tidak menggagalkan pesannya.** Mengirim pesan jauh
  lebih penting daripada hiasannya, jadi kegagalan `composing` (endpoint tidak
  ada, sesi tidak terhubung, timeout) dilewati begitu saja — dan tidak menambah
  jeda yang tidak dipakai. Karena SDK ini tidak punya logger, kegagalan itu
  senyap; kalau perlu diketahui, panggil `sendTyping()` sendiri dan tangani
  exception-nya.

### Dua gaya pemanggilan

`send()` melempar exception kalau gagal; `notify()` mengembalikan string dan
tidak pernah melempar.

```php
// Notifikasi yang tidak boleh menggagalkan request pemanggil:
$result = $client->notify($message);   // selalu string

// Pengiriman yang kegagalannya harus ditangani:
try {
    $client->send($message);
} catch (\Sikuwa\Whatsapp\Exceptions\WhatsappException $e) {
    // ...
}
```

### Memilih gateway

`WHATSAPP_PROVIDER` menerima nama gateway (tidak peka huruf besar/kecil) atau
`Auto`. Mode `Auto` mengundi **hanya di antara gateway yang
`WHATSAPP_TOKEN_<Provider>`-nya terisi**, sehingga undian tidak pernah jatuh ke
gateway yang belum dikonfigurasi.

```php
Client::configured();   // mis. ['OpenWA', 'Wuzapi']
$client->provider();    // instance gateway terpilih
```

`provider()` mengembalikan instance baru setiap dipanggil. Untuk `Auto` itu
berarti undiannya diulang — panggil sekali lalu simpan hasilnya kalau beberapa
pesan harus lewat gateway yang sama.

## Sesi

Selain mengirim pesan, SDK bisa membuat dan memeriksa sesi WhatsApp lewat
antarmuka yang sama di semua gateway:

```php
$session = $client->checkSession();   // sesi dari konfigurasi

if (! $session->isConnected()) {
    echo $session->qrTag();           // <img> QR siap tampil
}

// Buat sesi/instance baru, lalu pindai QR-nya.
$baru = $client->createSession(['name' => 'Notifikasi Sekolah']);
echo $baru->id;
```

Gateway menyebutnya berbeda-beda — OpenWA dan Wwebjs "session", ApiMe dan
Evolution API "instance", wuzapi dan Fonnte "device" — tetapi semuanya
mengembalikan `Sikuwa\Whatsapp\Session` yang sama:

| Properti | Isi |
| --- | --- |
| `$provider` | Nama gateway, mis. `OpenWA` |
| `$id` | Id sesi menurut gateway |
| `$status` | Status apa adanya dari gateway, mis. `CONNECTED` atau `open` |
| `$connected` | Apakah sesi siap mengirim pesan |
| `$qr` | QR sebagai data URI, bila ada |
| `$token` | Kredensial yang baru diterbitkan gateway, bila ada |
| `$phoneNumber` / `$profileName` | Identitas yang tersambung, bila ada |
| `$raw` | Amplop asli gateway, apa adanya |

`isConnected()`, `hasQr()`, `toArray()`, dan `toJson()` tersedia sebagai
penolong; `(string) $session` menghasilkan ringkasan siap log, mis.
`OpenWA: CONNECTED (sess-1)`. Nilai yang tidak disediakan gateway dibiarkan
kosong, tidak ditebak.

`$token` berisi kredensial yang diterbitkan saat sesi dibuat — token perangkat
Fonnte, atau `hash.apikey` Evolution API. Itulah yang diisi ke `WHATSAPP_TOKEN`
supaya pesan bisa dikirim lewat sesi tersebut. Baik `$token` maupun `$raw`
sengaja **tidak** ikut di `toArray()`/`toJson()`, karena keluaran itu biasanya
berakhir di log atau response HTTP.

Nama sesi diambil dari `$options` bila diberikan, selain itu dari konfigurasi
(`WHATSAPP_SESSION` / `WHATSAPP_INSTANCE`) — jadi `createSession()` tanpa
argumen tetap masuk akal di aplikasi yang kredensialnya sudah ada di `.env`.
Fonnte adalah pengecualian: `createSession()` di sana memang menuntut `name`
dan `device`, karena perangkat baru butuh nomor yang belum pernah dipakai.

### QR sesi

Sesi yang belum tersambung bisa dimintai QR-nya lewat `showQr()`. Hasilnya
`Session` yang sama, dengan `$qr` terisi:

```php
$qr = $client->showQr();

if ($qr->hasQr()) {
    echo $qr->qrTag();     // <img src="data:image/png;base64,…" width="260" height="260">
    echo $qr->qrImage();   // data URI — untuk atribut src
    echo $qr->qrBase64();  // base64 murni — untuk disimpan atau dikirim sebagai JSON
}
```

`$qr` selalu **data URI penuh atau string kosong**, walaupun gateway tidak
sepakat soal bentuknya: Fonnte mengirim base64 PNG telanjang di `url`, OpenWA
dan wuzapi mengirim data URI yang sudah lengkap, Evolution API mengirim base64
di `base64`. Perangkaiannya dikerjakan `Support\Qr::dataUri()`, jadi pemanggil
tidak perlu tahu bedanya.

**Sesi yang sudah tersambung tidak punya QR.** Gateway yang mengatakannya terus
terang dilaporkan sebagai `isConnected() === true` dengan `hasQr() === false`,
bukan sebagai exception:

| Gateway | Jawaban saat sudah tersambung |
| --- | --- |
| Evolution API | `{"instance":{"state":"open"}}` |
| Fonnte | `{"status":false,"reason":"device already connect"}` |
| Wuzapi | error `already logged in` |
| OpenWA | HTTP 400 — sebabnya bercampur dengan sesi yang belum `qr_ready`, jadi tetap dilempar |
| Wwebjs | JSON `qr code not ready or already scanned` — dibedakan dengan membaca status sesi |

Karena itu `showQr()` aman dipanggil tanpa memeriksa `checkSession()` lebih
dulu:

```php
$qr = $client->showQr();

if ($qr->isConnected()) {
    // Sudah tersambung — tidak ada yang perlu dipindai.
} elseif ($qr->hasQr()) {
    echo $qr->qrTag('Scan untuk menyambungkan WhatsApp', 320);
}
```

Catatan per gateway:

- **OpenWA** — `GET /api/sessions/{id}/qr` menuntut API key berperan
  **operator**; kunci baca biasa ditolak dengan HTTP 403. Endpoint ini hanya
  menjawab saat sesi sedang menunggu dipindai, dan `status` pada balasannya
  (`qr_ready`) adalah kesiapan QR, bukan status sesi.
- **ApiMe** — OpenAPI-nya menyebut balasan endpoint ini hanya sebagai "QR code
  base64", tanpa skema. SDK karena itu membaca beberapa kemungkinan nama field
  sekaligus (`qr`, `qrcode`, `qrCode`, `base64`, `image`), termasuk `data` yang
  berupa string.
- **Wuzapi** — QR hanya keluar saat sesinya tersambung ke server WhatsApp
  **tetapi belum login**; `no session` dan `not connected` tetap kegagalan.
- **Fonnte** — QR memakai **token perangkat** (`WHATSAPP_TOKEN`), bukan account
  token seperti `createSession()`/`checkSession()`. `showQr('08123456789')`
  mengirim nomornya sebagai `whatsapp`. Mode `type=code` (kode pairing) belum
  didukung.
- **Wwebjs** — QR-nya diambil dari `GET /session/qr/{id}/image`, yang mengirim
  PNG biner; SDK yang membungkusnya menjadi data URI. Endpoint itu menjawab JSON
  saat QR-nya tidak ada, dan sebabnya bisa dua hal sekaligus — session belum
  selesai dimuat, atau QR-nya sudah dipindai. Yang kedua dibedakan dengan
  menanyakan `GET /session/status/{id}` sekali.

| Gateway | Membuat sesi | Memeriksa sesi | QR sesi |
| --- | --- | --- | --- |
| OpenWA | `POST /api/sessions` — `id`, `name`, `config` | `GET /api/sessions/{id}` | `GET /api/sessions/{id}/qr` |
| ApiMe | `POST /api/instances` — `name`, `webhook_url`, `webhook_secret` | `GET /api/instances/{id}` | `GET /api/instances/{id}/qr` |
| Evolution API | `POST /instance/create` — `instanceName`, `qrcode`, `webhook` | `GET /instance/connectionState/{instance}` | `GET /instance/connect/{instance}` |
| Wuzapi | `POST /session/connect` — `subscribe`, `immediate` | `GET /session/status` | `GET /session/qr` |
| Fonnte | `POST /add-device` — `name`, `device`, `autoread` | `POST /get-devices` | `POST /qr` |
| Wwebjs | `POST /session/start/{id}` — `id`, `webhookUrl` | `GET /session/status/{id}` | `GET /session/qr/{id}/image` |

Catatan:

- **Sesi baru biasanya belum tersambung.** OpenWA membuatnya `INITIALIZING`,
  Evolution API `created`, Fonnte `created`, Wwebjs `starting`. Ambil QR-nya
  dengan `showQr()`, lalu pantau dengan `checkSession()`.
- **wuzapi tidak mengenal id sesi** — token yang terpasang sudah menentukan
  sesinya, jadi `createSession()` di sana berarti *menyambungkan* sesi dan
  `checkSession($id)` mengabaikan argumennya. Yang menandakan sesi siap dipakai
  adalah `LoggedIn`, bukan `Connected`.
- **Fonnte memakai dua kredensial yang berbeda.** Mengirim pesan memakai token
  perangkat (`WHATSAPP_TOKEN`), sedangkan Device API — menambah dan membaca
  perangkat — menuntut **account token** (`WHATSAPP_ACCOUNT_TOKEN`); token
  perangkat ditolak di sana dengan `"unknown user"`. Karena itu
  `checkSession()` tanpa argumen mencari perangkat yang tokennya sama dengan
  `WHATSAPP_TOKEN`, sehingga pertanyaan "apakah perangkat yang saya pakai siap?"
  terjawab langsung.

  ```php
  $device = $client->createSession(['name' => 'Notifikasi', 'device' => '08123456789']);

  // Perangkat baru menerbitkan tokennya sendiri — simpan ke WHATSAPP_TOKEN.
  file_put_contents('.env', "WHATSAPP_TOKEN={$device->token}\n", FILE_APPEND);
  ```
- **Membuat instance ApiMe menuntut token user (JWT) atau API token.** Token
  ber-scope instance — yang justru dipakai untuk mengirim pesan — ditolak
  dengan HTTP 403, jadi pembuatan instance biasanya dijalankan sekali dari
  dashboard atau skrip admin.
- **Nama instance Evolution API hanya boleh huruf kecil dan angka.** SDK
  menolaknya lebih awal dengan pesan yang jelas, bukan meneruskan HTTP 400.
- **Nama session Wwebjs hanya boleh huruf, angka, garis bawah, dan tanda
  minus**; SDK menolaknya lebih awal, bukan meneruskan HTTP 422 dari
  middleware-nya. `createSession()` di sana juga **menunggu Chromium selesai
  dimuat** — bisa mendekati `WHATSAPP_TIMEOUT` bawaan (10 detik), jadi naikkan
  timeout kalau sering berakhir `TimeoutException`.

## Konfigurasi

Lihat [`.env.example`](.env.example). Ringkasnya:

| Kunci | Keterangan |
| --- | --- |
| `WA_NOTIFICATION` | Penanda notifikasi aktif. SDK **tidak** menegakkannya; tersedia lewat `$client->enabled()` |
| `WHATSAPP_PROVIDER` | `Auto` atau nama gateway |
| `WHATSAPP_TOKEN_<Provider>` | Token per gateway. Inilah yang dihitung mode `Auto` |
| `WHATSAPP_TOKEN` | Token umum, dipakai bila token khusus gateway tidak ada. **Tidak dihitung mode `Auto`** |
| `WHATSAPP_URL_<Provider>` | Base URL per gateway. **Ini yang sebaiknya dipakai** untuk self-hosted |
| `WHATSAPP_URL` | Base URL cadangan bila kunci per-provider kosong. **Diabaikan Fonnte** |
| `WHATSAPP_SESSION` | Khusus OpenWA dan Wwebjs. Juga id bawaan `createSession()`/`checkSession()` |
| `WHATSAPP_INSTANCE` | Khusus ApiMe dan Evolution API. Juga nama bawaan `createSession()` |
| `WHATSAPP_ACCOUNT_TOKEN` | Khusus Fonnte Device API (`add-device`, `get-devices`). Bukan token perangkat |
| `WHATSAPP_TIMEOUT` | Batas waktu request, detik (1–60, default 10) |
| `WHATSAPP_PACING_CYCLE` | Jeda tetap yang dipakai bergiliran antar pesan, detik. Mis. `0,30`. Kosong = pacing mati |
| `WHATSAPP_PACING_INTERVAL` | Jitter acak yang ditambahkan ke tiap jeda siklus, detik. Mis. `20-30` |
| `WHATSAPP_PACING_LONG_CHARS` | Ambang pesan panjang, karakter (default 300). `0` = aturannya dimatikan |
| `WHATSAPP_PACING_LONG_FACTOR` | Pengali jeda untuk pesan panjang (default 3). `1` = tidak ada pengalian |
| `WHATSAPP_TYPING` | Munculkan indikator "sedang mengetik" sendiri sebelum mengirim. Kosong = mati |
| `WHATSAPP_TYPING_SPEED` | Kecepatan ketik, karakter per detik (default 15) |
| `WHATSAPP_TYPING_MIN` | Lama indikator tampil paling singkat, detik (default 2) |
| `WHATSAPP_TYPING_MAX` | Lama indikator tampil paling lama, detik (default 20) |

### URL per gateway

Satu `WHATSAPP_URL` bersama tidak cukup kalau Anda memakai lebih dari satu
gateway self-hosted: nilainya berlaku untuk provider **apa pun** yang sedang
aktif, jadi URL OpenWA akan ikut terpakai Wuzapi. Pakai kunci per-provider:

```dotenv
WHATSAPP_URL_OpenWA=https://wa-1.internal
WHATSAPP_URL_Wuzapi=https://wa-2.internal
```

Setara lewat opsi konstruktor:
`['urls' => ['OpenWA' => 'https://wa-1.internal']]`.

Urutan pembacaannya: kunci per-provider → `url` yang diberikan eksplisit →
`WHATSAPP_URL` → default provider. Kunci per-provider sengaja **tidak** jatuh ke
`WHATSAPP_URL`, sama seperti `WHATSAPP_TOKEN_<Provider>` yang tidak jatuh ke
`WHATSAPP_TOKEN`.

Nilai default bila semuanya dikosongkan: OpenWA `https://openwa.whatsapp.com`,
ApiMe `https://api-me.whatsapp.com`, Evolution API
`https://evolution-api.whatsapp.com`, Wuzapi `https://wuzapi.whatsapp.com`,
Wwebjs `https://wwebjs.whatsapp.com`. Fonnte punya endpoint tetap sendiri.

### Catatan per gateway

- **Fonnte** — selalu membalas HTTP 200; keberhasilan sebenarnya ada di field
  `status`. Endpoint-nya tetap, jadi `WHATSAPP_URL` sengaja **tidak** dibaca:
  kalau dibaca, satu nilai yang ditujukan untuk gateway self-hosted akan
  mengalihkan pengiriman Fonnte ke host yang salah. Yang diterima hanya URL
  yang jelas milik Fonnte: opsi `url` eksplisit atau `WHATSAPP_URL_Fonnte`.
  Device API-nya memakai **account token** (`WHATSAPP_ACCOUNT_TOKEN`), bukan
  token perangkat, dan host-nya diturunkan dari URL pengiriman — jadi override
  proxy ikut berlaku untuk `add-device` dan `get-devices`.
- **OpenWA** — butuh `WHATSAPP_SESSION`. Satu pesan dikirim ke `send-text`
  (sinkron, balasannya `messageId`); lebih dari satu dikirim ke `send-bulk`
  (asinkron, balasannya `batchId`, maksimum 100 pesan per batch).
- **ApiMe** — butuh `WHATSAPP_INSTANCE` dan token **ber-scope instance**; JWT
  user maupun API token global ditolak dengan HTTP 403. Pengiriman memakai
  `Idempotency-Key` deterministik, sehingga kartu yang ter-scan dua kali
  beruntun tidak menghasilkan dua pesan.
- **Evolution API** — butuh `WHATSAPP_INSTANCE`. `delay` dititipkan ke server
  lewat payload (dalam milidetik), jadi klien tidak ikut menunggu. Nilainya
  diambil dari `delay` pesan, atau dari pacing bila tidak diisi.
- **Wuzapi** — tidak butuh instance: tokennya sendiri yang menentukan sesi.
  Auth memakai header `Token`, bukan `Authorization` seperti yang tertulis di
  README wuzapi.
- **Wwebjs** — butuh `WHATSAPP_SESSION`. Auth memakai header `x-api-key`, dan
  **API key-nya opsional**: selama `API_KEY` tidak diisi di sisi server, semua
  endpoint terbuka. Pengiriman menuntut session yang sudah `CONNECTED`; selama
  belum, middleware-nya membalas HTTP 404 — dan SDK menerjemahkannya menjadi
  petunjuk yang jelas, bukan "Not Found" apa adanya.

## Error

Semua error melempar subclass dari `Sikuwa\Whatsapp\Exceptions\WhatsappException`:

| Kelas | Kapan |
| --- | --- |
| `ConfigurationException` | Token/URL/session/instance belum diisi, atau bentuk pesan salah. Tidak akan sembuh kalau diulang |
| `UnknownProviderException` | Nama gateway tidak dikenali |
| `ApiException` | Gateway menjawab tapi menolak. Membawa `getStatus()`, `getBody()`, `getErrorKind()` |
| `AuthException` | 401 — token ditolak |
| `ForbiddenException` | 403 — token kurang hak (mis. bukan instance token di ApiMe) |
| `NotFoundException` | 404 — instance/session tidak ditemukan, atau perangkat Fonnte yang dicari tidak ada di akun |
| `ConflictException` | 409 — masih ada pengiriman dengan `Idempotency-Key` yang sama |
| `RateLimitException` | 429 — satu-satunya status yang aman diretry |
| `ServiceUnavailableException` | 503 — sesi WhatsApp belum siap, tidak ada pesan terkirim |
| `TimeoutException` | Request melewati `WHATSAPP_TIMEOUT` |

Pengiriman massal ke gateway tanpa endpoint batch (ApiMe, Evolution API,
wuzapi, Wwebjs) mengirim satu per satu. Kegagalan satu nomor tidak menghentikan
sisanya; semuanya dikumpulkan lalu dilempar sebagai satu `ApiException`, jadi
pemanggil melihat gambaran lengkapnya:

```
2/3 pesan terkirim. Gagal: 62822: ApiMe menolak pesan (HTTP 400): nomor tidak terdaftar
```

## Pengujian

```bash
composer install
composer test
```

Tidak ada jaringan yang tersentuh: test menyuntikkan klien Guzzle ber-handler
`MockHandler` lewat opsi `httpClient`, sehingga tidak perlu monkey-patching global.

```php
$client = new Client(['provider' => 'Fonnte'], $mockGuzzleClient);
```

## Butuh satu gateway saja?

Kalau hanya memakai satu gateway, SDK ini berlebihan. Gunakan langsung
[SDK PHP OpenWA](https://github.com/rmyndharis/OpenWA/tree/main/sdk/php)
yang sudah teruji.

## Feature

- [x] Enam gateway: Fonnte, OpenWA, ApiMe, Evolution API, Wuzapi, Wwebjs
- [x] Check Session
- [x] Create sessions
- [x] Show QR
- [x] Send messages
- [x] Jeda antar pesan (pacing)
- [x] Send Media Image
- [x] Send Media File
- [x] Human Being Typing (`sendTyping()` eksplisit dan otomatis lewat `WHATSAPP_TYPING`)

## Rencana Pengembangan

- [ ] Menambahkan Gateway [Baileys API](https://github.com/rsuppersahabatan/baileys-api)
- [ ] Menambahkan Gateway [Whatsameow Node API](https://github.com/mdestafadilah/api-whatsameow-node) based on [Whatsmeow Node](https://github.com/nicastelo/whatsmeow-node)

## Kredit

Terinspirasi dari [SDK PHP OpenWA](https://github.com/rmyndharis/OpenWA/tree/main/sdk/php).
