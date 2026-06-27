<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Controller;

use Genaker\Bundle\ComiVoyager\Service\ConnectionTesterService;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * POST /comivoyager/admin/test-connection/{provider}
 *
 * Backs the "Test Connection" buttons next to the OSRM Base URL and Google
 * API Key fields in System Configuration > Integrations > ComiVoyager Route
 * Optimizer.
 *
 * Body: { "value": "<the value currently in the field>" }
 * Response: { "success": bool, "message": "..." }
 */
class ConnectionTestController extends AbstractController
{
    public function __construct(
        private readonly ConnectionTesterService $connectionTester,
    ) {
    }

    #[AclAncestor('oro_config_system')]
    #[CsrfProtection]
    public function testAction(Request $request, string $provider): JsonResponse
    {
        $value = $request->request->get('value');
        $value = is_string($value) ? $value : null;

        $result = match ($provider) {
            'osrm' => $this->connectionTester->testOsrm($value),
            'google' => $this->connectionTester->testGoogle($value),
            default => ['success' => false, 'message' => sprintf('Unknown connection test "%s".', $provider)],
        };

        return $this->json($result);
    }
}
