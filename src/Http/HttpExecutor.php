<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Http;

/**
 * Perakit request: header bawaan, timeout, dan bentuk badan.
 *
 * Kelas ini tidak menyentuh jaringan sama sekali — pekerjaan itu milik
 * {@see Transport}. Pemisahan itu yang membuat pengujian bisa menyuntikkan
 * transport palsu lewat opsi `httpClient` pada {@see \Sikuwa\Whatsapp\Client}
 * tanpa monkey-patching global.
 *
 * Method `post()`, `postMultipart()`, dan `get()` tidak pernah melempar
 * exception; kegagalan transport dilaporkan lewat {@see HttpResponse::$error}.
 * Yang melempar adalah provider, supaya pesan errornya bisa disesuaikan dengan
 * amplop gateway masing-masing.
 */
final class HttpExecutor
{
    /** Batas waktu handshake koneksi (detik). */
    public const CONNECT_TIMEOUT = CurlTransport::CONNECT_TIMEOUT;

    private Transport $transport;

    /**
     * @param Transport|null        $transport      Bawaan: {@see CurlTransport}.
     * @param array<string,string>  $defaultHeaders Dikirim pada setiap request,
     *                                              di bawah header milik provider
     *                                              (yang selalu menang).
     */
    public function __construct(
        ?Transport $transport = null,
        private readonly float $timeout = 10.0,
        private readonly array $defaultHeaders = []
    ) {
        $this->transport = $transport ?? new CurlTransport();
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    /**
     * Kirim satu request POST.
     *
     * @param array<string,mixed>|string $body    array => form-encoded, string => body mentah
     * @param array<string,string>       $headers Header untuk request ini, dalam bentuk
     *                                             peta `['Nama' => 'nilai']` — bukan daftar
     *                                             `['Nama: nilai']` seperti pada cURL.
     */
    public function post(string $url, array|string $body, array $headers = []): HttpResponse
    {
        return $this->send('POST', $url, $headers, $body);
    }

    /**
     * Kirim satu request POST berbadan `multipart/form-data`.
     *
     * Dibutuhkan gateway yang menuntut berkasnya diunggah sebagai biner —
     * Fonnte dan ApiMe — bukan dikirim sebagai base64 di dalam JSON. Bentuk
     * `form_params` tidak bisa dipakai untuk itu: ia meng-encode semuanya
     * sebagai teks, sehingga byte berkasnya rusak.
     *
     * `Content-Type` sengaja tidak diisi provider: `boundary` yang memisahkan
     * bagian-bagian body baru diketahui saat body dirakit, dan header buatan
     * sendiri akan membuat server gagal mengurainya.
     *
     * @param array<int,array{name:string, contents:string, filename?:string,
     *                        headers?:array<string,string>}> $parts
     * @param array<string,string> $headers Header untuk request ini, di luar
     *                                      header autentikasi milik provider
     */
    public function postMultipart(string $url, array $parts, array $headers = []): HttpResponse
    {
        return $this->send('POST', $url, $headers, null, $parts);
    }

    /**
     * Kirim satu request GET tanpa body.
     *
     * Dipakai endpoint yang hanya membaca keadaan — mis. pemeriksaan sesi —
     * sehingga provider tidak perlu merakit request-nya sendiri.
     *
     * @param array<string,string> $headers
     */
    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->send('GET', $url, $headers);
    }

    /**
     * Satu-satunya tempat request dirakit, supaya aturan yang berlaku untuk
     * semua method (header bawaan di bawah header provider, timeout yang sama)
     * hanya ditulis sekali.
     *
     * @param array<string,string> $headers
     * @param array<string,mixed>|string|null $body
     * @param array<int,array{name:string, contents:string, filename?:string,
     *                        headers?:array<string,string>}>|null $multipart
     */
    private function send(
        string $method,
        string $url,
        array $headers,
        array|string|null $body = null,
        ?array $multipart = null
    ): HttpResponse {
        return $this->transport->send(new HttpRequest(
            $method,
            $url,
            // Header provider ditaruh paling akhir supaya tidak bisa ditimpa
            // oleh defaultHeaders milik pemanggil.
            array_merge($this->defaultHeaders, $headers),
            $body,
            $multipart,
            $this->timeout
        ));
    }
}
