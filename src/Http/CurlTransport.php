<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Http;

/**
 * Transport bawaan: cURL langsung, tanpa pustaka HTTP perantara.
 *
 * SDK ini hanya butuh empat hal dari sebuah klien HTTP — POST form, POST
 * multipart, GET, dan pembacaan satu header — sehingga kehadiran Guzzle beserta
 * seluruh pohon dependensinya tidak sepadan: 1,2 MB untuk 172 berkas, tiga kali
 * lipat ukuran SDK-nya sendiri. Yang di sini meniru perilaku yang sudah
 * diandalkan provider:
 *
 * - redirect **tidak** diikuti (token tidak boleh terkirim ulang ke host lain);
 * - status 4xx/5xx bukan kegagalan transport;
 * - `Retry-After` diteruskan mentah, bentuk detik maupun tanggal diurus
 *   {@see HttpResponse::retryAfterSeconds()};
 * - timeout koneksi dan timeout total dibedakan dari kegagalan lain.
 *
 * Verifikasi TLS dibiarkan menyala seperti bawaan PHP. Menembak gateway
 * WhatsApp dengan sertifikat yang tidak diperiksa berarti token dan isi pesan
 * bisa dibaca pihak lain di jalur yang sama.
 */
final class CurlTransport implements Transport
{
    /** Batas waktu handshake koneksi (detik). */
    public const CONNECT_TIMEOUT = 5.0;

    public function send(HttpRequest $request): HttpResponse
    {
        $handle = curl_init();

        if ($handle === false) {
            return new HttpResponse(0, null, 'cURL tidak bisa diinisialisasi');
        }

        // Header dikumpulkan dari baris demi baris: hanya `Retry-After` yang
        // dipakai, dan ia bisa datang pada respons apa pun — termasuk 429/503.
        $retryAfter = null;

        $headers = [];

        foreach ($request->headers() as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $body = $request->getBody();

        // cURL menambahkan `Expect: 100-continue` sendiri untuk badan di atas
        // 1 KB, lalu menunggu satu detik bila server diam. Unggahan media bisa
        // berkali-kali lipat batas itu, jadi header itu dimatikan.
        if (\strlen($body) > 1024) {
            $headers[] = 'Expect:';
        }

        $options = [
            CURLOPT_URL => $request->getUri(),
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function (mixed $handle, string $line) use (&$retryAfter): int {
                if (stripos($line, 'retry-after:') === 0) {
                    $retryAfter = trim(substr($line, \strlen('retry-after:')));
                }

                return \strlen($line);
            },
            CURLOPT_TIMEOUT_MS => self::milliseconds($request->timeout),
            CURLOPT_CONNECTTIMEOUT_MS => self::milliseconds(
                min(self::CONNECT_TIMEOUT, $request->timeout)
            ),
            // Tanpa ini, timeout di bawah satu detik diabaikan sebagian build
            // cURL yang memakai sinyal untuk menghentikan transfer.
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => $headers,
        ];

        $method = $request->getMethod();

        if ($method !== 'GET' && $method !== 'HEAD') {
            // Badan kosong tetap dipasang supaya cURL mengirim
            // `Content-Length: 0` — sebagian gateway menolak POST tanpa itu.
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);

        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        $error = curl_error($handle);

        // curl_close() sengaja tidak dipanggil: sejak PHP 8 handle dilepas saat
        // objeknya tidak lagi dipakai, dan fungsinya sudah ditandai usang.

        if ($errno !== 0 || $responseBody === false) {
            $message = $error !== '' ? $error : 'Kegagalan transport tanpa pesan dari cURL';

            // CURLE_OPERATION_TIMEDOUT (28) menandai habisnya waktu, baik saat
            // menyambung maupun saat menunggu balasan. Nomor errno dipakai
            // lebih dulu karena teksnya bisa diterjemahkan oleh locale.
            $timedOut = $errno === 28 || str_contains($message, 'timed out');

            return new HttpResponse(0, null, $message, $timedOut);
        }

        return new HttpResponse(
            $status,
            (string) $responseBody,
            '',
            false,
            $retryAfter === null || $retryAfter === '' ? null : $retryAfter
        );
    }

    /** Detik ke milidetik, minimal 1 ms supaya cURL tidak membacanya sebagai "tanpa batas". */
    private static function milliseconds(float $seconds): int
    {
        return max(1, (int) round($seconds * 1000));
    }
}
