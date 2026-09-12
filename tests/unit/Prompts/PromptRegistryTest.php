<?php

namespace Espo\Modules\EspoMcp\Tests\unit\Prompts;

use Espo\Modules\EspoMcp\Tools\Mcp\Prompts\PromptRegistry;
use PHPUnit\Framework\TestCase;

class PromptRegistryTest extends TestCase
{
    public function testListsThreePrompts(): void
    {
        $list = (new PromptRegistry())->list();

        $this->assertCount(3, $list);
        $this->assertSame(
            ['pipeline-review', 'lead-triage', 'duplicate-sweep'],
            array_column($list, 'name')
        );
    }

    public function testEveryListedPromptIsRetrievable(): void
    {
        $registry = new PromptRegistry();

        foreach ($registry->list() as $entry) {
            $prompt = $registry->get($entry['name'], []);

            $this->assertIsArray($prompt, $entry['name']);
            $this->assertNotEmpty($prompt['messages'], $entry['name']);
            $this->assertSame('user', $prompt['messages'][0]['role']);
            $this->assertNotSame('', $prompt['description'], $entry['name']);
        }
    }

    public function testUnknownPromptReturnsNull(): void
    {
        $this->assertNull((new PromptRegistry())->get('nope', []));
    }

    public function testArgumentIsInterpolated(): void
    {
        $prompt = (new PromptRegistry())->get('pipeline-review', ['period' => 'last quarter']);

        $this->assertStringContainsString(
            'last quarter',
            $prompt['messages'][0]['content']['text']
        );
    }

    public function testMissingArgumentFallsBackToDefault(): void
    {
        $prompt = (new PromptRegistry())->get('pipeline-review', []);

        $this->assertStringContainsString(
            'the last 30 days',
            $prompt['messages'][0]['content']['text']
        );
    }
}
