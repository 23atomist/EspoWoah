<?php

namespace Espo\Modules\EspoMcp\Tests\unit\Resources;

use Espo\Modules\EspoMcp\Tools\Mcp\Resources\ResourceRegistry;
use PHPUnit\Framework\TestCase;

class ResourceRegistryTest extends TestCase
{
    public function testListsThreeGuides(): void
    {
        $list = (new ResourceRegistry())->list();

        $this->assertCount(3, $list);

        foreach ($list as $entry) {
            $this->assertArrayHasKey('uri', $entry);
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('description', $entry);
            $this->assertSame('text/markdown', $entry['mimeType']);
        }
    }

    public function testEveryListedUriIsReadable(): void
    {
        $registry = new ResourceRegistry();

        foreach ($registry->list() as $entry) {
            $content = $registry->read($entry['uri']);

            $this->assertIsString($content, $entry['uri']);
            $this->assertNotSame('', trim($content), $entry['uri']);
        }
    }

    public function testUnknownUriReturnsNull(): void
    {
        $this->assertNull((new ResourceRegistry())->read('espocrm://guide/nope'));
    }

    public function testSearchGrammarGuideCoversTheNonObviousOperators(): void
    {
        $content = (string) (new ResourceRegistry())->read('espocrm://guide/search-grammar');

        foreach (['linkedWith', 'arrayAnyOf', 'isTrue', 'between'] as $operator) {
            $this->assertStringContainsString($operator, $content, $operator);
        }
    }

    public function testAclGuideExplainsEveryLevel(): void
    {
        $content = (string) (new ResourceRegistry())->read('espocrm://guide/acl-levels');

        foreach (['`all`', '`team`', '`own`', '`no`'] as $level) {
            $this->assertStringContainsString($level, $content, $level);
        }
    }
}
