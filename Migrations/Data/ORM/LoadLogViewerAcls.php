<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Migrations\Data\ORM;

use Oro\Bundle\SecurityBundle\Migrations\Data\ORM\AbstractLoadAclData;

/**
 * Grants log viewer action ACLs to ROLE_ADMINISTRATOR by default.
 */
class LoadLogViewerAcls extends AbstractLoadAclData
{
    #[\Override]
    protected function getDataPath(): string
    {
        return '';
    }

    #[\Override]
    protected function getAclData(): array
    {
        return [
            'ROLE_ADMINISTRATOR' => [
                'permissions' => [
                    'action|genaker_log_viewer_index'    => ['EXECUTE'],
                    'action|genaker_log_viewer_truncate' => ['EXECUTE'],
                ],
            ],
        ];
    }
}
