<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp;

use Sikuwa\Whatsapp\Support\Pacing;
use Sikuwa\Whatsapp\Support\Throttle;
use Sikuwa\Whatsapp\Support\Typing;

/**
 * Sumber konfigurasi tunggal untuk seluruh provider.
 *
 * SDK ini tidak terikat framework. Nilai yang tidak diberikan secara eksplisit
 * dicari lewat {@see Config::env()}, yang urutannya:
 *
 * 1. Resolver yang dipasang lewat {@see Config::useResolver()} — jalur untuk
 *    aplikasi yang menyimpan konfigurasi di tempat lain.
 * 2. Helper global `env()` kalau ada (CodeIgniter 4, Laravel) — sehingga SDK
 *    ini bisa dipasang apa adanya ke aplikasi CodeIgniter tanpa adapter.
 * 3. `$_ENV` / `$_SERVER` / `getenv()`.
 *
 * Kunci yang dikenali: `WA_NOTIFICATION`, `WHATSAPP_PROVIDER`,
 * `WHATSAPP_TOKEN`, `WHATSAPP_TOKEN_<Provider>`, `WHATSAPP_URL`,
 * `WHATSAPP_URL_<Provider>`, `WHATSAPP_SESSION`, `WHATSAPP_SESSION_<Provider>`,
 * `WHATSAPP_INSTANCE`, `WHATSAPP_INSTANCE_<Provider>`,
 * `WHATSAPP_ACCOUNT_TOKEN`, `WHATSAPP_TIMEOUT`, `WHATSAPP_PACING_CYCLE`,
 * `WHATSAPP_PACING_INTERVAL`, `WHATSAPP_PACING_LONG_CHARS`,
 * `WHATSAPP_PACING_LONG_FACTOR`, `WHATSAPP_TYPING`, `WHATSAPP_TYPING_SPEED`,
 * `WHATSAPP_TYPING_MIN`, `WHATSAPP_TYPING_MAX`, `WHATSAPP_THROTTLE_MAX`,
 * `WHATSAPP_THROTTLE_WINDOW`.
 */
final class Config
{
    public const DEFAULT_TIMEOUT = 10.0;

    /** Batas atas timeout, dalam detik. */
    public const MAX_TIMEOUT = 60.0;

    /** @var (callable(string):(string|null))|null */
    private static $resolver = null;

    /**
     * @param array<string,string> $tokens Token per provider, mis. ['Fonnte' => 'xxx'].
     *                                     Menang atas `WHATSAPP_TOKEN_<Provider>`.
     * @param array<string,string> $headers Header tambahan untuk setiap request.
     * @param array<string,string> $urls URL per provider, mis. ['OpenWA' => 'https://wa.internal'].
     *                                   Menang atas `WHATSAPP_URL_<Provider>`.
     * @param string|null $session Id session eksplisit — lihat
     *                             {@see self::session()}. Kunci
     *                             `WHATSAPP_SESSION_<Provider>` tetap menang atasnya.
     * @param string|null $instance Id instance eksplisit — lihat
     *                              {@see self::instance()}. Kunci
     *                              `WHATSAPP_INSTANCE_<Provider>` tetap menang atasnya.
     * @param string|null $accountToken Token akun, khusus Fonnte Device API —
     *                                  lihat {@see self::accountToken()}.
     * @param Pacing|null $pacing Pengatur jeda antar pesan saat mengirim
     *                            beruntun — lihat {@see self::pacing()}.
     * @param Typing|null $typing Pengatur indikator "sedang mengetik" yang
     *                            dimunculkan sendiri sebelum mengirim — lihat
     *                            {@see self::typing()}.
     */
    public function __construct(
        private ?string $token = null,
        private ?string $url = null,
        private ?string $session = null,
        private ?string $instance = null,
        private ?float $timeout = null,
        private array $tokens = [],
        private ?string $provider = null,
        private array $headers = [],
        private array $urls = [],
        private ?string $accountToken = null,
        private ?Pacing $pacing = null,
        private ?Typing $typing = null,
        private ?Throttle $throttle = null,
        private ?int $retries = null
    ) {
    }

    /**
     * Terima array opsi, instance Config, atau null.
     *
     * @param array{
     *     token?:string, url?:string, session?:string, instance?:string,
     *     timeout?:int|float, tokens?:array<string,string>, provider?:string,
     *     headers?:array<string,string>, urls?:array<string,string>,
     *     account_token?:string,
     *     pacing?:array{
     *         cycle?:string|array<int,mixed>,
     *         interval?:string|int|array<int,mixed>,
     *         long_chars?:string|int,
     *         long_factor?:string|int
     *     }|Pacing,
     *     typing?:array{
     *         enabled?:mixed, speed?:string|int, min?:string|int, max?:string|int
     *     }|Typing,
     *     throttle?:array{max?:string|int, window?:string|int}|Throttle,
     *     retries?:string|int
     * }|Config|null $options
     */
    public static function from(array|Config|null $options): self
    {
        if ($options instanceof self) {
            return $options;
        }

        if ($options === null) {
            return new self();
        }

        return new self(
            token: $options['token'] ?? null,
            url: $options['url'] ?? null,
            session: $options['session'] ?? null,
            instance: $options['instance'] ?? null,
            timeout: isset($options['timeout']) ? (float) $options['timeout'] : null,
            tokens: $options['tokens'] ?? [],
            provider: $options['provider'] ?? null,
            headers: $options['headers'] ?? [],
            urls: $options['urls'] ?? [],
            accountToken: $options['account_token'] ?? null,
            pacing: self::pacingOption($options['pacing'] ?? null),
            typing: self::typingOption($options['typing'] ?? null),
            throttle: self::throttleOption($options['throttle'] ?? null),
            retries: isset($options['retries']) ? (int) $options['retries'] : null,
        );
    }

    /** Bangun Config murni dari environment. */
    public static function fromEnvironment(): self
    {
        return new self(
            token: self::env('WHATSAPP_TOKEN'),
            url: self::env('WHATSAPP_URL'),
            session: self::env('WHATSAPP_SESSION'),
            instance: self::env('WHATSAPP_INSTANCE'),
            timeout: self::env('WHATSAPP_TIMEOUT') === null ? null : (float) self::env('WHATSAPP_TIMEOUT'),
            provider: self::env('WHATSAPP_PROVIDER'),
            accountToken: self::env('WHATSAPP_ACCOUNT_TOKEN'),
            pacing: self::pacingFromEnv(),
            typing: self::typingFromEnv(),
            throttle: self::throttleFromEnv(),
            retries: self::retriesFromEnv(),
        );
    }

    /**
     * Pacing dari environment.
     *
     * Dikumpulkan di satu tempat supaya dua jalur yang memakainya —
     * {@see self::fromEnvironment()} dan {@see self::pacing()} — tidak bisa
     * berbeda diam-diam saat kuncinya bertambah.
     */
    private static function pacingFromEnv(): Pacing
    {
        return Pacing::fromConfig(
            self::env('WHATSAPP_PACING_CYCLE'),
            self::env('WHATSAPP_PACING_INTERVAL'),
            self::env('WHATSAPP_PACING_LONG_CHARS'),
            self::env('WHATSAPP_PACING_LONG_FACTOR')
        );
    }

    /**
     * Terima opsi `pacing` dalam bentuk objek jadi maupun array mentah.
     *
     * @return Pacing|null Null bila pemanggil tidak mengirim apa pun, supaya
     *                     {@see self::pacing()} masih bisa jatuh ke environment.
     */
    private static function pacingOption(mixed $value): ?Pacing
    {
        if ($value instanceof Pacing) {
            return $value;
        }

        return \is_array($value) ? Pacing::fromArray($value) : null;
    }

    /**
     * Typing dari environment.
     *
     * Sama seperti {@see self::pacingFromEnv()}: dikumpulkan di satu tempat
     * supaya dua jalur yang memakainya tidak bisa berbeda diam-diam saat
     * kuncinya bertambah.
     */
    private static function typingFromEnv(): Typing
    {
        return Typing::fromConfig(
            self::env('WHATSAPP_TYPING'),
            self::env('WHATSAPP_TYPING_SPEED'),
            self::env('WHATSAPP_TYPING_MIN'),
            self::env('WHATSAPP_TYPING_MAX')
        );
    }

    /**
     * Terima opsi `typing` dalam bentuk objek jadi maupun array mentah.
     *
     * @return Typing|null Null bila pemanggil tidak mengirim apa pun, supaya
     *                     {@see self::typing()} masih bisa jatuh ke environment.
     */
    private static function typingOption(mixed $value): ?Typing
    {
        if ($value instanceof Typing) {
            return $value;
        }

        return \is_array($value) ? Typing::fromArray($value) : null;
    }

    /**
     * Throttle dari environment.
     *
     * Dikumpulkan di satu tempat seperti {@see self::pacingFromEnv()}, supaya
     * dua jalur yang memakainya tidak bisa berbeda diam-diam saat kuncinya
     * bertambah.
     */
    private static function throttleFromEnv(): Throttle
    {
        return Throttle::fromConfig(
            self::env('WHATSAPP_THROTTLE_MAX'),
            self::env('WHATSAPP_THROTTLE_WINDOW')
        );
    }

    /**
     * Terima opsi `throttle` dalam bentuk objek jadi maupun array mentah.
     *
     * @return Throttle|null Null bila pemanggil tidak mengirim apa pun, supaya
     *                       {@see self::throttle()} masih bisa jatuh ke environment.
     */
    private static function throttleOption(mixed $value): ?Throttle
    {
        if ($value instanceof Throttle) {
            return $value;
        }

        return \is_array($value) ? Throttle::fromArray($value) : null;
    }

    /** Jumlah percobaan ulang otomatis untuk kegagalan yang aman diulang. */
    private static function retriesFromEnv(): ?int
    {
        $value = self::env('WHATSAPP_RETRIES');

        return $value === null || ! is_numeric(trim($value)) ? null : (int) trim($value);
    }

    /**
     * Pasang resolver environment sendiri. Kirim null untuk kembali ke deteksi
     * otomatis. Utamanya dipakai di test supaya tidak menyentuh environment
     * proses.
     *
     * @param (callable(string):(string|null))|null $resolver
     */
    public static function useResolver(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /** Baca satu nilai environment. Mengembalikan null untuk nilai kosong. */
    public static function env(string $key): ?string
    {
        if (self::$resolver !== null) {
            $value = (self::$resolver)($key);
        } elseif (\function_exists('env')) {
            $value = \env($key);
        } else {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? \getenv($key);
        }

        if ($value === null || $value === false || \is_array($value)) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    /**
     * Token khusus satu provider: `tokens[<Provider>]`, lalu
     * `WHATSAPP_TOKEN_<Provider>`. Tidak pernah jatuh ke `WHATSAPP_TOKEN`.
     *
     * Dipakai untuk mendeteksi provider mana saja yang benar-benar
     * dikonfigurasi, supaya mode `auto` tidak mengundi gateway tanpa token.
     */
    public function providerToken(string $provider): ?string
    {
        $specific = $this->tokens[$provider] ?? self::env("WHATSAPP_TOKEN_{$provider}");

        return ($specific === null || $specific === '') ? null : $specific;
    }

    /**
     * URL khusus satu provider: `urls[<Provider>]`, lalu
     * `WHATSAPP_URL_<Provider>`. Tidak pernah jatuh ke `WHATSAPP_URL`.
     *
     * Inilah yang membuat beberapa gateway self-hosted bisa dikonfigurasi
     * bersamaan: `WHATSAPP_URL` hanya punya satu nilai, jadi mengisinya untuk
     * OpenWA akan ikut terpakai Wuzapi. Kunci per-provider tidak punya
     * ambiguitas itu.
     */
    public function providerUrl(string $provider): ?string
    {
        $specific = $this->urls[$provider] ?? self::env("WHATSAPP_URL_{$provider}");

        return ($specific === null || $specific === '') ? null : $specific;
    }

    /** Token efektif: token khusus provider, lalu token umum, lalu environment. */
    public function token(?string $provider = null): string
    {
        if ($provider !== null) {
            $specific = $this->providerToken($provider);

            if ($specific !== null) {
                return $specific;
            }
        }

        if ($this->token !== null && $this->token !== '') {
            return $this->token;
        }

        return self::env('WHATSAPP_TOKEN') ?? '';
    }

    /**
     * URL dasar. Urutannya: URL khusus provider, lalu nilai eksplisit, lalu
     * `WHATSAPP_URL`, lalu default provider.
     *
     * Kunci per-provider didahulukan atas nilai eksplisit supaya
     * `WHATSAPP_URL_<Provider>` menang atas `WHATSAPP_URL` — sama seperti
     * {@see providerToken()} yang menang atas token umum.
     */
    public function url(string $default = '', ?string $provider = null): string
    {
        $url = ($provider !== null ? $this->providerUrl($provider) : null)
            ?? $this->url
            ?? self::env('WHATSAPP_URL');

        return rtrim(($url === null || $url === '') ? $default : $url, '/');
    }

    /**
     * URL yang diberikan eksplisit saja, tanpa melihat environment.
     *
     * Dipakai provider dengan endpoint tetap (Fonnte): membaca `WHATSAPP_URL`
     * di sana berbahaya, karena satu nilai yang ditujukan untuk gateway
     * self-hosted akan mengalihkan pengiriman ke host yang salah.
     */
    public function explicitUrl(): ?string
    {
        return ($this->url === null || $this->url === '') ? null : $this->url;
    }

    /**
     * Id session khusus satu provider: `WHATSAPP_SESSION_<Provider>`.
     *
     * Sama seperti {@see providerToken()} dan {@see providerUrl()}: kunci ini
     * tidak pernah jatuh ke `WHATSAPP_SESSION`. Inilah yang membuat OpenWA,
     * Wwebjs, dan Waxum bisa dikonfigurasi bersamaan — masing-masing bisa
     * memakai nama sesinya sendiri.
     *
     * Nama provider ditulis apa adanya (`WHATSAPP_SESSION_OpenWA`), mengikuti
     * kunci per-provider yang sudah ada, bukan diubah menjadi huruf besar.
     */
    public function providerSession(string $provider): ?string
    {
        $specific = self::env("WHATSAPP_SESSION_{$provider}");

        return ($specific === null || $specific === '') ? null : $specific;
    }

    /**
     * Id/nama instance khusus satu provider: `WHATSAPP_INSTANCE_<Provider>`.
     *
     * Seperti {@see providerSession()}, kunci ini tidak pernah jatuh ke
     * `WHATSAPP_INSTANCE`, sehingga ApiMe dan Evolution API bisa punya
     * instance yang berbeda dalam satu aplikasi.
     */
    public function providerInstance(string $provider): ?string
    {
        $specific = self::env("WHATSAPP_INSTANCE_{$provider}");

        return ($specific === null || $specific === '') ? null : $specific;
    }

    /**
     * Id session efektif. Urutannya: kunci khusus provider, lalu nilai
     * eksplisit, lalu `WHATSAPP_SESSION`.
     *
     * Dipakai OpenWA, Wwebjs, dan Waxum. Provider yang tidak memakai session
     * (Fonnte, ApiMe, Evolution API, wuzapi) tetap bisa memanggil ini tanpa
     * akibat apa pun — nilainya memang tidak dipakai di sana.
     */
    public function session(?string $provider = null): string
    {
        $specific = $provider !== null ? $this->providerSession($provider) : null;

        return $specific
            ?? (($this->session === null || $this->session === '') ? null : $this->session)
            ?? self::env('WHATSAPP_SESSION')
            ?? '';
    }

    /**
     * Id/nama instance efektif. Urutannya: kunci khusus provider, lalu nilai
     * eksplisit, lalu `WHATSAPP_INSTANCE`.
     *
     * Dipakai ApiMe dan Evolution API.
     */
    public function instance(?string $provider = null): string
    {
        $specific = $provider !== null ? $this->providerInstance($provider) : null;

        return $specific
            ?? (($this->instance === null || $this->instance === '') ? null : $this->instance)
            ?? self::env('WHATSAPP_INSTANCE')
            ?? '';
    }

    /**
     * Token akun — hanya Fonnte, untuk Device API-nya.
     *
     * Sengaja terpisah dari {@see token()} karena keduanya kredensial yang
     * berbeda: token perangkat dipakai mengirim pesan, sedangkan menambah dan
     * membaca daftar perangkat menuntut token akun. Menukar keduanya hanya
     * menghasilkan `"unknown user"`.
     */
    public function accountToken(): string
    {
        return $this->accountToken ?? self::env('WHATSAPP_ACCOUNT_TOKEN') ?? '';
    }

    /**
     * Pengatur jeda antar pesan saat mengirim beruntun.
     *
     * Berbeda dari kunci lain di kelas ini, bawaannya adalah **mati**: selama
     * `WHATSAPP_PACING_CYCLE` dan `WHATSAPP_PACING_INTERVAL` kosong, jeda
     * diserahkan sepenuhnya ke tiap gateway seperti sebelum fitur ini ada.
     * Pacing yang aktif tapi salah nilainya akan menahan proses pemanggil
     * selama berjam-jam, jadi ia harus dinyalakan dengan sengaja.
     *
     * Nilai eksplisit di konstruktor menang utuh atas environment — tidak
     * digabung sebagian, sama seperti `token` dan `url`.
     */
    public function pacing(): Pacing
    {
        return $this->pacing ?? self::pacingFromEnv();
    }

    /**
     * Pengatur indikator "sedang mengetik" yang dimunculkan sebelum mengirim.
     *
     * Sama seperti {@see self::pacing()}, bawaannya **mati**: selama
     * `WHATSAPP_TYPING` kosong, tiap gateway mengirim pesan begitu saja seperti
     * sebelum fitur ini ada. Fitur ini menyisipkan request tambahan ke jalur
     * kirim dan menahan pemanggil selama durasinya, jadi ia harus dinyalakan
     * dengan sengaja.
     *
     * Nilai eksplisit di konstruktor menang utuh atas environment — tidak
     * digabung sebagian, sama seperti `token` dan `url`.
     */
    public function typing(): Typing
    {
        return $this->typing ?? self::typingFromEnv();
    }

    /**
     * Pembatas laju pengiriman: berapa pesan boleh keluar per jendela waktu.
     *
     * Melengkapi {@see self::pacing()}, yang hanya mengatur jeda **antar** pesan
     * di dalam satu batch. Pacing tidak melihat aplikasi yang mengirim satu
     * pesan per request — dan justru pola itulah yang paling sering dipakai,
     * sekaligus yang paling mudah menembak terlalu cepat tanpa disadari.
     *
     * Sama seperti pacing dan typing, bawaannya **mati**: selama
     * `WHATSAPP_THROTTLE_MAX` kosong, tidak ada yang berubah dari sebelumnya.
     * Pembatas yang aktif tapi salah nilainya akan menahan pemanggil, jadi ia
     * harus dinyalakan dengan sengaja.
     *
     * Nilai eksplisit di konstruktor menang utuh atas environment — tidak
     * digabung sebagian, sama seperti `token` dan `url`.
     */
    public function throttle(): Throttle
    {
        return $this->throttle ?? self::throttleFromEnv();
    }

    /**
     * Berapa kali SDK boleh mencoba ulang sendiri saat gateway menolak dengan
     * 429 atau 503.
     *
     * Hanya dua status itu yang diulang, dan hanya bila gateway menyebut
     * `Retry-After`: status lain tidak akan sembuh kalau diulang (token salah,
     * nomor tidak terdaftar), dan mengulang tanpa tahu berapa lama harus
     * menunggu hanya menambah beban di dinding yang sama.
     *
     * Bawaannya **0** — tanpa percobaan ulang otomatis. Mengulang sendiri
     * berarti menahan proses pemanggil lebih lama, jadi ia harus dinyalakan
     * dengan sengaja, sama seperti pacing dan throttle.
     */
    public function retries(): int
    {
        $configured = $this->retries ?? self::retriesFromEnv();

        return $configured === null ? 0 : max(0, $configured);
    }

    /**
     * Timeout request dalam detik. Nilai di luar 1–60 diabaikan supaya salah
     * tulis di .env tidak membuat request menggantung selamanya.
     */
    public function timeout(): float
    {
        $configured = $this->timeout ?? (float) (self::env('WHATSAPP_TIMEOUT') ?? 0);

        return ($configured >= 1.0 && $configured <= self::MAX_TIMEOUT)
            ? $configured
            : self::DEFAULT_TIMEOUT;
    }

    /** Provider terpilih dari konfigurasi; `auto` berarti undi di antara yang siap. */
    public function provider(): ?string
    {
        return $this->provider ?? self::env('WHATSAPP_PROVIDER');
    }

    /**
     * Apakah notifikasi WhatsApp diaktifkan (`WA_NOTIFICATION`).
     *
     * Bawaannya true kalau kuncinya tidak diisi, supaya SDK tidak diam-diam
     * mematikan diri sendiri hanya karena sebuah variabel lupa ditulis.
     *
     * SDK **tidak pernah** menegakkan nilai ini — SDK tidak tahu kapan
     * notifikasi pantas dikirim. Nilainya disediakan supaya pemanggil tidak
     * perlu mengurai environment sendiri.
     */
    public static function notificationEnabled(): bool
    {
        $value = self::env('WA_NOTIFICATION');

        return $value === null ? true : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** Salinan dengan URL berbeda, tanpa mengubah instance asal. */
    public function withUrl(?string $url): self
    {
        if ($url === null || $url === '') {
            return $this;
        }

        $clone = clone $this;
        $clone->url = $url;

        return $clone;
    }
}
