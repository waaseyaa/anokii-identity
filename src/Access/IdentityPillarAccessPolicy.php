<?php

declare(strict_types=1);

namespace Anokii\Identity\Access;

use Anokii\Core\Access\AbstractEntityAccessPolicy;
use Anokii\Identity\IdentityPermissions;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Access posture for Identity Workspace pillars (identity_pillar).
 *
 * Standard workspace shape (via the shared Anokii base): any signed-in account
 * may read; create/update require `edit identity`; delete requires
 * `administer identity`; anonymous and everything else fails closed.
 *
 * @api
 */
#[PolicyAttribute(entityType: 'identity_pillar')]
final class IdentityPillarAccessPolicy extends AbstractEntityAccessPolicy
{
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        $principal = $this->claims($account);
        if ($this->appliesTo($entity->getEntityTypeId())
            && $principal !== null
            && ($principal->communityId() === null || trim($principal->communityId()) === '')
        ) {
            return AccessResult::forbidden('Identity access requires an active principal community.');
        }

        return parent::access($entity, $operation, $account);
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        $principal = $this->claims($account);
        if ($this->appliesTo($entityTypeId)
            && $principal !== null
            && ($principal->communityId() === null || trim($principal->communityId()) === '')
        ) {
            return AccessResult::forbidden('Identity pillar creation requires an active principal community.');
        }

        return parent::createAccess($entityTypeId, $bundle, $account);
    }

    protected function entityTypeId(): string
    {
        return 'identity_pillar';
    }

    protected function editPermission(): string
    {
        return IdentityPermissions::EDIT;
    }

    protected function administerPermission(): string
    {
        return IdentityPermissions::ADMINISTER;
    }

}
