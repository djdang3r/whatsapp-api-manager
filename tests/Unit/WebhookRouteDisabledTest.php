<?php

namespace ScriptDevelop\WhatsappManager\Tests\Unit;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use ScriptDevelop\WhatsappManager\Providers\WhatsappServiceProvider;

class WebhookRouteDisabledTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WhatsappServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('whatsapp.webhook.routes_enabled', false);
    }

    public function test_legacy_package_route_is_not_registered_when_disabled(): void
    {
        $this->assertFalse(Route::has('whatsapp.webhook.handle'));
    }
}
