<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Support;

/**
 * Pembatas laju pengiriman: berapa pesan boleh keluar dalam satu jendela waktu.
 *
 * Berbeda dari {@see Pacing} yang mengatur jeda **antar** pesan di dalam satu
 * batch, kelas ini membatasi **jumlah** pesan — termasuk ketika pemanggil
 * mengirim satu pesan per request, yang justru pola paling sering dipakai
 * aplikasi nyata. Pacing tidak melihat kasus itu sama sekali: lima panggilan
 * `send()` terpisah tidak punya "pesan berikutnya" untuk diberi jeda.
 *
 * Pembatasnya bekerja dengan menahan pemanggil SEBELUM pesan dikirim, bukan
 * menolaknya: kalau jatah jendela ini habis, SDK menunggu sampai jendela
 * berikutnya terbuka. Menolak akan memaksa pemanggil menulis loop retry
 * sendiri — dan loop seperti itulah yang justru mempercepat pemblokiran.
 *
 * Bawaannya **mati**, sama seperti {@see Pacing} dan {@see Typing}: selama
 * `WHATSAPP_THROTTLE_MAX` kosong, {@see self::delayFor()} mengembalikan null
 * dan jalur kirim berjalan persis seperti sebelum fitur ini ada.
 *
 * Batas laju ini **bukan jaminan bebas blokir**. WhatsApp menilai jauh lebih
 * banyak daripada kecepatan — umur akun, rasio pesan masuk-keluar, nomor yang
 * belum pernah dibalas, dan laporan penerima. Kelas ini hanya menjaga satu
 * variabel yang memang bisa dijaga dari sisi pengirim.
 */
final class Throttle
{
    /**
     * Batas satu jeda tunggu, dalam detik.
     *
     * Salah tulis di .env — mis. `WHATSAPP_THROTTLE_MAX=10000` yang dimaksudkan
     * `100` — tidak boleh menahan proses pemanggil berjam-jam. Batas ini
     * berlaku sama seperti {@see Pacing::MAX_DELAY}.
     */
    public const MAX_WAIT = 600;

    /**
     * @param int $max Jumlah pesan yang boleh keluar dalam satu jendela.
     *                 0 atau kurang berarti pembatasnya mati.
     * @param int $window Panjang jendela, detik.
     */
    private function __construct(
        private readonly int $max,
        private readonly int $window
    ) {
    }

    /** Throttle yang tidak mengubah apa pun. */
    public static function none(): self
    {
        return new self(0, 60);
    }

    /**
     * Bangun dari isi environment: `100` dan `60`.
     *
     * Nilai yang tidak bisa dibaca memakai bawaannya, bukan mematikan
     * pembatasnya — sama seperti {@see Pacing::fromConfig()}, salah tulis satu
     * kunci tidak boleh membatalkan kunci lain.
     */
    public static function fromConfig(mixed $max, mixed $window = null): self
    {
        return new self(
            self::parseMax($max),
            self::parseWindow($window)
        );
    }

    /**
     * Bangun dari opsi pemanggil, mis. `['max' => 50, 'window' => 60]`.
     *
     * @param array<string,mixed> $spec
     */
    public static function fromArray(array $spec): self
    {
        return self::none()->merge($spec);
    }

    /**
     * Timpa sebagian nilai, tanpa menyentuh yang tidak disebutkan.
     *
     * @param array<string,mixed>|null $spec
     */
    public function merge(?array $spec): self
    {
        if ($spec === null || $spec === []) {
            return $this;
        }

        return new self(
            array_key_exists('max', $spec) ? self::parseMax($spec['max']) : $this->max,
            array_key_exists('window', $spec) ? self::parseWindow($spec['window']) : $this->window
        );
    }

    /** Apakah pembatas ini mengubah apa pun. */
    public function isEnabled(): bool
    {
        return $this->max > 0 && $this->window > 0;
    }

    /**
     * Jeda sebelum pesan ke-`index` boleh dikirim, dalam detik.
     *
     * Cara kerjanya sederhana dan sengaja tidak menyimpan keadaan: pesan
     * ke-`index` (mulai dari nol) di dalam satu batch dijadwalkan pada
     * `index ÷ max` jendela. Jadi dengan `max = 3` dan `window = 60`, tiga
     * pesan pertama langsung keluar, pesan ke-4 sampai ke-6 menunggu satu
     * jendela, dan seterusnya.
     *
     * Null berarti "tidak ada pembatasan", dan pemanggil yang memutuskan
     * berikutnya. Nol berarti "pembatas aktif, tapi pesan ini masih jatah
     * jendela yang sedang berjalan".
     *
     * @param int $index Posisi pesan di dalam batch, mulai dari nol.
     */
    public function delayFor(int $index): ?int
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $window = intdiv(max(0, $index), $this->max);

        return min(self::MAX_WAIT, $window * $this->window);
    }

    /**
     * Jeda untuk satu batch berisi `$count` pesan, bersiap dipakai berurutan.
     *
     * Dikembalikan sebagai daftar sepanjang `$count` supaya pemanggil tinggal
     * membacanya per posisi — bentuk yang sama dengan hasil {@see Pacing}.
     *
     * Null pada tiap posisi ketika pembatasnya mati, bukan nol: null berarti
     * "tidak ada aturan", sedangkan nol berarti "aturan aktif, tapi pesan ini
     * masih jatah jendela yang berjalan". Pemanggil yang menggabungkannya
     * dengan pacing bergantung pada perbedaan itu.
     *
     * @return array<int,int|null>
     */
    public function schedule(int $count): array
    {
        $delays = [];

        for ($i = 0; $i < $count; $i++) {
            $delays[] = $this->delayFor($i);
        }

        return $delays;
    }

    /** Jumlah pesan yang boleh keluar dalam satu jendela. 0 berarti mati. */
    public function max(): int
    {
        return $this->max;
    }

    /** Panjang jendela, detik. */
    public function window(): int
    {
        return $this->window;
    }

    /** Batas jumlah pesan; nilai tak terbaca atau nol mematikan pembatasnya. */
    private static function parseMax(mixed $value): int
    {
        $text = trim(\is_scalar($value) ? (string) $value : '');

        return is_numeric($text) ? max(0, (int) $text) : 0;
    }

    /** Panjang jendela; nilai tak terbaca atau nol kembali ke bawaan 60 detik. */
    private static function parseWindow(mixed $value): int
    {
        $text = trim(\is_scalar($value) ? (string) $value : '');

        return is_numeric($text) && (int) $text > 0 ? (int) $text : 60;
    }
}
