<?php

namespace ScriptDevelop\WhatsappManager\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use ScriptDevelop\WhatsappManager\Services\WhatsappManager;
use ScriptDevelop\WhatsappManager\WhatsappApi\ApiClient;
use ScriptDevelop\WhatsappManager\Services\AccountRegistrationService;
use ScriptDevelop\WhatsappManager\Services\WhatsappService;
use ScriptDevelop\WhatsappManager\Repositories\WhatsappBusinessAccountRepository;
use ScriptDevelop\WhatsappManager\Console\Commands\CheckUserModel;
use ScriptDevelop\WhatsappManager\Services\BlockService;
use ScriptDevelop\WhatsappManager\Services\MessageDispatcherService;
use ScriptDevelop\WhatsappManager\Services\TemplateService;
use ScriptDevelop\WhatsappManager\Services\FlowService;
use ScriptDevelop\WhatsappManager\Services\Flows\FlowMediaService;
use ScriptDevelop\WhatsappManager\Services\Flows\FlowCryptoService;
use ScriptDevelop\WhatsappManager\Events\TemplateMessageSent;
use ScriptDevelop\WhatsappManager\Listeners\CreateFlowSessionOnTemplateSent;
use Illuminate\Support\Facades\Event;

class WhatsappServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Fusionar configuraciones
        $this->mergeConfigFrom(__DIR__ . '/../Config/whatsapp.php', 'whatsapp');
        $this->mergeConfigFrom(__DIR__ . '/../Config/logging.php', 'logging');

        // Registrar servicios
        $this->app->singleton(ApiClient::class, function ($app) {
            return new ApiClient(
                config('whatsapp.api.base_url', 'https://graph.facebook.com'),
                config('whatsapp.api.version', 'v22.0'),
                config('whatsapp.api.timeout', 30)
            );
        });

        $this->app->singleton(WhatsappBusinessAccountRepository::class);

        $this->app->singleton(MessageDispatcherService::class, function ($app) {
            return new MessageDispatcherService(
                $app->make(ApiClient::class)
            );
        });

        $this->app->singleton('whatsapp.manager', function ($app) {
            return new WhatsappManager(
                $app->make(MessageDispatcherService::class)
            );
        });

        $this->app->singleton(AccountRegistrationService::class, function ($app) {
            return new AccountRegistrationService(
                $app->make(WhatsappService::class)
            );
        });

        $this->app->singleton(WhatsappService::class, function ($app) {
            return new WhatsappService(
                $app->make(ApiClient::class),
                $app->make(WhatsappBusinessAccountRepository::class)
            );
        });

        $this->app->alias(WhatsappService::class, 'whatsapp.phone');
        $this->app->alias(MessageDispatcherService::class, 'whatsapp.message');
        $this->app->alias(AccountRegistrationService::class, 'whatsapp.account');

        $this->app->singleton('whatsapp.template', function ($app) {
            return new TemplateService(
                $app->make(ApiClient::class),
                $app->make(FlowService::class)
            );
        });

        $this->app->singleton('whatsapp.block', function ($app) {
            return new BlockService(
                $app->make(ApiClient::class)
            );
        });

        $this->app->singleton('whatsapp.flow', function ($app) {
            return new FlowService(
                $app->make(ApiClient::class)
            );
        });

        $this->app->singleton('whatsapp.qr_code', function ($app) {
            return new \ScriptDevelop\WhatsappManager\Services\QrCodeService(
                $app->make(ApiClient::class)
            );
        });

        $this->app->singleton(FlowMediaService::class, function ($app) {
            return new FlowMediaService();
        });

        $this->app->singleton(FlowCryptoService::class, function ($app) {
            return new FlowCryptoService();
        });

        // ── Flow Data Collection Services ─────────────────────────────────────
        $this->app->singleton(
            \ScriptDevelop\WhatsappManager\Services\Flows\FlowSessionService::class,
            function ($app) {
                return new \ScriptDevelop\WhatsappManager\Services\Flows\FlowSessionService();
            }
        );

        $this->app->singleton(
            \ScriptDevelop\WhatsappManager\Services\Flows\FlowResponseService::class,
            function ($app) {
                return new \ScriptDevelop\WhatsappManager\Services\Flows\FlowResponseService();
            }
        );

        $this->app->singleton(
            \ScriptDevelop\WhatsappManager\Services\Flows\Handlers\AutoFlowHandler::class,
            function ($app) {
                return new \ScriptDevelop\WhatsappManager\Services\Flows\Handlers\AutoFlowHandler(
                    $app->make(\ScriptDevelop\WhatsappManager\Services\Flows\FlowSessionService::class)
                );
            }
        );

        $this->app->singleton(
            \ScriptDevelop\WhatsappManager\Services\Flows\Handlers\WebhookProxyHandler::class,
            function ($app) {
                return new \ScriptDevelop\WhatsappManager\Services\Flows\Handlers\WebhookProxyHandler();
            }
        );

        $this->app->singleton(
            \ScriptDevelop\WhatsappManager\Services\Flows\FlowEndpointRouter::class,
            function ($app) {
                return new \ScriptDevelop\WhatsappManager\Services\Flows\FlowEndpointRouter(
                    $app->make(\ScriptDevelop\WhatsappManager\Services\Flows\FlowSessionService::class)
                );
            }
        );

        // bind (no singleton) — ejecuta side effects externos, mejor no compartir estado
        $this->app->bind(
            \ScriptDevelop\WhatsappManager\Services\Flows\FlowActionDispatcher::class,
            function ($app) {
                return new \ScriptDevelop\WhatsappManager\Services\Flows\FlowActionDispatcher();
            }
        );
        // ── Fin Flow Data Collection Services ────────────────────────────────

        // Registrar el procesador de webhook con valor por defecto
        $this->app->bind(
            \ScriptDevelop\WhatsappManager\Contracts\WebhookProcessorInterface::class,
            function () {
                $processorClass = config(
                    'whatsapp.webhook.processor',
                    \ScriptDevelop\WhatsappManager\Services\WebhookProcessors\BaseWebhookProcessor::class
                );

                // Verificar si la clase existe y es instanciable
                if (class_exists($processorClass)) {
                    return new $processorClass();
                }

                // Fallback a la implementación por defecto
                return new \ScriptDevelop\WhatsappManager\Services\WebhookProcessors\BaseWebhookProcessor();
            }
        );
    }

    public function boot()
    {
        // Publicar archivos de configuración
        $this->publishes([
            __DIR__ . '/../Config/whatsapp.php' => config_path('whatsapp.php'),
        ], 'whatsapp-config');

        // Publicar migraciones
        $this->publishes([
            __DIR__ . '/../Database/Migrations' => database_path('migrations'),
        ], 'whatsapp-migrations');

        // Cargar automáticamente las migraciones si está habilitado
        if (config('whatsapp.load_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        }

        // Publicar seeders
        $this->publishes([
            __DIR__ . '/../Database/Seeders/WhatsappTemplateLanguageSeeder.php' => database_path('seeders/WhatsappTemplateLanguageSeeder.php'),
        ], 'whatsapp-seeders');

        // Publicar rutas
        $this->publishes([
            __DIR__ . '/../routes/whatsapp_webhook.php' => base_path('routes/whatsapp_webhook.php'),
        ], 'whatsapp-routes');

        $this->publishes([], 'whatsapp-media');

        $this->publishes([
            __DIR__ . '/../routes/channels.php' => base_path('routes/channels.php'),
        ], 'whatsapp-events');

        // Cargar rutas automáticamente
        $this->loadRoutesFrom(__DIR__ . '/../routes/whatsapp_webhook.php');

        // Registrar comandos de consola
        if ($this->app->runningInConsole()) {
            // Crear directorios necesarios al publicar configuraciones
            $this->publishes([], 'whatsapp-storage');

            $this->publishes([
                __DIR__ . '/../Database/Seeders/WhatsappTemplateLanguageSeeder.php' => database_path('seeders/WhatsappTemplateLanguageSeeder.php'),
            ], 'whatsapp-seeders');

            $this->registerPackageCommands();

            // Registrar el schedule automáticamente
            $this->registerSchedule();
        }

        // Crear el enlace simbólico y directorios solo al publicar configuraciones
        if ($this->app->runningInConsole() && $this->isPublishing()) {
            $this->createStorageDirectories();
            $this->createStorageLink();
        }

        if ($this->app->runningInConsole()) {
            if (!file_exists(config_path('whatsapp.php'))) {
                $this->app->booted(function () {

                });
            }
        }

        // ── Registrar listeners internos del paquete ──────────────────────
        Event::listen(TemplateMessageSent::class, CreateFlowSessionOnTemplateSent::class);
    }

    /**
     * Crear los directorios necesarios para el almacenamiento.
     */
    protected function createStorageDirectories()
    {
        $basePath = storage_path('app/public/whatsapp');
        // Añadimos 'flows/keys' y 'flows/media'
        $folders = ['audios', 'documents', 'images', 'stickers', 'videos', 'flows/keys', 'flows/media'];

        foreach ($folders as $folder) {
            $path = "{$basePath}/{$folder}";
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    /**
     * Crear el enlace simbólico para storage.
     */
    protected function createStorageLink()
    {
        $mediaBasePath = storage_path('app/public/whatsapp');
        $mediaLinkPath = public_path('storage/whatsapp');

        try {
            // Asegurar directorio padre
            $parentDir = dirname($mediaLinkPath);
            if (!is_dir($parentDir) && !@mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
                throw new \RuntimeException("No se pudo crear el directorio: {$parentDir}");
            }

            // Crear enlace solo si no existe
            if (!file_exists($mediaLinkPath)) {
                if (@symlink($mediaBasePath, $mediaLinkPath)) {
                    $this->app['log']->info('Enlace simbólico creado exitosamente.');
                } else {
                    $this->app['log']->warning("Falló la creación automática del enlace. Ejecuta manualmente: php artisan storage:link");
                }
            }
        } catch (\Throwable $e) {
            $this->app['log']->error("Error en storage link: {$e->getMessage()}");
            $this->app['log']->warning("El paquete se instaló correctamente, pero debes ejecutar MANUALMENTE: php artisan storage:link");
        }
    }

    /**
     * Verifica si se está ejecutando una publicación.
     */
    protected function isPublishing(): bool
    {
        $argv = request()->server('argv', []);
        return in_array('--tag=whatsapp-config', $argv) ||
            in_array('--tag=whatsapp-migrations', $argv) ||
            in_array('--tag=whatsapp-routes', $argv) ||
            in_array('--tag=whatsapp-storage', $argv);
    }

    /**
     * Registrar el schedule automáticamente (placeholder para documentación)
     *
     * INSTRUCCIONES PARA EL USUARIO:
     *
     * Agrega lo siguiente a tu archivo routes/console.php:
     *
     * use Illuminate\Support\Facades\Schedule;
     *
     * if (config('whatsapp.crontimes.get_general_template_analytics.enabled', false)) {
     *     Schedule::command('whatsapp:get-general-template-analytics')
     *         ->cron(config('whatsapp.crontimes.get_general_template_analytics.schedule', '0 0 * * *'))
     *         ->onOneServer()
     *         ->runInBackground()
     *         ->withoutOverlapping(60);
     * }
     *
     * Y asegúrate de tener configurado el CRON en tu servidor:
     * * * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
     */
    protected function registerSchedule(): void
    {
        // El schedule debe agregarse manualmente en routes/console.php del proyecto
        // Ver las instrucciones en el comentario de arriba
    }

    /**
     * Registrar automáticamente todos los comandos del paquete.
     */
    protected function registerPackageCommands(): void
    {
        $commandFiles = glob(__DIR__ . '/../Console/Commands/*.php') ?: [];
        $commandClasses = [];

        foreach ($commandFiles as $commandFile) {
            $className = pathinfo($commandFile, PATHINFO_FILENAME);
            $fqcn = "ScriptDevelop\\WhatsappManager\\Console\\Commands\\{$className}";

            if (class_exists($fqcn) && is_subclass_of($fqcn, \Illuminate\Console\Command::class)) {
                $commandClasses[] = $fqcn;
            }
        }

        if (!empty($commandClasses)) {
            $this->commands($commandClasses);
        }
    }
}