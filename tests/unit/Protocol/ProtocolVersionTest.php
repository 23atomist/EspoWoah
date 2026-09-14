<?php

namespace Espo\Modules\EspoMcp\Tests\unit\Protocol;

use Espo\Modules\EspoMcp\Tools\Mcp\Protocol\ProtocolVersion;
use PHPUnit\Framework\TestCase;

class ProtocolVersionTest extends TestCase
{
    public function testLatestIsTheFirstSupportedRevision(): void
    {
        $this->assertSame(ProtocolVersion::SUPPORTED[0], ProtocolVersion::LATEST);
    }

    public function testTheSseOnlyRevisionIsNotOffered(): void
    {
        // 2024-11-05 defines only the HTTP+SSE transport. Offering it would
        // promise a long-lived stream this server never opens.
        $this->assertFalse(ProtocolVersion::isSupported('2024-11-05'));
    }

    public function testBothStreamableHttpRevisionsAreSupported(): void
    {
        $this->assertTrue(ProtocolVersion::isSupported('2025-06-18'));
        $this->assertTrue(ProtocolVersion::isSupported('2025-03-26'));
    }

    public function testNegotiateEchoesASupportedRequest(): void
    {
        $this->assertSame('2025-03-26', ProtocolVersion::negotiate('2025-03-26'));
        $this->assertSame('2025-06-18', ProtocolVersion::negotiate('2025-06-18'));
    }

    public function testNegotiateFallsBackToLatestForAnythingElse(): void
    {
        foreach (['2024-11-05', 'not-a-version', '', null, 42, []] as $requested) {
            $this->assertSame(
                ProtocolVersion::LATEST,
                ProtocolVersion::negotiate($requested),
                var_export($requested, true)
            );
        }
    }

    public function testAbsentProtocolHeaderIsAcceptable(): void
    {
        // The spec says to assume 2025-03-26 rather than reject.
        $this->assertTrue(ProtocolVersion::headerIsAcceptable(null));
        $this->assertTrue(ProtocolVersion::headerIsAcceptable(''));
    }

    public function testSupportedProtocolHeaderIsAcceptable(): void
    {
        $this->assertTrue(ProtocolVersion::headerIsAcceptable('2025-06-18'));
        $this->assertTrue(ProtocolVersion::headerIsAcceptable('2025-03-26'));
    }

    public function testUnsupportedProtocolHeaderIsRefused(): void
    {
        $this->assertFalse(ProtocolVersion::headerIsAcceptable('2024-11-05'));
        $this->assertFalse(ProtocolVersion::headerIsAcceptable('tomorrow'));
        $this->assertFalse(ProtocolVersion::headerIsAcceptable(42));
    }

    public function testAssumedVersionWhenHeaderAbsentIsSupported(): void
    {
        $this->assertTrue(
            ProtocolVersion::isSupported(ProtocolVersion::ASSUMED_WHEN_HEADER_ABSENT)
        );
    }

    public function testDetectsAnEventStreamRequest(): void
    {
        $this->assertTrue(ProtocolVersion::wantsEventStream('text/event-stream'));
        $this->assertTrue(ProtocolVersion::wantsEventStream('application/json, text/event-stream'));
        $this->assertTrue(ProtocolVersion::wantsEventStream('text/event-stream;q=0.9'));
        $this->assertTrue(ProtocolVersion::wantsEventStream('  TEXT/EVENT-STREAM  '));
    }

    public function testDoesNotMistakeOtherAcceptValuesForEventStream(): void
    {
        foreach (['application/json', '*/*', 'text/html', '', null, 42] as $accept) {
            $this->assertFalse(
                ProtocolVersion::wantsEventStream($accept),
                var_export($accept, true)
            );
        }
    }

    public function testEventStreamIsNotMatchedAsASubstring(): void
    {
        // A media type that merely contains the words must not match.
        $this->assertFalse(ProtocolVersion::wantsEventStream('application/text-event-stream-ish'));
    }
}
