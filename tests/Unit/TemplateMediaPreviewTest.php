<?php

namespace ScriptDevelop\WhatsappManager\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Orchestra\Testbench\TestCase;
use ScriptDevelop\WhatsappManager\Providers\WhatsappServiceProvider;
use ScriptDevelop\WhatsappManager\Services\FlowService;
use ScriptDevelop\WhatsappManager\Services\TemplateBuilder;
use ScriptDevelop\WhatsappManager\Services\TemplateService;
use ScriptDevelop\WhatsappManager\WhatsappApi\ApiClient;

class TemplateMediaPreviewTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WhatsappServiceProvider::class];
    }

    public function test_stores_a_public_preview_copy_without_touching_the_template_payload(): void
    {
        Storage::fake('public');

        $builder = new class(
            Mockery::mock(ApiClient::class),
            Mockery::mock(Model::class),
            Mockery::mock(TemplateService::class),
            Mockery::mock(FlowService::class),
        ) extends TemplateBuilder {
            public function storePreview(string $path): void
            {
                $this->storeHeaderMediaPreview($path);
            }

            public function previewUrl(): ?string
            {
                return $this->headerMediaPreviewUrl;
            }
        };

        $path = tempnam(sys_get_temp_dir(), 'template-media-');
        file_put_contents($path, 'fake-media');

        $builder->storePreview($path);

        $this->assertNotNull($builder->previewUrl());
        $this->assertStringContainsString('/storage/whatsapp/template-headers/', $builder->previewUrl());

        $relative = ltrim(parse_url($builder->previewUrl(), PHP_URL_PATH), '/');
        $relative = preg_replace('#^storage/#', '', $relative);
        Storage::disk('public')->assertExists($relative);

        @unlink($path);
    }

    public function test_preview_storage_failure_is_non_fatal(): void
    {
        Storage::shouldReceive('disk')->andThrow(new \RuntimeException('storage unavailable'));

        $builder = new class(
            Mockery::mock(ApiClient::class),
            Mockery::mock(Model::class),
            Mockery::mock(TemplateService::class),
            Mockery::mock(FlowService::class),
        ) extends TemplateBuilder {
            public function storePreview(string $path): void
            {
                $this->storeHeaderMediaPreview($path);
            }

            public function previewUrl(): ?string
            {
                return $this->headerMediaPreviewUrl;
            }
        };

        $path = tempnam(sys_get_temp_dir(), 'template-media-');
        file_put_contents($path, 'fake-media');

        $builder->storePreview($path);

        $this->assertNull($builder->previewUrl());
        @unlink($path);
    }
}
