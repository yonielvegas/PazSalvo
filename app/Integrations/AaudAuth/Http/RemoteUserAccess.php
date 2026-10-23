<?php

namespace App\Integrations\AaudAuth\Http;

use App\Integrations\AaudAuth\Access\AccessSnapshot;
use App\Integrations\AaudAuth\Exceptions\AaudAuthRequestException;

/** Validated GET evidence; absence is explicit, never inferred from an HTTP error. */
final readonly class RemoteUserAccess
{
    private function __construct(public array $data) {}

    public static function fromResponse(array $data, AccessSnapshot $requested): self
    {
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['application_code', 'application_user_id', 'exists', 'is_active', 'issuer', 'roles', 'subject', 'version']
            || $data['application_code'] !== 'pazysalvo' || $data['issuer'] !== $requested->issuer || $data['subject'] !== $requested->subject
            || ! is_bool($data['exists']) || ! is_bool($data['is_active']) || ! is_int($data['version'])
            || $data['version'] < 0 || $data['version'] > 9007199254740991
            || ! is_array($data['roles']) || ! array_is_list($data['roles']) || count($data['roles']) > 50) {
            throw new AaudAuthRequestException('invalid_response', 200);
        }
        $seen = [];
        foreach ($data['roles'] as $code) {
            if (! is_string($code) || ! preg_match('/\A[a-z0-9][a-z0-9_.:-]{0,79}\z/D', $code) || isset($seen[$code])) {
                throw new AaudAuthRequestException('invalid_response', 200);
            }
            $seen[$code] = true;
        }
        if (! $data['exists']) {
            if ($data['version'] !== 0 || $data['application_user_id'] !== null || $data['is_active'] || $data['roles'] !== []) {
                throw new AaudAuthRequestException('invalid_response', 200);
            }
        } elseif ($data['version'] < 1 || ! is_string($data['application_user_id'])
            || ! preg_match('/\A[1-9][0-9]{0,127}\z/D', $data['application_user_id']) || (! $data['is_active'] && $data['roles'] !== [])) {
            throw new AaudAuthRequestException('invalid_response', 200);
        }
        sort($data['roles'], SORT_STRING);

        return new self($data);
    }

    /** Local single-role persistence cannot silently collapse a multi-role remote assignment. */
    public function snapshotFor(string $owner): AccessSnapshot
    {
        if ($this->data['exists'] && ($this->data['application_user_id'] !== $owner
            || count($this->data['roles']) !== ($this->data['is_active'] ? 1 : 0))) {
            throw new AaudAuthRequestException('conflict', 409, 'application_identity_conflict');
        }

        return AccessSnapshot::fromArray(['issuer' => $this->data['issuer'], 'subject' => $this->data['subject'],
            'application_user_id' => $owner, 'is_active' => $this->data['is_active'], 'roles' => $this->data['roles']]);
    }
}
