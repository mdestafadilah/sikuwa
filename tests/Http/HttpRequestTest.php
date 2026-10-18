<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sikuwa\Whatsapp\Http\CurlTransport;
use Sikuwa\Whatsapp\Http\HttpExecutor;
use Sikuwa\Whatsapp\Http\HttpRequest;

/**
 * Perakitan request: encoding badan dan header.
 *
 * Yang diuji di sini adalah byte yang benar-benar dikirim. Kesalahan di lapisan
 * ini tidak akan terlihat sebagai exception — gateway hanya membalas "field
 * tidak dikenal" atau berkas yang rusak — jadi bentuknya dipaku di tes.
 */
final class HttpRequestTest extends TestCase
{
    public function testFormBodyIsEncodedLikeHtmlForms(): void
    {
        $request = new HttpRequest('POST', 'https://contoh.test', [], ['nama' => 'budi santoso', 'nomor' => '0812']);

        // Spasi menjadi `+`, bukan `%20`: itu yang dihasilkan
        // `application/x-www-form-urlencoded` dan yang dibaca `parse_str()`.
        self::assertSame('nama=budi+santoso&nomor=0812', $request->getBody());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
    }

    public function testRawBodyGetsNoContentType(): void
    {
        $request = new HttpRequest('POST', 'https://contoh.test', [], '{"halo":"dunia"}');

        self::assertSame('{"halo":"dunia"}', $request->getBody());
        self::assertSame('', $request->getHeaderLine('Content-Type'), 'Provider yang menentukan header JSON-nya sendiri');
    }

    public function testMultipartBodyCarriesBoundaryAndFilename(): void
    {
        $request = new HttpRequest('POST', 'https://contoh.test', [], null, [
            ['name' => 'target', 'contents' => '628123456789'],
            [
                'name' => 'file',
                'contents' => 'halo dunia',
                'filename' => 'catatan.txt',
                'headers' => ['Content-Type' => 'text/plain'],
            ],
        ]);

        $contentType = $request->getHeaderLine('Content-Type');

        self::assertMatchesRegularExpression('#^multipart/form-data; boundary=\S+$#', $contentType);

        preg_match('/boundary=(\S+)/', $contentType, $cocok);
        $boundary = $cocok[1];

        $body = $request->getBody();

        self::assertStringContainsString('--' . $boundary . "\r\n", $body);
        self::assertStringContainsString('name="target"', $body);
        self::assertStringContainsString('628123456789', $body);
        self::assertStringContainsString('name="file"; filename="catatan.txt"', $body);
        self::assertStringContainsString('Content-Type: text/plain', $body);
        self::assertStringContainsString('halo dunia', $body);
        self::assertStringEndsWith('--' . $boundary . "--\r\n", $body);
    }

    public function testQuotesAndNewlinesInFilenameCannotBreakThePartHeader(): void
    {
        $request = new HttpRequest('POST', 'https://contoh.test', [], null, [
            ['name' => 'file', 'contents' => 'x', 'filename' => "a\"b\r\nX-Palsu: 1"],
        ]);

        $body = $request->getBody();

        self::assertStringNotContainsString("filename=\"a\"b", $body);
        self::assertStringNotContainsString("\r\nX-Palsu: 1", $body);
        self::assertStringContainsString('a%22b%0D%0AX-Palsu: 1', $body);
    }

    public function testHeaderOfTheCallerWinsOverTheDerivedContentType(): void
    {
        $request = new HttpRequest(
            'POST',
            'https://contoh.test',
            ['content-type' => 'application/json'],
            ['nama' => 'budi']
        );

        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $request->getHeaderLine('CONTENT-TYPE'));
    }

    public function testMethodIsUppercasedAndTimeoutIsKept(): void
    {
        $request = new HttpRequest('post', 'https://contoh.test', [], '', null, 2.5);

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://contoh.test', $request->getUri());
        self::assertSame(2.5, $request->timeout);
    }

    public function testExecutorFallsBackToTheCurlTransport(): void
    {
        // Bukti bahwa SDK tidak lagi butuh klien HTTP pihak ketiga: tanpa
        // suntikan apa pun, transport bawaannya adalah cURL.
        self::assertInstanceOf(CurlTransport::class, (new HttpExecutor())->transport());
    }
}
