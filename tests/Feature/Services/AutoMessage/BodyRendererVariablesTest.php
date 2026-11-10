<?php

namespace Tests\Feature\Services\AutoMessage;

use App\Services\AutoMessage\BodyRenderer;
use Tests\TestCase;

class BodyRendererVariablesTest extends TestCase
{
    public function test_available_variables_includes_core_placeholders(): void
    {
        $vars = BodyRenderer::availableVariables();

        $this->assertArrayHasKey('{{ $guest_first_name }}', $vars);
        $this->assertArrayHasKey('{{ $arrival }}', $vars);
        $this->assertArrayHasKey('{{ $listing->wifi_password }}', $vars);
        $this->assertSame('ゲスト名（名）', $vars['{{ $guest_first_name }}']);
    }
}
