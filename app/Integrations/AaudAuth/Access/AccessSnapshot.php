<?php

namespace App\Integrations\AaudAuth\Access;

use App\Integrations\AaudAuth\Exceptions\InvalidAccessSnapshotException;

/** Desired state only: no version, operation UUID, credentials or institutional profile. */
final readonly class AccessSnapshot
{
    public string $applicationCode;

    private function __construct(
        public string $applicationUserId,
        public string $issuer,
        public string $subject,
        public bool $isActive,
        public array $roles,
    ) {
        $this->applicationCode = 'pazysalvo';
    }

    public static function fromArray(#[\SensitiveParameter] array $state): self
    {
        $keys = array_keys($state);
        sort($keys);
        if ($keys !== ['application_user_id', 'is_active', 'issuer', 'roles', 'subject']) {
            throw new InvalidAccessSnapshotException('invalid_fields');
        }
        $configured = config('aaud-auth.issuer');
        $issuer = $state['issuer'];
        $parts = is_string($configured) && strlen($configured) <= 255 && filter_var($configured, FILTER_VALIDATE_URL) ? parse_url($configured) : false;
        $localHttp = is_array($parts) && ($parts['scheme'] ?? '') === 'http'
            && in_array(config('app.env'), ['local', 'testing'], true)
            && in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true);
        if (! is_string($issuer) || $issuer === '' || $issuer !== $configured || ! is_array($parts)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (! $localHttp && ($parts['scheme'] ?? '') !== 'https')
            || ! preg_match('~/realms/[A-Za-z0-9_-]+$~D', $parts['path'] ?? '')) {
            throw new InvalidAccessSnapshotException('invalid_issuer');
        }
        if (! is_string($state['subject']) || ! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/D', $state['subject'])) {
            throw new InvalidAccessSnapshotException('invalid_subject');
        }
        if (! is_string($state['application_user_id']) || ! preg_match('/\A[1-9][0-9]{0,127}\z/D', $state['application_user_id'])) {
            throw new InvalidAccessSnapshotException('invalid_local_id');
        }
        if (! is_bool($state['is_active']) || ! is_array($state['roles']) || ! array_is_list($state['roles'])
            || count($state['roles']) !== ($state['is_active'] ? 1 : 0)) {
            throw new InvalidAccessSnapshotException('invalid_state');
        }
        foreach ($state['roles'] as $code) {
            if (! is_string($code) || ! preg_match('/\A[a-z0-9][a-z0-9_.:-]{0,79}\z/D', $code)) {
                throw new InvalidAccessSnapshotException('invalid_role_code');
            }
        }

        return new self($state['application_user_id'], $issuer, $state['subject'], $state['is_active'], $state['roles']);
    }

    /** Subject is routing metadata; the HTTP client removes it from the JSON body. */
    public function payload(): array
    {
        return ['issuer' => $this->issuer, 'subject' => $this->subject, 'application_user_id' => $this->applicationUserId,
            'is_active' => $this->isActive, 'roles' => $this->roles];
    }
}
