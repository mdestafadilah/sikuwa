<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Client;
use Sikuwa\Whatsapp\Config;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Providers\ApiMe\ApiMe;
use Sikuwa\Whatsapp\Providers\EvolutionAPI\EvolutionAPI;
use Sikuwa\Whatsapp\Providers\Fonnte\Fonnte;
use Sikuwa\Whatsapp\Providers\OpenWA\OpenWA;
use Sikuwa\Whatsapp\Providers\Wuzapi\Wuzapi;

/**
 * Kirim gambar dan berkas — `sendImage()` / `sendFile()` di semua gateway.
 *
 * Yang diperiksa bukan cuma hasil akhirnya, tapi juga method HTTP, URL, header
 * autentikasi, dan bentuk body yang benar-benar dikirim: itulah bagian yang
 * paling mudah rusak saat provider dirapikan.
 *
 * Yang dijaga ketat di sini adalah **perbedaan bentuk antar gateway**, karena
 * itulah alasan kelas `Support\File` ada:
 *
 * - Fonnte mengunggah byte mentah sebagai multipart, bukan base64;
 * - OpenWA menerima base64 telanjang dengan `mimetype` di kolom terpisah;
 * - ApiMe juga multipart, tapi dengan dua endpoint dan nama kolom berbeda;
 * - Evolution API menolak data URI dan hanya menerima base64 telanjang;
 * - wuzapi hanya mau data URI, dan dokumennya selalu `octet-stream`.
 */
final class MediaTest extends TestCase
{
    private const BASE = 'https://gw.test';

    /** Base64 PNG kecil; cukup untuk membuktikan perangkaian data URI. */
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUg==';

    private const PNG_URI = 'data:image/png;base64,' . self::PNG_B64;

    private const PDF_B64 = 'JVBERi0xLjQK';

    private const PDF_URI = 'data:application/pdf;base64,' . self::PDF_B64;

    /** Base64 dari "halo dunia" — dipakai pada uji multipart, yang bodynya teks. */
    private const TEKS_B64 = 'aGFsbyBkdW5pYQ==';

    private const TEKS_URI = 'data:text/plain;base64,' . self::TEKS_B64;

    /**
     * Berjenis gambar tetapi isinya teks, supaya body multipart tetap terbaca
     * saat di-assert. Yang menentukan sebuah berkas dikirim sebagai gambar
     * adalah jenisnya, dan jenisnya datang dari data URI — bukan dari isi
     * berkasnya.
     */
    private const GAMBAR_TEKS_URI = 'data:image/png;base64,' . self::TEKS_B64;

    private const URL = 'https://files.test/bukti.png';

    protected function tearDown(): void
    {
        Config::useResolver(null);
    }

    // ---------------------------------------------------------------------
    // Fonnte — unggahan multipart
    // ---------------------------------------------------------------------

    public function testFonnteUploadsTheFileAsMultipart(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => true, 'detail' => '2/2'])]);

        $hasil = $this->fonnte($backend)->sendFile([
            'destination' => '08123456789',
            'file' => self::TEKS_URI,
            'filename' => 'catatan.txt',
            'caption' => 'Halo',
        ]);

        $body = $backend->lastBody();

        self::assertSame('Sukses: 2/2', $hasil);
        self::assertSame('POST', $backend->lastRequest()?->getMethod());
        self::assertSame('https://api.fonnte.com/send', (string) $backend->lastRequest()?->getUri());
        self::assertSame('device-tok', $backend->lastHeader('Authorization'));

        // Fonnte tidak punya kolom base64: berkasnya harus jadi byte mentah.
        self::assertStringContainsString('multipart/form-data', $backend->lastHeader('Content-Type'));
        self::assertStringContainsString('name="target"', $body);
        self::assertStringContainsString('628123456789', $body);
        self::assertStringContainsString('name="filename"', $body);
        self::assertStringContainsString('catatan.txt', $body);
        self::assertStringContainsString('name="message"', $body);
        // Isinya sudah di-decode kembali dari base64.
        self::assertStringContainsString('halo dunia', $body);
        self::assertStringNotContainsString(self::TEKS_B64, $body);
    }

    public function testFonnteUsesThePublicUrlInsteadOfUploading(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => true])]);

        $this->fonnte($backend)->sendImage([
            'destination' => '08123456789',
            'image' => self::URL,
        ]);

        $body = $backend->lastBody();

        // Kalau sumbernya URL, biar server Fonnte yang mengunduh.
        self::assertStringContainsString('name="url"', $body);
        self::assertStringContainsString(self::URL, $body);
        self::assertStringNotContainsString('name="file"', $body);
    }

    // ---------------------------------------------------------------------
    // OpenWA — base64 telanjang, dua endpoint
    // ---------------------------------------------------------------------

    public function testOpenWaSendsAnImageAsBareBase64(): void
    {
        $backend = new MockBackend([MockBackend::json(['messageId' => 'mid-1'])]);

        $hasil = $this->openWa($backend)->sendImage([
            'destination' => '08123456789',
            'image' => self::PNG_URI,
            'caption' => 'Bukti transfer',
        ]);

        $json = $backend->lastJson();

        self::assertSame('Sukses, messageId: mid-1', $hasil);
        self::assertSame(
            self::BASE . '/api/sessions/sess-1/messages/send-image',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('k', $backend->lastHeader('X-API-Key'));
        // chatId wajib JID lengkap.
        self::assertSame('628123456789@c.us', $json['chatId']);
        self::assertSame('image/png', $json['mimetype']);
        self::assertSame('lampiran.png', $json['filename']);
        self::assertSame('Bukti transfer', $json['caption']);
        // OpenWA mengirim mimetype di kolom sendiri, jadi awalan data URI
        // justru harus dibuang.
        self::assertSame(self::PNG_B64, $json['base64']);
    }

    public function testOpenWaSendsADocumentToTheDocumentEndpoint(): void
    {
        $backend = new MockBackend([MockBackend::json(['messageId' => 'mid-2'])]);

        $this->openWa($backend)->sendFile([
            'destination' => '08123456789',
            'file' => self::PDF_URI,
            'filename' => 'invoice.pdf',
        ]);

        $json = $backend->lastJson();

        self::assertSame(
            self::BASE . '/api/sessions/sess-1/messages/send-document',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('application/pdf', $json['mimetype']);
        self::assertSame('invoice.pdf', $json['filename']);
        self::assertSame(self::PDF_B64, $json['base64']);
    }

    public function testOpenWaPrefersThePublicUrlOverBase64(): void
    {
        $backend = new MockBackend([MockBackend::json(['messageId' => 'mid-3'])]);

        $this->openWa($backend)->sendImage([
            'destination' => '08123456789',
            'image' => self::URL,
        ]);

        $json = $backend->lastJson();

        self::assertSame(self::URL, $json['url']);
        // Jangan kirim keduanya sekaligus.
        self::assertArrayNotHasKey('base64', $json);
    }

    // ---------------------------------------------------------------------
    // ApiMe — multipart, dua endpoint dengan kolom berbeda
    // ---------------------------------------------------------------------

    public function testApiMeUploadsAnImageAsMultipart(): void
    {
        $backend = new MockBackend([MockBackend::json(['data' => ['whatsappId' => 'wamid-1']])]);

        $hasil = $this->apiMe($backend)->sendImage([
            'destination' => '08123456789',
            'image' => self::GAMBAR_TEKS_URI,
            'caption' => 'Halo',
        ]);

        $body = $backend->lastBody();

        self::assertSame('Sukses, messageId: wamid-1', $hasil);
        self::assertSame(
            self::BASE . '/api/instances/uuid-9/messages/media',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('Bearer inst-token', $backend->lastHeader('Authorization'));
        self::assertStringContainsString('multipart/form-data', $backend->lastHeader('Content-Type'));
        self::assertStringContainsString('name="type"', $body);
        self::assertStringContainsString('image', $body);
        self::assertStringContainsString('name="file"', $body);
        self::assertStringContainsString('halo dunia', $body);
    }

    public function testApiMeUploadsADocumentToTheDocumentEndpoint(): void
    {
        $backend = new MockBackend([MockBackend::json(['data' => ['id' => 'doc-1']])]);

        $this->apiMe($backend)->sendFile([
            'destination' => '08123456789',
            'file' => self::TEKS_URI,
            'filename' => 'catatan.txt',
        ]);

        $body = $backend->lastBody();

        self::assertSame(
            self::BASE . '/api/instances/uuid-9/messages/document',
            (string) $backend->lastRequest()?->getUri()
        );
        // Dokumen memakai `fileName`, bukan `type`.
        self::assertStringContainsString('name="fileName"', $body);
        self::assertStringContainsString('catatan.txt', $body);
        self::assertStringNotContainsString('name="type"', $body);
    }

    public function testApiMeRejectsAPublicUrlBecauseItOnlyAcceptsUploads(): void
    {
        $backend = new MockBackend();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('unggahan biner, bukan URL');

        $this->apiMe($backend)->sendImage([
            'destination' => '08123456789',
            'image' => self::URL,
        ]);
    }

    // ---------------------------------------------------------------------
    // Evolution API — base64 telanjang di dalam JSON
    // ---------------------------------------------------------------------

    public function testEvolutionSendsAnImageWithoutTheDataUriPrefix(): void
    {
        $backend = new MockBackend([MockBackend::json(['key' => ['id' => 'evo-1']])]);

        $hasil = $this->evolution($backend)->sendImage([
            'destination' => '08123456789',
            'image' => self::PNG_URI,
            'caption' => 'Bukti',
        ]);

        $json = $backend->lastJson();

        self::assertSame('Sukses, messageId: evo-1', $hasil);
        self::assertSame(
            self::BASE . '/message/sendMedia/sikuwa',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('global-key', $backend->lastHeader('apikey'));
        self::assertSame('628123456789', $json['number']);
        self::assertSame('image', $json['mediatype']);
        self::assertSame('image/png', $json['mimetype']);
        self::assertSame('Bukti', $json['caption']);
        // `isBase64()` di sisi Evolution tidak mengenali awalan data URI,
        // jadi yang dikirim harus base64 telanjang.
        self::assertSame(self::PNG_B64, $json['media']);
        // Nama berkas hanya bermakna untuk dokumen.
        self::assertArrayNotHasKey('fileName', $json);
    }

    public function testEvolutionSendsADocumentWithTheMandatoryFileName(): void
    {
        $backend = new MockBackend([MockBackend::json(['key' => ['id' => 'evo-2']])]);

        $this->evolution($backend)->sendFile([
            'destination' => '08123456789',
            'file' => self::PDF_URI,
            'filename' => 'invoice.pdf',
        ]);

        $json = $backend->lastJson();

        self::assertSame('document', $json['mediatype']);
        self::assertSame('application/pdf', $json['mimetype']);
        self::assertSame(self::PDF_B64, $json['media']);
        // Tanpa fileName, Evolution menolak dokumen berbasis base64.
        self::assertSame('invoice.pdf', $json['fileName']);
    }

    public function testEvolutionPassesThePublicUrlStraightThrough(): void
    {
        $backend = new MockBackend([MockBackend::json(['key' => ['id' => 'evo-3']])]);

        $this->evolution($backend)->sendImage([
            'destination' => '08123456789',
            'image' => self::URL,
        ]);

        self::assertSame(self::URL, $backend->lastJson()['media']);
    }

    // ---------------------------------------------------------------------
    // wuzapi — hanya data URI
    // ---------------------------------------------------------------------

    public function testWuzapiSendsAnImageAsADataUri(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true, 'data' => ['Id' => 'wz-1']])]);

        $hasil = $this->wuzapi($backend)->sendImage([
            'destination' => '08123456789',
            'image' => self::PNG_B64,
            // Base64 telanjang tidak membawa jenisnya, jadi nama berkaslah yang
            // menentukannya — tanpa ini berkasnya jadi dokumen.
            'filename' => 'bukti.png',
            'caption' => 'Bukti',
        ]);

        $json = $backend->lastJson();

        self::assertSame('Sukses, messageId: wz-1', $hasil);
        self::assertSame(self::BASE . '/chat/send/image', (string) $backend->lastRequest()?->getUri());
        self::assertSame('user-token', $backend->lastHeader('Token'));
        self::assertSame('628123456789', $json['Phone']);
        // base64 telanjang dibungkus jadi data URI; wuzapi tidak mau yang lain.
        self::assertSame(self::PNG_URI, $json['Image']);
        self::assertSame('Bukti', $json['Caption']);
    }

    public function testWuzapiSendsADocumentAsOctetStream(): void
    {
        $backend = new MockBackend([MockBackend::json(['success' => true, 'data' => ['Id' => 'wz-2']])]);

        $this->wuzapi($backend)->sendFile([
            'destination' => '08123456789',
            'file' => self::PDF_URI,
            'filename' => 'invoice.pdf',
        ]);

        $json = $backend->lastJson();

        self::assertSame(self::BASE . '/chat/send/document', (string) $backend->lastRequest()?->getUri());
        self::assertSame('628123456789', $json['Phone']);
        self::assertSame('invoice.pdf', $json['FileName']);
        // Dokumentasi wuzapi meminta dokumen sebagai octet-stream, bukan
        // sebagai jenis berkas sebenarnya.
        self::assertSame('data:application/octet-stream;base64,' . self::PDF_B64, $json['Document']);
    }

    public function testWuzapiRejectsAPublicUrlBecauseItCannotDownload(): void
    {
        $backend = new MockBackend();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('data URI, bukan URL');

        $this->wuzapi($backend)->sendFile([
            'destination' => '08123456789',
            'file' => self::URL,
        ]);
    }

    // ---------------------------------------------------------------------
    // Bentuk pesan yang seragam
    // ---------------------------------------------------------------------

    public function testMediaKeyIsAcceptedByBothMethods(): void
    {
        $backend = new MockBackend([MockBackend::json(['messageId' => 'mid-4'])]);

        // `media` berlaku untuk sendImage() maupun sendFile(), supaya pemanggil
        // yang menyimpan berkasnya secara umum tidak perlu tahu method mana
        // yang akan dipakai.
        $this->openWa($backend)->sendFile([
            'destination' => '08123456789',
            'media' => self::PNG_URI,
        ]);

        $json = $backend->lastJson();

        // PNG lewat sendFile() tetap terkirim sebagai gambar: yang menentukan
        // endpoint adalah jenis berkasnya, bukan nama method-nya.
        self::assertSame(
            self::BASE . '/api/sessions/sess-1/messages/send-image',
            (string) $backend->lastRequest()?->getUri()
        );
        self::assertSame('image/png', $json['mimetype']);
    }

    public function testMissingPayloadIsRejected(): void
    {
        $backend = new MockBackend();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("kunci 'image'");

        $this->openWa($backend)->sendImage(['destination' => '08123456789']);
    }

    public function testMissingDestinationIsRejected(): void
    {
        $backend = new MockBackend();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("kunci 'destination'");

        $this->openWa($backend)->sendImage(['image' => self::PNG_URI]);
    }

    public function testClientDelegatesMediaSending(): void
    {
        $backend = new MockBackend([MockBackend::json(['messageId' => 'mid-5'])]);

        $client = new Client([
            'provider' => 'OpenWA',
            'token' => 'k',
            'url' => self::BASE,
            'session' => 'sess-1',
        ], $backend->client());

        $hasil = $client->sendImage([
            'destination' => '08123456789',
            'image' => self::PNG_URI,
        ]);

        self::assertSame('Sukses, messageId: mid-5', $hasil);
        self::assertSame(
            self::BASE . '/api/sessions/sess-1/messages/send-image',
            (string) $backend->lastRequest()?->getUri()
        );
    }

    // ---------------------------------------------------------------------
    // Batas ukuran
    // ---------------------------------------------------------------------

    public function testOversizedMediaIsRejectedBeforeAnyRequestIsSent(): void
    {
        $backend = new MockBackend([]);
        $provider = $this->fonnte($backend);

        // 17 MB melebihi batas WhatsApp. Memeriksanya setelah unggahan selesai
        // berarti pemanggil menunggu lama hanya untuk kegagalan yang sudah
        // bisa diketahui sejak awal.
        $besar = 'data:application/pdf;base64,' . base64_encode(random_bytes(17 * 1024 * 1024));

        try {
            $provider->sendFile([
                'destination' => '081234567890',
                'media' => $besar,
                'filename' => 'laporan.pdf',
            ]);
            self::fail('Berkas di atas batas seharusnya ditolak');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('melebihi batas WhatsApp 16 MB', $e->getMessage());
            self::assertStringContainsString('laporan.pdf', $e->getMessage());
            self::assertStringContainsString('17.0 MB', $e->getMessage());
        }

        self::assertSame(0, $backend->count(), 'Tidak boleh ada request sebelum ukurannya diperiksa');
    }

    public function testMediaAtTheLimitStillGoesOut(): void
    {
        $backend = new MockBackend([MockBackend::json(['status' => true])]);
        $provider = $this->fonnte($backend);

        $tepat = 'data:application/pdf;base64,' . base64_encode(random_bytes(16 * 1024 * 1024));

        $hasil = $provider->sendFile([
            'destination' => '081234567890',
            'media' => $tepat,
            'filename' => 'pas.pdf',
        ]);

        self::assertStringContainsString('Sukses', $hasil);
        self::assertSame(1, $backend->count(), 'Tepat 16 MB masih harus dikirim');
    }

    // ---------------------------------------------------------------------
    // Penolong
    // ---------------------------------------------------------------------

    private function openWa(MockBackend $backend): OpenWA
    {
        return new OpenWA(
            ['token' => 'k', 'url' => self::BASE, 'session' => 'sess-1'],
            $backend->executor()
        );
    }

    private function apiMe(MockBackend $backend): ApiMe
    {
        return new ApiMe(
            ['token' => 'inst-token', 'url' => self::BASE, 'instance' => 'uuid-9'],
            $backend->executor()
        );
    }

    private function evolution(MockBackend $backend): EvolutionAPI
    {
        return new EvolutionAPI(
            ['token' => 'global-key', 'url' => self::BASE, 'instance' => 'sikuwa'],
            $backend->executor()
        );
    }

    private function wuzapi(MockBackend $backend): Wuzapi
    {
        return new Wuzapi(['token' => 'user-token', 'url' => self::BASE], $backend->executor());
    }

    /** Sengaja diberi dua kredensial, untuk membuktikan yang dipakai token perangkat. */
    private function fonnte(MockBackend $backend): Fonnte
    {
        return new Fonnte(
            ['token' => 'device-tok', 'account_token' => 'acct-tok'],
            $backend->executor()
        );
    }
}
