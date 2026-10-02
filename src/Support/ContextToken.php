<?php

namespace Packstub\SessionReplay\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * What the server knew when it rendered the page — who is signed in, the
 * workspace, who is impersonating, the app's own properties — signed with
 * the app key and handed to the recorder, which sends it back with every
 * upload. The ingest endpoint trusts nothing else about identity, so it
 * needs no session, no cookie and no CSRF token, and a panel on its own
 * guard or a tenant in the URL is recorded correctly.
 */
class ContextToken
{
    /** ingest.token_days when the config does not say; a tab left open longer starts a new recording on its next page load. */
    public const DEFAULT_DAYS = 7;

    /** @param array<string, mixed> $properties */
    public function __construct(
        public ?string $userType = null,
        public ?string $userId = null,
        public ?string $tenantType = null,
        public ?string $tenantId = null,
        public ?string $impersonatorId = null,
        public array $properties = [],
        public int $issuedAt = 0,
        // Random per rendered page: what a guest's uploads are throttled by, since the client cannot choose it.
        public ?string $nonce = null,
        // privacy.anonymous: a keyed hash of the person, never stored; their uploads are limited per person and a
        // change of person still starts a new recording, while the recording names nobody.
        public ?string $pseudonym = null,
    ) {}

    /**
     * The same context with the person and the impersonator taken out (privacy.anonymous): the workspace and the
     * app's properties stay, the person becomes a pseudonym the server can limit uploads by and never stores.
     */
    public function withoutPerson(): self
    {
        if ($this->userId === null) {
            return $this;
        }

        $pseudonym = substr(hash_hmac('sha256', 'session-replay-person|'.$this->userType.'|'.$this->userId, self::key()), 0, 32);

        return new self(null, null, $this->tenantType, $this->tenantId, null, $this->properties, $this->issuedAt, $this->nonce, $pseudonym);
    }

    /** @param array<string, mixed> $properties */
    public static function for(?Model $user, ?Model $tenant = null, ?string $impersonatorId = null, array $properties = []): self
    {
        return new self(
            $user?->getMorphClass(),
            $user === null ? null : (string) $user->getKey(),
            $tenant?->getMorphClass(),
            $tenant === null ? null : (string) $tenant->getKey(),
            $impersonatorId,
            $properties,
            time(),
            bin2hex(random_bytes(8)),
        );
    }

    public function isGuest(): bool
    {
        return $this->userId === null;
    }

    /** Names nobody: no person, no workspace, no impersonator, no pseudonym. Only such a token may be exempt from expiry. */
    public function isAnonymous(): bool
    {
        return $this->userId === null && $this->tenantId === null && $this->impersonatorId === null && $this->pseudonym === null;
    }

    /** Nobody was signed in: neither a person nor a pseudonym for one. */
    public function isVisitor(): bool
    {
        return $this->userId === null && $this->pseudonym === null;
    }

    public function isExpired(): bool
    {
        if ($this->isAnonymous() && ! config('session-replay.ingest.guest_tokens_expire', true)) {
            return false;
        }

        $days = max(1, (int) config('session-replay.ingest.token_days', self::DEFAULT_DAYS));

        return $this->issuedAt < time() - $days * 24 * 60 * 60;
    }

    /** The same person (or the same "nobody") as the recording's first batch. */
    public function sameUserAs(?string $userType, ?string $userId): bool
    {
        return $this->userType === $userType && $this->userId === $userId;
    }

    /** The same impersonator (or none) as the recording's first batch. */
    public function sameImpersonatorAs(?string $impersonatorId): bool
    {
        return $this->impersonatorId === $impersonatorId;
    }

    /** What the ingest limits count against: the person, or for guests the page render the token came from. */
    public function throttleKey(): string
    {
        if ($this->pseudonym !== null) {
            return 'person:'.$this->pseudonym;
        }

        return $this->isGuest() ? 'guest:'.($this->nonce ?? 'none') : $this->userType.':'.$this->userId;
    }

    /** The same workspace (or the same "none") as the recording's first batch. */
    public function sameTenantAs(?string $tenantType, ?string $tenantId): bool
    {
        return $this->tenantType === $tenantType && $this->tenantId === $tenantId;
    }

    public function encode(): string
    {
        $payload = self::base64UrlEncode((string) json_encode([
            'u' => $this->isGuest() ? null : [$this->userType, $this->userId],
            't' => $this->tenantId === null ? null : [$this->tenantType, $this->tenantId],
            'i' => $this->impersonatorId,
            'p' => $this->properties,
            'iat' => $this->issuedAt,
            'n' => $this->nonce,
            'a' => $this->pseudonym,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload.'.'.self::sign($payload);
    }

    /** Null when the token is malformed, was not signed by this app, or is too old. */
    public static function decode(?string $token): ?self
    {
        if (! is_string($token) || substr_count($token, '.') !== 1) {
            return null;
        }

        [$payload, $signature] = explode('.', $token);

        if (! hash_equals(self::sign($payload), $signature)) {
            return null;
        }

        $data = json_decode((string) self::base64UrlDecode($payload), true);

        if (! is_array($data) || ! is_int($data['iat'] ?? null)) {
            return null;
        }

        $token = new self(
            isset($data['u'][0]) ? (string) $data['u'][0] : null,
            isset($data['u'][1]) ? (string) $data['u'][1] : null,
            isset($data['t'][0]) ? (string) $data['t'][0] : null,
            isset($data['t'][1]) ? (string) $data['t'][1] : null,
            isset($data['i']) ? (string) $data['i'] : null,
            is_array($data['p'] ?? null) ? $data['p'] : [],
            $data['iat'],
            isset($data['n']) ? (string) $data['n'] : null,
            isset($data['a']) ? (string) $data['a'] : null,
        );

        return $token->isExpired() ? null : $token;
    }

    protected static function sign(string $payload): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', 'session-replay|'.$payload, self::key(), true));
    }

    protected static function key(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }

    protected static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected static function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
