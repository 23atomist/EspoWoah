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

namespace Espo\Modules\EspoMcp\Tools\Mcp\Protocol;

/**
 * Protocol version negotiation and Accept-header inspection.
 *
 * This module implements the Streamable HTTP transport in its stateless
 * form: one POST in, one JSON object out. It does NOT implement the
 * HTTP+SSE transport that protocol version 2024-11-05 defines, which is
 * why that version is not offered here — a client negotiating it would
 * then expect a long-lived SSE stream this server will never open.
 *
 * Pure logic: no Espo dependencies, so it is unit-testable standalone.
 */
final class ProtocolVersion
{
    /** The newest revision this server implements. */
    public const string LATEST = '2025-06-18';

    /**
     * Revisions this server can actually serve, newest first.
     *
     * Both define the Streamable HTTP transport and both permit answering a
     * POSTed request with a single `application/json` response rather than
     * an SSE stream, which is the only mode implemented here.
     *
     * @var string[]
     */
    public const array SUPPORTED = [
        '2025-06-18',
        '2025-03-26',
    ];

    /**
     * What a client is assumed to be speaking when it sends no
     * `MCP-Protocol-Version` header, per the transport spec's backwards
     * compatibility rule.
     */
    public const string ASSUMED_WHEN_HEADER_ABSENT = '2025-03-26';

    public static function isSupported(string $version): bool
    {
        return in_array($version, self::SUPPORTED, true);
    }

    /**
     * Resolve the version to report from an `initialize` response.
     *
     * The client's requested version is echoed back when this server can
     * serve it. Otherwise the server answers with its own latest, and the
     * client decides whether to continue or disconnect.
     */
    public static function negotiate(mixed $requested): string
    {
        if (is_string($requested) && self::isSupported($requested)) {
            return $requested;
        }

        return self::LATEST;
    }

    /**
     * Whether an `MCP-Protocol-Version` header value may proceed.
     *
     * An absent header is acceptable — the spec says to assume
     * 2025-03-26 rather than reject. A header that is present but names a
     * revision this server cannot serve must be refused with 400.
     */
    public static function headerIsAcceptable(mixed $headerValue): bool
    {
        if ($headerValue === null || $headerValue === '') {
            return true;
        }

        return is_string($headerValue) && self::isSupported($headerValue);
    }

    /**
     * Whether an HTTP `Accept` header is asking for an SSE stream.
     *
     * A GET carrying this is a client trying to open a server-initiated
     * stream. This server has none, so it must answer 405 rather than
     * hand back an unrelated JSON body.
     */
    public static function wantsEventStream(mixed $acceptHeader): bool
    {
        if (!is_string($acceptHeader) || $acceptHeader === '') {
            return false;
        }

        foreach (explode(',', $acceptHeader) as $part) {
            // Strip any parameters, e.g. "text/event-stream;q=0.9".
            $mediaType = strtolower(trim(explode(';', $part, 2)[0]));

            if ($mediaType === 'text/event-stream') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    public static function supported(): array
    {
        return self::SUPPORTED;
    }
}
