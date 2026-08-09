<?php

declare(strict_types=1);

namespace Anokii\Identity;

/** Canonical permissions owned by the Identity capability. */
final class IdentityPermissions
{
    public const string EDIT = 'edit identity';
    public const string ADMINISTER = 'administer identity';

    private function __construct() {}
}
