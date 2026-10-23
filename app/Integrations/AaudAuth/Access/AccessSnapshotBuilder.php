<?php

namespace App\Integrations\AaudAuth\Access;

use App\Integrations\AaudAuth\Exceptions\InvalidAccessSnapshotException;
use App\Models\Role;
use App\Models\User;

final class AccessSnapshotBuilder
{
    public function build(User $user): ?AccessSnapshot
    {
        if (! $user->exists || $user->getKey() === null) {
            throw new InvalidAccessSnapshotException('invalid_user');
        }
        // Ignore cached relationships and unsaved administrative changes. No transaction or writes.
        $current = User::query()->with(['ssoIdentity', 'roles'])->find($user->getKey());
        if (! $current) {
            throw new InvalidAccessSnapshotException('invalid_user');
        }
        $identity = $current->ssoIdentity;
        if (! $identity) {
            return null; // Never infer a link from email, username or name.
        }
        if ((string) $identity->user_id !== (string) $current->getKey()) {
            throw new InvalidAccessSnapshotException('incomplete_identity');
        }

        return $this->fromState((string) $current->getKey(), $current->getAttributes()['is_active'] ?? null,
            $identity->only(['issuer', 'subject']), $current->roles);
    }

    /** Pure transformation, also usable with an explicitly captured consistent local state. */
    public function fromState(string $localId, mixed $administrativelyActive, ?array $identity, iterable $roles): ?AccessSnapshot
    {
        if ($identity === null) {
            return null;
        }
        if (! in_array($administrativelyActive, [true, false, 1, 0, '1', '0'], true)) {
            throw new InvalidAccessSnapshotException('invalid_administrative_state');
        }
        $active = in_array($administrativelyActive, [true, 1, '1'], true);
        $codes = [];
        if ($active) {
            foreach ($roles as $role) {
                if (! $role instanceof Role || $role->guard_name !== 'web') {
                    throw new InvalidAccessSnapshotException('invalid_role_guard');
                }
                if (! in_array($role->getAttributes()['is_active'] ?? null, [true, 1, '1'], true)) {
                    throw new InvalidAccessSnapshotException('inactive_role');
                }
                $codes[] = $role->code;
            }
        }

        return AccessSnapshot::fromArray(['application_user_id' => $localId, 'issuer' => $identity['issuer'] ?? null,
            'subject' => $identity['subject'] ?? null, 'is_active' => $active, 'roles' => $codes]);
    }

    /** The caller must supply the old owner ID and old issuer/subject, even after unlink/delete. */
    public function revocation(#[\SensitiveParameter] array $previousAssociation): AccessSnapshot
    {
        if (isset($previousAssociation['application_code']) && $previousAssociation['application_code'] !== 'pazysalvo') {
            throw new InvalidAccessSnapshotException('invalid_application');
        }

        return AccessSnapshot::fromArray(['application_user_id' => $previousAssociation['application_user_id'] ?? null,
            'issuer' => $previousAssociation['issuer'] ?? null, 'subject' => $previousAssociation['subject'] ?? null,
            'is_active' => false, 'roles' => []]);
    }
}
