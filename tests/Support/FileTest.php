<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests\Support;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Exceptions\ConfigurationException;
use Sikuwa\Whatsapp\Support\File;

/**
 * Penyamaan bentuk berkas antar gateway.
 *
 * Tidak ada satu bentuk kiriman berkas yang diterima semua gateway — ada yang
 * mau data URI, ada yang mau base64 telanjang, ada yang mau byte mentah — jadi
 * kelas inilah yang menerjemahkannya. Yang diuji di sini terjemahannya, bukan
 * pengirimannya.
 */
final class FileTest extends TestCase
{
    /** Base64 dari "halo dunia" — isinya sengaja terbaca supaya mudah ditelusuri. */
    private const B64 = 'aGFsbyBkdW5pYQ==';

    public function testMimeComesFromTheExtension(): void
    {
        self::assertSame('image/png', File::from(self::B64, 'bukti.png')->mime);
        self::assertSame('application/pdf', File::from(self::B64, 'invoice.PDF')->mime);
        self::assertSame('application/zip', File::from(self::B64, 'arsip.zip')->mime);
    }

    public function testUnknownExtensionBecomesOctetStream(): void
    {
        // Berkas yang jenisnya tidak dikenali tetap bisa dikirim — WhatsApp
        // memperlakukannya sebagai dokumen biasa, bukan menolaknya.
        self::assertSame('application/octet-stream', File::from(self::B64, 'aneh.xyz')->mime);
        self::assertSame('application/octet-stream', File::from(self::B64, 'tanpa-ekstensi')->mime);
    }

    public function testMimeFromDataUriBeatsTheFilename(): void
    {
        $file = File::from('data:image/jpeg;base64,' . self::B64, 'salah-tulis.png');

        // Isi berkas lebih dipercaya daripada nama berkasnya.
        self::assertSame('image/jpeg', $file->mime);
        self::assertTrue($file->isImage());
    }

    public function testGivenFilenameIsKept(): void
    {
        self::assertSame('bukti.png', File::from(self::B64, 'bukti.png')->filename);
        // Spasi di sekitar nama dibuang supaya tidak ikut terkirim.
        self::assertSame('bukti.png', File::from(self::B64, '  bukti.png  ')->filename);
    }

    public function testDefaultFilenameFollowsTheMime(): void
    {
        // Nama berkas wajib ada: wuzapi menolak dokumen tanpa nama, jadi nama
        // bawaan harus selalu terisi.
        self::assertSame('lampiran.png', File::from('data:image/png;base64,' . self::B64)->filename);
        self::assertSame('lampiran.pdf', File::from('data:application/pdf;base64,' . self::B64)->filename);
        self::assertSame('lampiran.bin', File::from(self::B64)->filename);
    }

    public function testIsImageFollowsTheMime(): void
    {
        self::assertTrue(File::from(self::B64, 'a.png')->isImage());
        self::assertTrue(File::from(self::B64, 'a.jpeg')->isImage());
        self::assertFalse(File::from(self::B64, 'a.pdf')->isImage());
        self::assertFalse(File::from(self::B64, 'a.zip')->isImage());
    }

    public function testIsUrlOnlyForHttpAndHttps(): void
    {
        self::assertTrue(File::from('https://contoh.test/bukti.png')->isUrl());
        self::assertTrue(File::from('http://contoh.test/bukti.png')->isUrl());
        self::assertFalse(File::from(self::B64, 'a.png')->isUrl());
        // Data URI tidak pernah dianggap URL, walaupun isinya panjang dan
        // mengandung "://" di dalam base64-nya.
        self::assertFalse(File::from('data:image/png;base64,' . self::B64)->isUrl());
    }

    public function testDataUriIsIdempotent(): void
    {
        $wrapped = File::from(self::B64, 'a.png')->dataUri();

        self::assertSame('data:image/png;base64,' . self::B64, $wrapped);
        // Yang sudah data URI tidak boleh dibungkus dua kali.
        self::assertSame($wrapped, File::from($wrapped)->dataUri());
    }

    public function testBase64StripsTheDataUriPrefix(): void
    {
        self::assertSame(self::B64, File::from('data:image/png;base64,' . self::B64)->base64());
        // Yang memang sudah base64 telanjang dikembalikan apa adanya.
        self::assertSame(self::B64, File::from(self::B64, 'a.png')->base64());
    }

    public function testBytesDecodesBackToRawContent(): void
    {
        self::assertSame('halo dunia', File::from(self::B64, 'a.txt')->bytes());
        self::assertSame('halo dunia', File::from('data:text/plain;base64,' . self::B64)->bytes());
    }

    public function testBytesRejectsAPayloadThatIsNeitherBase64NorUsable(): void
    {
        $file = File::from('https://contoh.test/bukti.png');

        // Gateway yang mengunggah byte mentah tidak bisa memakai URL, dan
        // kegagalannya harus menjelaskan kenapa.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('bukan base64 yang sah');

        $file->bytes();
    }

    public function testEmptyPayloadIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Isi berkas kosong');

        File::from('   ');
    }

    // -- Ukuran berkas ---------------------------------------------------

    /**
     * Ukuran harus persis, apa pun panjang isinya.
     *
     * Dihitung dari panjang base64, jadi panjang yang tidak habis dibagi 3
     * (yang menghasilkan padding `=`) adalah kasus yang paling mudah salah —
     * itulah sebabnya daftar ini memuat 1, 2, 4, dan 100 byte, bukan hanya
     * angka bulat yang enak dibagi.
     */
    public function testSizeIsExactForEveryPayloadLength(): void
    {
        foreach ([1, 2, 3, 4, 5, 100, 1023, 1024, 1025, 70000] as $expected) {
            $raw = random_bytes($expected);
            $base64 = base64_encode($raw);

            self::assertSame(
                $expected,
                File::from('data:application/octet-stream;base64,' . $base64, 'x.bin')->size(),
                "Ukuran salah untuk isi {$expected} byte lewat data URI"
            );

            self::assertSame(
                $expected,
                File::from($base64, 'x.bin')->size(),
                "Ukuran salah untuk isi {$expected} byte lewat base64 telanjang"
            );
        }
    }

    public function testSizeIgnoresLineBreaksInWrappedBase64(): void
    {
        // Base64 gaya MIME dibungkus tiap 76 kolom; decoder mengabaikan baris
        // baru, jadi hitungan ukurannya pun harus mengabaikannya.
        $raw = random_bytes(1000);
        $wrapped = chunk_split(base64_encode($raw), 76, "\r\n");

        self::assertSame(1000, File::from('data:application/octet-stream;base64,' . $wrapped, 'x.bin')->size());
    }

    public function testSizeIsNullWhenItCannotBeKnown(): void
    {
        // URL publik diunduh gateway, bukan SDK — ukurannya tidak diketahui,
        // dan itu berbeda dari nol.
        self::assertNull(File::from('https://contoh.test/besar.pdf')->size());
    }

    public function testExceedsLimitComparesAgainstWhatsAppMaximum(): void
    {
        $justUnder = File::from('data:application/pdf;base64,' . base64_encode(random_bytes(16 * 1024 * 1024)), 'a.pdf');
        $justOver = File::from('data:application/pdf;base64,' . base64_encode(random_bytes(16 * 1024 * 1024 + 1)), 'b.pdf');

        self::assertFalse($justUnder->exceedsLimit(), 'Tepat 16 MB masih diterima');
        self::assertTrue($justOver->exceedsLimit(), 'Satu byte di atas batas sudah ditolak');
    }

    public function testExceedsLimitAcceptsACustomBoundary(): void
    {
        $file = File::from('data:application/pdf;base64,' . base64_encode(random_bytes(2000)), 'a.pdf');

        self::assertTrue($file->exceedsLimit(1000));
        self::assertFalse($file->exceedsLimit(3000));
    }

    public function testUnknownSizeIsNeverTreatedAsExceedingTheLimit(): void
    {
        // Menolak berkas yang ukurannya belum tentu besar akan menggagalkan
        // pengiriman yang sebenarnya sah.
        self::assertFalse(File::from('https://contoh.test/besar.pdf')->exceedsLimit());
    }

    public function testReadableSizeUsesTheRightUnit(): void
    {
        // Isi harus base64 yang sah — 'x' bukan, jadi ukurannya nol.
        self::assertSame('512 B', File::from(base64_encode(random_bytes(512)), 'a.bin')->readableSize());
        self::assertSame('2.0 KB', File::from(base64_encode(random_bytes(2048)), 'a.bin')->readableSize());
        self::assertSame('1.0 MB', File::from(base64_encode(random_bytes(1048576)), 'a.bin')->readableSize());
        self::assertSame('', File::from('https://contoh.test/a.pdf')->readableSize());
    }
}
