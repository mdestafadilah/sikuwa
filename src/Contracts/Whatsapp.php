<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Contracts;

use Sikuwa\Whatsapp\Session;

/**
 * Antarmuka tunggal yang harus dipenuhi setiap gateway WhatsApp.
 *
 * Semua provider memakai bentuk pesan yang sama supaya mengganti gateway —
 * atau membiarkan SDK memilihnya sendiri — tidak mengubah kode pemanggil:
 *
 * ```php
 * ['destination' => '081234567890', 'message' => 'Halo', 'delay' => 2]
 * ```
 *
 * `delay` opsional dan dihitung dalam detik; artinya jeda sebelum pesan
 * dikirim (Fonnte dan Evolution API) atau jeda antar pesan pada pengiriman
 * berurutan. Bila tidak diisi, jedanya diambil dari pacing (`WHATSAPP_PACING_*`)
 * — termasuk bagian yang bergantung pada panjang isi pesan, karena pesan
 * panjang ditunggu lebih lama — lalu dari bawaan gateway.
 *
 * Indikator "sedang mengetik" yang dimunculkan SDK sendiri sebelum mengirim
 * juga berlaku di sini, diatur `WHATSAPP_TYPING`. Selama kunci itu kosong,
 * jalur kirim berjalan seperti sebelumnya.
 *
 * Sesi WhatsApp juga seragam: {@see self::createSession()},
 * {@see self::checkSession()}, dan {@see self::showQr()} selalu mengembalikan
 * {@see Session}, apa pun gateway-nya — walaupun tiap gateway menyebutnya
 * berbeda (OpenWA "session", ApiMe dan Evolution API "instance", wuzapi dan
 * Fonnte "device").
 */
interface Whatsapp
{
    /**
     * Kirim satu pesan, atau beberapa sekaligus.
     *
     * @param array<string,mixed>|array<int,array<string,mixed>>|string $message
     *        Satu pesan `['destination' => ..., 'message' => ..., 'delay' => ...]`,
     *        atau list dari array seperti itu untuk pengiriman massal.
     *        Jeda antar pesan diatur pacing; untuk menimpanya pada satu
     *        panggilan saja, bungkus list-nya —
     *        `['messages' => [...], 'pacing' => ['cycle' => '0,30']]`.
     *        Kunci `typing` (`['speed' => 8, 'max' => 30]`) menyalakan
     *        indikator "sedang mengetik" untuk panggilan itu saja.
     *
     * @return string Detail hasil yang siap dicatat ke log. Setiap provider
     *                mengawalinya dengan `"Sukses"` supaya pemanggil bisa
     *                menentukan level log tanpa tahu gateway mana yang dipakai.
     *
     * @throws \Sikuwa\Whatsapp\Exceptions\WhatsappException Bila pesan gagal
     *         dikirim. Pakai {@see \Sikuwa\Whatsapp\Client::notify()} kalau
     *         pemanggil lebih suka menerima string alih-alih exception.
     */
    public function sendMessage(array|string $message): string;

    /**
     * Kirim satu gambar.
     *
     * Bentuk pesannya seragam di semua gateway:
     *
     * ```php
     * [
     *     'destination' => '081234567890',
     *     'image'       => 'data:image/png;base64,iVBORw0KGgo…',
     *     'filename'    => 'bukti.png',        // opsional
     *     'caption'     => 'Bukti transfer',   // opsional
     * ]
     * ```
     *
     * Isi `image` boleh salah satu dari tiga bentuk; gateway-nya sendiri yang
     * menyesuaikan:
     *
     * - **data URI** (`data:image/png;base64,…`) — bentuk paling aman, karena
     *   jenis berkasnya ikut terbawa;
     * - **base64 telanjang**, tanpa awalan `data:`. Jenisnya lalu ditebak dari
     *   `filename`, dan menjadi `application/octet-stream` bila ekstensinya
     *   tidak dikenali;
     * - **URL publik** (`https://…`), bila gateway boleh mengunduhnya sendiri.
     *   ApiMe dan wuzapi tidak bisa — keduanya menuntut isi berkasnya ikut
     *   dikirim, dan akan melempar {@see Exceptions\ConfigurationException}
     *   yang menjelaskan bahwa berkasnya perlu diunduh lebih dulu.
     *
     * Kunci `media` diterima sebagai alias `image`, supaya pemanggil yang
     * menyimpan berkasnya secara umum tidak perlu tahu method mana yang akan
     * dipakai.
     *
     * Yang menentukan sebuah berkas dikirim sebagai gambar atau dokumen adalah
     * **jenis berkasnya**, bukan method yang dipanggil — jadi `sendImage()`
     * dengan PDF tetap terkirim sebagai dokumen, dan sebaliknya.
     *
     * @param array<string,mixed> $message
     *
     * @return string Detail hasil yang siap dicatat ke log, berawalan
     *                `"Sukses"` seperti {@see self::sendMessage()}.
     *
     * @throws \Sikuwa\Whatsapp\Exceptions\WhatsappException
     */
    public function sendImage(array $message): string;

    /**
     * Kirim satu berkas/dokumen (PDF, DOCX, XLSX, ZIP, dan seterusnya).
     *
     * Bentuk pesannya sama dengan {@see self::sendImage()}, hanya kuncinya
     * `file`:
     *
     * ```php
     * [
     *     'destination' => '081234567890',
     *     'file'        => $base64Pdf,        // atau 'media'
     *     'filename'    => 'invoice-1209.pdf',
     *     'caption'     => 'Invoice bulan ini',
     * ]
     * ```
     *
     * `filename` lebih penting di sini daripada pada gambar: ia menentukan nama
     * yang dilihat penerima sekaligus jenis berkasnya, dan beberapa gateway
     * menolak dokumen tanpa nama. Karena itu isilah `filename` bila isinya
     * base64 telanjang.
     *
     * @param array<string,mixed> $message
     *
     * @return string Detail hasil yang siap dicatat ke log, berawalan
     *                `"Sukses"` seperti {@see self::sendMessage()}.
     *
     * @throws \Sikuwa\Whatsapp\Exceptions\WhatsappException
     */
    public function sendFile(array $message): string;

    /**
     * Tampilkan (atau hapus) indikator "sedang mengetik" — supaya balasan bot
     * tidak muncul seketika seperti mesin.
     *
     * Bentuknya seragam di semua gateway:
     *
     * ```php
     * $client->sendTyping([
     *     'destination' => '081234567890',
     *     'state'       => 'composing',   // composing | paused | recording
     *     'duration'    => 5,             // detik
     * ]);
     * ```
     *
     * `state` boleh dikosongkan, artinya `composing`. Istilah gateway lain
     * juga diterima dan dipetakan ke kosakata di atas — `typing` menjadi
     * `composing`, `stop` menjadi `paused`, `audio` menjadi `recording`.
     *
     * **`duration` wajib diisi saat menampilkan indikator** (jadi tidak perlu
     * untuk `paused`). Dua gateway memakainya untuk menentukan berapa lama
     * indikator tampil, dan tanpa angka keduanya tidak menampilkan apa pun:
     *
     * - **Fonnte** menuntut `duration` di sisi server;
     * - **Evolution API** menahan indikator selama `duration` lalu
     *   menghapusnya sendiri — dan **panggilan ini ikut menunggu** selama itu,
     *   karena servernya yang menidurkan permintaan. Durasi panjang dipotong
     *   Evolution menjadi siklus 20 detik.
     *
     * Tiga gateway sisanya mengabaikan `duration`: statusnya bertahan sampai
     * pemanggil menghapusnya sendiri dengan `state => 'paused'`, atau sampai
     * sebuah pesan benar-benar terkirim. Jadi rangkaian yang aman di semua
     * gateway adalah menampilkan indikator, lalu menghapusnya:
     *
     * ```php
     * $client->sendTyping(['destination' => $to, 'duration' => 3]);
     * $client->sendMessage(['destination' => $to, 'message' => 'Halo']);
     * $client->sendTyping(['destination' => $to, 'state' => 'paused']);
     * ```
     *
     * Fonnte tidak punya indikator merekam suara; meminta `recording` di sana
     * melempar {@see Exceptions\ConfigurationException}, bukan diam-diam
     * mengirim indikator mengetik.
     *
     * @param array<string,mixed> $message Kunci `destination` (wajib),
     *                                     `state` (opsional, default
     *                                     `composing`), dan `duration` (wajib
     *                                     saat menampilkan indikator).
     *
     * @return string Detail hasil yang siap dicatat ke log, berawalan
     *                `"Sukses"` seperti {@see self::sendMessage()}.
     *
     * @throws \Sikuwa\Whatsapp\Exceptions\WhatsappException
     */
    public function sendTyping(array $message): string;

    /**
     * Buat sesi/instance baru di gateway.
     *
     * Nama sesi diambil dari `$options` bila ada, selain itu dari konfigurasi
     * yang sudah terpasang — `WHATSAPP_SESSION_<Provider>` dulu, baru
     * `WHATSAPP_SESSION`, dan `WHATSAPP_INSTANCE_<Provider>` dulu, baru
     * `WHATSAPP_INSTANCE`. Karena itu `createSession()` tanpa argumen pun masuk
     * akal di aplikasi yang seluruh kredensialnya sudah ada di `.env`, dan tiap
     * gateway tetap bisa memakai nama sesi/instance-nya sendiri. Fonnte adalah
     * pengecualian: ia memang menuntut `name` dan `device` di `$options`,
     * karena perangkat baru butuh nomor yang belum pernah dipakai.
     *
     * Kunci `$options` yang dikenali berbeda per gateway dan diteruskan apa
     * adanya — mis. OpenWA `id`, `name`, `config`; ApiMe `name`,
     * `webhook_url`; Evolution API `instanceName`, `webhook`; Fonnte `name`,
     * `device`; wuzapi `subscribe`, `immediate`.
     *
     * Sesi yang baru dibuat biasanya belum tersambung: pindai QR-nya, lalu
     * pantau dengan {@see self::checkSession()}.
     *
     * @param array<string,mixed> $options
     *
     * @throws \Sikuwa\Whatsapp\Exceptions\WhatsappException
     */
    public function createSession(array $options = []): Session;

    /**
     * Baca keadaan sesi yang sudah ada.
     *
     * @param string|null $id Sesi yang diperiksa; default dari konfigurasi.
     *
     * @throws \Sikuwa\Whatsapp\Exceptions\WhatsappException
     */
    public function checkSession(?string $id = null): Session;

    /**
     * Ambil QR milik sesi yang sudah ada, untuk dipindai.
     *
     * Dipanggil setelah {@see self::createSession()} atau
     * {@see self::checkSession()} menunjukkan sesi belum tersambung.
     * Mengembalikan {@see Session} yang sama, dengan {@see Session::$qr}
     * terisi data URI PNG:
     *
     * ```php
     * $qr = $client->showQr();
     *
     * if ($qr->hasQr()) {
     *     echo '<img src="' . $qr->qrImage() . '">';   // atau $qr->qrBase64()
     * }
     * ```
     *
     * Sesi yang sudah tersambung tidak punya QR untuk dipindai. Gateway yang
     * mengatakannya terus terang — Evolution API membalas keadaan instance,
     * Fonnte dan wuzapi menyebutnya di alasan penolakan — dilaporkan sebagai
     * `connected === true` dengan `$qr` kosong, bukan sebagai exception.
     * Gateway yang hanya membalas error umum (OpenWA) tetap melempar, karena
     * di sana penyebabnya bisa beberapa hal sekaligus.
     *
     * @param string|null $id Sesi yang diminta QR-nya; default dari konfigurasi.
     *                        Fonnte memakainya sebagai nomor perangkat.
     *
     * @throws \Sikuwa\Whatsapp\Exceptions\WhatsappException
     */
    public function showQr(?string $id = null): Session;

    /** Nama gateway, dipakai untuk memilih token dan menandai baris log. */
    public function getProvider(): string;

    /** Token efektif: token eksplisit bila ada, selain itu dari environment. */
    public function getToken(): string;
}
