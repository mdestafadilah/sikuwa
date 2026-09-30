<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Transport HTTP yang bisa disuntik.
 *
 * SDK tidak pernah membangun klien Guzzle dengan handler yang dipaku di dalam.
 * Untuk pengujian, suntikkan klien yang handler-nya
 * {@see \GuzzleHttp\Handler\MockHandler} lewat opsi `httpClient` pada
 * {@see \Sikuwa\Whatsapp\Client} — tanpa monkey-patching global.
 *
 * Method `post()` dan `get()` tidak pernah melempar exception; kegagalan
 * transport dilaporkan lewat {@see HttpResponse::$error}. Yang melempar adalah
 * provider, supaya pesan errornya bisa disesuaikan dengan amplop gateway
 * masing-masing.
 */
final class HttpExecutor
{
    /** Batas waktu handshake koneksi (detik). */
    public const CONNECT_TIMEOUT = 5.0;

    private ClientInterface $http;

    /**
     * @param array<string,string> $defaultHeaders Dikirim pada setiap request,
     *                                             di bawah header milik provider
     *                                             (yang selalu menang).
     */
    public function __construct(
        ?ClientInterface $httpClient = null,
        private readonly float $timeout = 10.0,
        private readonly array $defaultHeaders = []
    ) {
        $this->http = $httpClient ?? new GuzzleClient();
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    public function client(): ClientInterface
    {
        return $this->http;
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
        $options = \is_array($body)
            ? ['form_params' => $body]
            : ['body' => $body];

        return $this->send('POST', $url, $options, $headers);
    }

    /**
     * Kirim satu request POST berbadan `multipart/form-data`.
     *
     * Dibutuhkan gateway yang menuntut berkasnya diunggah sebagai biner —
     * Fonnte dan ApiMe — bukan dikirim sebagai base64 di dalam JSON. Bentuk
     * `form_params` tidak bisa dipakai untuk itu: ia meng-encode semuanya
     * sebagai teks, sehingga byte berkasnya rusak.
     *
     * `Content-Type` sengaja tidak diisi: hanya Guzzle yang tahu `boundary`
     * yang dipakai memisahkan bagian-bagian body, dan header buatan sendiri
     * akan membuat server gagal mengurainya.
     *
     * @param array<int,array{name:string, contents:string, filename?:string,
     *                        headers?:array<string,string>}> $parts
     * @param array<string,string> $headers Header untuk request ini, di luar
     *                                      header autentikasi milik provider
     */
    public function postMultipart(string $url, array $parts, array $headers = []): HttpResponse
    {
        return $this->send('POST', $url, ['multipart' => $parts], $headers);
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
        return $this->send('GET', $url, [], $headers);
    }

    /**
     * Satu-satunya tempat request benar-benar ditembak, supaya aturan yang
     * berlaku untuk semua method (tanpa redirect, tanpa throw, timeout) hanya
     * ditulis sekali.
     *
     * @param array<string,mixed>  $options Opsi Guzzle; bentuk body sudah
     *                                     ditentukan pemanggil (`body`,
     *                                     `form_params`, atau `multipart`)
     * @param array<string,string> $headers
     */
    private function send(string $method, string $url, array $options, array $headers): HttpResponse
    {
        // Status non-2xx ditangani sendiri oleh provider, bukan dilempar Guzzle.
        $options['http_errors'] = false;
        // Redirect tidak pernah diikuti: token dikirim ulang ke host tujuan,
        // yang belum tentu origin yang sama.
        $options['allow_redirects'] = false;
        $options['timeout'] = $this->timeout;
        $options['connect_timeout'] = min(self::CONNECT_TIMEOUT, $this->timeout);
        // Header provider ditaruh paling akhir supaya tidak bisa ditimpa oleh
        // defaultHeaders milik pemanggil.
        $options['headers'] = array_merge($this->defaultHeaders, $headers);

        try {
            $response = $this->http->request($method, $url, $options);
        } catch (ConnectException $e) {
            // cURL error 28 (CURLE_OPERATION_TIMEDOUT) adalah penanda timeout yang
            // paling andal: nomor errno tidak bergantung locale maupun versi.
            $errno = $e->getHandlerContext()['errno'] ?? null;
            $timedOut = $errno === 28 || str_contains($e->getMessage(), 'timed out');

            return new HttpResponse(0, null, $e->getMessage(), $timedOut);
        } catch (GuzzleException $e) {
            return new HttpResponse(0, null, $e->getMessage());
        }

        return new HttpResponse(
            $response->getStatusCode(),
            (string) $response->getBody(),
            '',
            false,
            // Diambil di sini karena inilah satu-satunya titik yang masih
            // memegang objek respons Guzzle; setelah ini provider hanya
            // melihat HttpResponse.
            $response->getHeaderLine('Retry-After') !== '' ? $response->getHeaderLine('Retry-After') : null
        );
    }
}
