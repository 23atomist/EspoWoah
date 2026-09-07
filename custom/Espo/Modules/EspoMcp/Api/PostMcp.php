<?php
/************************************************************************
 * This file is part of EspoMcp — an EspoCRM module.
 *
 * EspoMcp – MCP server as an EspoCRM extension.
 * Licensed under the MIT License.
 ************************************************************************/

namespace Espo\Modules\EspoMcp\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\InjectableFactory;
use Espo\Modules\EspoMcp\Tools\Mcp\McpService;

/**
 * POST /api/v1/mcp — the MCP JSON-RPC endpoint.
 */
class PostMcp implements Action
{
    public function __construct(private InjectableFactory $injectableFactory)
    {}

    public function process(Request $request): Response
    {
        /** @var McpService $service */
        $service = $this->injectableFactory->create(McpService::class);

        $service->setRequestContext($request);

        return $service->processPost($request, ResponseComposer::empty());
    }
}