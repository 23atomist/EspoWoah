<?php
/************************************************************************
 * This file is part of EspoMcp — an EspoCRM module.
 *
 * EspoMcp – MCP server as an EspoCRM extension.
 * Copyright (C) 2026 Thomas Gallaway
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 ************************************************************************/

namespace Espo\Modules\EspoMcp\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\InjectableFactory;
use Espo\Modules\EspoMcp\Tools\Mcp\McpService;

/**
 * GET /api/v1/mcp — endpoint discovery info.
 */
class GetMcp implements Action
{
    public function __construct(private InjectableFactory $injectableFactory)
    {}

    public function process(Request $request): Response
    {
        /** @var McpService $service */
        $service = $this->injectableFactory->create(McpService::class);

        $service->setRequestContext($request);

        return $service->processGet($request);
    }
}