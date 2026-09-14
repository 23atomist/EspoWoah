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

namespace Espo\Modules\EspoMcp\Tools\Mcp\Auth;

/**
 * Minimal DER/ASN.1 helpers for converting a raw ECDSA P-256
 * (r, s) signature pair into the DER-encoded form expected by OpenSSL.
 */
class ASN1
{
    public static function sequence(string $content): string
    {
        return self::encodeLength(self::tag(0x30), $content);
    }

    public static function integer(string $base64UrlValue): string
    {
        return self::integerRaw(self::base64UrlDecode($base64UrlValue));
    }

    public static function integerRaw(string $raw): string
    {
        // DER INTEGER: strip leading zero bytes, then pad when the
        // most significant bit is set (to keep the value positive).
        $raw = ltrim($raw, "\x00");

        if ($raw === '') {
            $raw = "\x00";
        }

        if ((ord($raw[0]) & 0x80) !== 0) {
            $raw = "\x00" . $raw;
        }

        return self::encodeLength(self::tag(0x02), $raw);
    }

    private static function tag(int $tag): string
    {
        return chr($tag);
    }

    private static function encodeLength(string $tag, string $content): string
    {
        $length = strlen($content);

        if ($length < 0x80) {
            return $tag . chr($length) . $content;
        }

        $lengthBytes = ltrim(pack('N', $length), "\x00");

        return $tag . chr(0x80 | strlen($lengthBytes)) . $lengthBytes . $content;
    }

    private static function base64UrlDecode(string $raw): string
    {
        $remainder = strlen($raw) % 4;

        if ($remainder) {
            $raw .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($raw, '-_', '+/'), true);

        if ($decoded === false) {
            throw new \RuntimeException("Could not base64url decode.");
        }

        return $decoded;
    }
}