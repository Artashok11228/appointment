<?php

namespace Tests\Feature;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class ApiDocsTest extends TestCase
{
    public function test_docs_root_redirects_to_the_swagger_ui(): void
    {
        $this->get('/docs')
            ->assertRedirect('/docs/api');
    }

    public function test_swagger_ui_is_publicly_available(): void
    {
        $response = $this->get('/docs/api');

        $response->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame(
            realpath(base_path('docs/swagger.html')),
            $response->baseResponse->getFile()->getRealPath(),
        );
    }

    public function test_openapi_spec_is_publicly_available(): void
    {
        $response = $this->get('/docs/openapi.yaml');

        $response->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame(
            realpath(base_path('docs/openapi.yaml')),
            $response->baseResponse->getFile()->getRealPath(),
        );
        $this->assertStringContainsString(
            'application/yaml',
            (string) $response->headers->get('content-type'),
        );
    }
}
