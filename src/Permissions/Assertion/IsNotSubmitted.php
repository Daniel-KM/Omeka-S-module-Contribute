<?php declare(strict_types=1);

namespace Contribute\Permissions\Assertion;

use Contribute\Entity\Contribution;
use Laminas\Permissions\Acl\Acl;
use Laminas\Permissions\Acl\Assertion\AssertionInterface;
use Laminas\Permissions\Acl\Resource\ResourceInterface;
use Laminas\Permissions\Acl\Role\RoleInterface;

class IsNotSubmitted implements AssertionInterface
{
    public function assert(
        Acl $acl,
        RoleInterface $role = null,
        ResourceInterface $resource = null,
        $privilege = null
    ) {
        if (!$resource instanceof Contribution) {
            return false;
        }
        return !$resource->getSubmitted();
    }
}
