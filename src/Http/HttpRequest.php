<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Http;

/**
 * Satu request HTTP yang siap ditembak.
 *
 * Objek ini sengaja menyimpan **bahan**, bukan hasil encoding: bentuk badan
 * (`form_params`, badan mentah, atau multipart) baru diubah menjadi byte saat
 * {@see self::getBody()} dipanggil. Dengan begitu transport apa pun — cURL
 * maupun transport palsu di pengujian — membaca byte yang persis sama.
 *
 * Multipart butuh `boundary` yang tidak boleh muncul di dalam isi bagian mana
 * pun. Nilainya diundi sekali per request dengan panjang 24 heksadesimal,
 * sehingga peluangnya bertabrakan dengan isi berkas dapat diabaikan.
 */
final class HttpRequest
{
    /** Batas waktu bawaan (detik) bila pemanggil tidak menyebutkan. */
    public const DEFAULT_TIMEOUT = 10.0;

    private string $boundary;

    /** @var array<string,string> */
    private array $headers;

    /**
     * @param array<string,string> $headers   Peta `['Nama' => 'nilai']`.
     * @param array<string,mixed>|string|null $body Array => form-encoded,
     *                                              string => badan mentah,
     *                                              null => tanpa badan.
     * @param array<int,array{name:string, contents:string, filename?:string,
     *                        headers?:array<string,string>}>|null $multipart
     *                        Bagian multipart; bila diisi, `$body` diabaikan.
     */
    public function __construct(
        private readonly string $method,
        private readonly string $url,
        array $headers = [],
        private readonly array|string|null $body = null,
        private readonly ?array $multipart = null,
        public readonly float $timeout = self::DEFAULT_TIMEOUT
    ) {
        $this->headers = $headers;
        $this->boundary = '----SikuwaBoundary' . bin2hex(random_bytes(12));
    }

    public function getMethod(): string
    {
        return strtoupper($this->method);
    }

    public function getUri(): string
    {
        return $this->url;
    }

    /**
     * Header yang benar-benar dikirim, sudah termasuk `Content-Type` yang
     * diturunkan dari bentuk badan.
     *
     * Header milik pemanggil selalu menang: kalau provider sudah menulis
     * `Content-Type` sendiri, nilai itu tidak ditimpa.
     *
     * @return array<string,string>
     */
    public function headers(): array
    {
        return $this->resolvedHeaders();
    }

    /**
     * Nilai satu header, tanpa membedakan huruf besar-kecil.
     *
     * Nama header di HTTP tidak peka huruf, sedangkan PHP menyimpan kunci array
     * apa adanya — jadi pencariannya harus dinormalkan di sini, sekali, alih-
     * alih setiap pemanggil mengingatnya sendiri.
     */
    public function getHeaderLine(string $name): string
    {
        foreach ($this->resolvedHeaders() as $header => $value) {
            if (strcasecmp((string) $header, $name) === 0) {
                return (string) $value;
            }
        }

        return '';
    }

    /** @return array<int,array{name:string, contents:string, filename?:string, headers?:array<string,string>}>|null */
    public function parts(): ?array
    {
        return $this->multipart;
    }

    public function isMultipart(): bool
    {
        return $this->multipart !== null;
    }

    /**
     * Badan request dalam bentuk byte, siap dikirim.
     *
     * Badan form di-encode dengan aturan RFC 1738 (`+` untuk spasi) supaya
     * sama dengan yang dulu dihasilkan Guzzle untuk `form_params` — gateway
     * yang membaca `application/x-www-form-urlencoded` mengharapkan bentuk itu.
     */
    public function getBody(): string
    {
        if ($this->multipart !== null) {
            return $this->encodeMultipart();
        }

        if (\is_array($this->body)) {
            return http_build_query($this->body, '', '&', PHP_QUERY_RFC1738);
        }

        return $this->body ?? '';
    }

    /**
     * `Content-Type` yang diturunkan dari bentuk badan, atau null bila badan
     * mentah (provider yang menentukan sendiri, mis. JSON).
     */
    public function contentType(): ?string
    {
        if ($this->multipart !== null) {
            return 'multipart/form-data; boundary=' . $this->boundary;
        }

        return \is_array($this->body) ? 'application/x-www-form-urlencoded' : null;
    }

    /**
     * Header akhir: milik pemanggil, ditambah `Content-Type` turunan bila
     * belum ada.
     *
     * Dipisah dari {@see self::headers()} supaya pembacaan satu header tidak
     * memanggil method publik yang sama dan menghitung ulang semuanya.
     *
     * @return array<string,string>
     */
    private function resolvedHeaders(): array
    {
        $headers = $this->headers;
        $contentType = $this->contentType();

        if ($contentType === null) {
            return $headers;
        }

        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Content-Type') === 0) {
                return $headers;
            }
        }

        $headers['Content-Type'] = $contentType;

        return $headers;
    }

    private function encodeMultipart(): string
    {
        $body = '';

        foreach ($this->multipart ?? [] as $part) {
            $disposition = 'form-data; name="' . self::quote($part['name']) . '"';

            if (isset($part['filename'])) {
                $disposition .= '; filename="' . self::quote($part['filename']) . '"';
            }

            $body .= '--' . $this->boundary . "\r\n";
            $body .= 'Content-Disposition: ' . $disposition . "\r\n";

            foreach ($part['headers'] ?? [] as $header => $value) {
                $body .= $header . ': ' . $value . "\r\n";
            }

            $body .= "\r\n" . $part['contents'] . "\r\n";
        }

        return $body . '--' . $this->boundary . "--\r\n";
    }

    /**
     * Kutip nilai yang masuk ke header multipart.
     *
     * Tanpa ini, nama berkas yang mengandung `"` atau baris baru bisa memecah
     * header dan menyelipkan header lain — mis. `filename` palsu.
     */
    private static function quote(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $value);
    }
}
