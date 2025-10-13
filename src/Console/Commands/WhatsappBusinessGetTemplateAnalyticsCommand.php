<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use ScriptDevelop\WhatsappManager\Support\WhatsappModelResolver;

class WhatsappBusinessGetGeneralTemplateAnalyticsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'whatsapp:get-general-template-analytics
                            {--force : Forzar obtención de 90 días incluso si hay datos}
                            {--template= : Obtener analytics solo para un template específico}
                            {--days= : Número específico de días a obtener (máximo 90)}
                            {--account= : Procesar solo una cuenta específica (whatsapp_business_id)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Obtiene analytics de templates de WhatsApp Business desde la API de Meta para una o todas las cuentas';

    /**
     * Account de WhatsApp Business actual
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $account;

    /**
     * Cliente HTTP
     *
     * @var GuzzleClient
     */
    protected $client;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Iniciando obtención de analytics de templates de WhatsApp Business...');

        try {
            // 1. Obtener cuentas a procesar
            $accounts = $this->getAccountsToProcess();

            if ($accounts->isEmpty()) {
                $this->error('❌ No se encontraron cuentas de WhatsApp Business para procesar');
                return Command::FAILURE;
            }

            $this->info("🏢 Procesando " . $accounts->count() . " cuenta(s) de WhatsApp Business");

            // 2. Determinar período de análisis
            $days = $this->determineDaysToFetch();
            $this->info("📅 Obteniendo analytics de los últimos {$days} días");

            // 3. Procesar cada cuenta
            $totalProcessed = 0;
            $totalErrors = 0;
            $accountsProcessed = 0;

            foreach ($accounts as $account) {
                $this->info("🏢 Procesando cuenta: {$account->whatsapp_business_id}");

                $result = $this->processAccount($account, $days);

                if ($result['success']) {
                    $totalProcessed += $result['processed'];
                    $totalErrors += $result['errors'];
                    $accountsProcessed++;
                    $this->info("   ✅ Cuenta procesada: {$result['processed']} templates, {$result['errors']} errores");
                } else {
                    $this->error("   ❌ Error procesando cuenta: {$result['error']}");
                }

                // Pausa entre cuentas para evitar rate limiting
                if ($account !== $accounts->last()) {
                    $this->info("⏱️ Pausa de 3 segundos entre cuentas...");
                    sleep(3);
                }
            }

            // 4. Resumen final
            $this->info("✅ Proceso completado:");
            $this->info("   🏢 Cuentas procesadas: {$accountsProcessed}/{$accounts->count()}");
            $this->info("   📊 Templates procesados: {$totalProcessed}");
            $this->info("   ❌ Errores totales: {$totalErrors}");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("💥 Error general: " . $e->getMessage());
            Log::error('WhatsApp Analytics Cron Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Obtener cuentas a procesar
     */
    protected function getAccountsToProcess()
    {
        // Si se especifica una cuenta específica
        if ($this->option('account')) {
            $account = WhatsappModelResolver::business_account()
                ->where('whatsapp_business_id', $this->option('account'))
                ->first();

            if (!$account) {
                $this->error("❌ Cuenta no encontrada: " . $this->option('account'));
                return collect();
            }

            $this->info("🎯 Procesando cuenta específica: {$account->whatsapp_business_id}");
            return collect([$account]);
        }

        // Obtener todas las cuentas activas con token configurado
        $accounts = WhatsappModelResolver::business_account()
            ->whereNotNull('api_token')
            ->where('api_token', '!=', '')
            ->get();

        $this->info("🔍 Encontradas " . $accounts->count() . " cuentas con token configurado");
        return $accounts;
    }

    /**
     * Procesar una cuenta específica
     */
    protected function processAccount($account, int $days): array
    {
        try {
            // Configurar la cuenta actual
            $this->account = $account;

            // Configurar cliente HTTP
            if (!$this->setupApiClientForAccount()) {
                return [
                    'success' => false,
                    'error' => 'No se pudo configurar el cliente API',
                    'processed' => 0,
                    'errors' => 0
                ];
            }

            // Obtener templates de esta cuenta
            $templates = $this->getTemplatesChunksForAccount($account);

            if ($templates->isEmpty()) {
                $this->info("   ⚠️ No se encontraron templates para esta cuenta");
                return [
                    'success' => true,
                    'processed' => 0,
                    'errors' => 0
                ];
            }

            $this->info("   📋 Procesando " . $templates->flatten()->count() . " templates en chunks de 10");

            // Procesar cada chunk de templates
            $processed = 0;
            $errors = 0;

            foreach ($templates as $chunkIndex => $templateChunk) {
                $this->info("   🔄 Chunk " . ($chunkIndex + 1) . "/" . $templates->count());

                $result = $this->processTemplateChunk($templateChunk, $days);
                $processed += $result['processed'];
                $errors += $result['errors'];

                // Pausa entre chunks para evitar rate limiting
                if ($chunkIndex < $templates->count() - 1) {
                    sleep(2);
                }
            }

            return [
                'success' => true,
                'processed' => $processed,
                'errors' => $errors
            ];

        } catch (\Exception $e) {
            Log::error('WhatsApp Analytics Account Processing Error', [
                'account_id' => $account->whatsapp_business_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'processed' => 0,
                'errors' => 0
            ];
        }
    }

    /**
     * Configurar cliente API para la cuenta actual
     */
    protected function setupApiClientForAccount(): bool
    {
        if (!$this->account->api_token) {
            $this->warn("   ⚠️ Token de API no configurado en la cuenta");
            return false;
        }

        $this->client = new GuzzleClient([
            'timeout' => config('whatsapp.api.timeout', 30),
            'verify' => false // ⚠️ Solo para desarrollo
        ]);

        return true;
    }

    /**
     * Obtener templates en chunks de 10 para una cuenta específica
     */
    protected function getTemplatesChunksForAccount($account)
    {
        $query = WhatsappModelResolver::template()
            ->select('wa_template_id', 'name')
            ->where('whatsapp_business_id', $account->whatsapp_business_id);

        // Si se especifica un template específico
        if ($this->option('template')) {
            $query->where('wa_template_id', $this->option('template'));
        }

        return $query->pluck('wa_template_id')->chunk(10);
    }

    /**
     * Configurar conexión con la API (método legacy - ahora usa setupApiClientForAccount)
     */
    protected function setupApiConnection(): bool
    {
        $accountId = config('whatsapp.api.default_account_number_id');

        if (!$accountId) {
            $this->error('❌ WHATSAPP_ACCOUNT_NUMBER_ID no configurado');
            return false;
        }

        $this->account = WhatsappModelResolver::business_account()->find($accountId);

        if (!$this->account) {
            $this->error("❌ Cuenta de WhatsApp Business no encontrada: {$accountId}");
            return false;
        }

        if (!$this->account->api_token) {
            $this->error('❌ Token de API no configurado en la cuenta');
            return false;
        }

        $this->client = new GuzzleClient([
            'timeout' => config('whatsapp.api.timeout', 30),
            'verify' => false // ⚠️ Solo para desarrollo
        ]);

        $this->info("✅ Conexión configurada para cuenta: {$this->account->whatsapp_business_id}");
        return true;
    }

    /**
     * Determinar cuántos días obtener
     */
    protected function determineDaysToFetch(): int
    {
        // Si se especifica días manualmente
        if ($this->option('days')) {
            $days = min((int)$this->option('days'), 90);
            $this->info("🎯 Días especificados manualmente: {$days}");
            return $days;
        }

        // Si se fuerza obtención completa
        if ($this->option('force')) {
            $this->info("🔒 Modo forzado: obteniendo 90 días");
            return 90;
        }

        // Verificar si la tabla está vacía
        $hasData = WhatsappModelResolver::template_analytics()->exists();

        if (!$hasData) {
            $this->info("📝 Tabla vacía: obteniendo 90 días iniciales");
            return 90;
        } else {
            $this->info("🔄 Tabla con datos: obteniendo 7 días para actualización");
            return 7;
        }
    }

    /**
     * Obtener templates en chunks de 10 (método legacy)
     */
    protected function getTemplatesChunks()
    {
        $query = WhatsappModelResolver::template()->select('wa_template_id', 'name');

        // Si se especifica un template específico
        if ($this->option('template')) {
            $query->where('wa_template_id', $this->option('template'));
        }

        // Si hay una cuenta configurada, filtrar por ella
        if ($this->account) {
            $query->where('whatsapp_business_id', $this->account->whatsapp_business_id);
        }

        return $query->pluck('wa_template_id')->chunk(10);
    }

    /**
     * Procesar un chunk de templates
     */
    protected function processTemplateChunk($templateIds, int $days): array
    {
        $processed = 0;
        $errors = 0;

        try {
            // Calcular fechas
            $endDate = Carbon::now('UTC');
            $startDate = $endDate->copy()->subDays($days);

            // Llamar a la API
            $analyticsData = $this->fetchAnalyticsFromApi($templateIds->toArray(), $startDate, $endDate);

            if (!$analyticsData) {
                $this->warn("⚠️ No se pudieron obtener datos para este chunk");
                return ['processed' => 0, 'errors' => count($templateIds)];
            }

            // Procesar respuesta de la API
            foreach ($analyticsData['data'] as $dataGroup) {
                foreach ($dataGroup['data_points'] as $dataPoint) {
                    try {
                        $this->saveAnalyticsData($dataPoint, $dataGroup);
                        $processed++;
                    } catch (\Exception $e) {
                        $errors++;
                        $this->warn("❌ Error guardando template {$dataPoint['template_id']}: " . $e->getMessage());
                    }
                }
            }

        } catch (\Exception $e) {
            $this->error("💥 Error procesando chunk: " . $e->getMessage());
            $errors = count($templateIds);
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    /**
     * Obtener analytics desde la API de WhatsApp
     */
    protected function fetchAnalyticsFromApi(array $templateIds, Carbon $startDate, Carbon $endDate): ?array
    {
        $baseUri = config('whatsapp.api.base_url') . '/' . config('whatsapp.api.version') . '/' . $this->account->phone_number_id;

        try {
            $response = $this->client->get($baseUri . '/template_analytics', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->account->api_token,
                ],
                'query' => [
                    'start' => (string)$startDate->timestamp,
                    'end' => (string)$endDate->timestamp,
                    'granularity' => 'DAILY',
                    'metric_types' => [
                        'COST',
                        'CLICKED',
                        'SENT',
                        'DELIVERED',
                        'READ',
                    ],
                    'template_ids' => $templateIds,
                    'limit' => 1000, // Máximo para obtener todos los días
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return json_decode($response->getBody()->getContents(), true);
            }

            $this->warn("⚠️ API respondió con código: {$statusCode}");
            return null;

        } catch (RequestException $e) {
            $this->error("🔌 Error de conexión con la API: " . $e->getMessage());

            if ($e->hasResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                $this->error("📄 Respuesta de error: " . $errorBody);
            }

            return null;
        }
    }

    /**
     * Guardar datos de analytics en la base de datos
     */
    protected function saveAnalyticsData(array $dataPoint, array $dataGroup): void
    {
        DB::transaction(function () use ($dataPoint, $dataGroup) {
            // Convertir timestamps a fechas
            $startTimestamp = $dataPoint['start'];
            $endTimestamp = $dataPoint['end'];
            $startDate = Carbon::createFromTimestamp($startTimestamp)->format('Y-m-d');
            $endDate = Carbon::createFromTimestamp($endTimestamp)->format('Y-m-d');

            // Crear o actualizar registro principal
            $analytics = WhatsappModelResolver::template_analytics()->updateOrCreate([
                'wa_template_id' => $dataPoint['template_id'],
                'start_timestamp' => $startTimestamp,
                'end_timestamp' => $endTimestamp,
            ], [
                'granularity' => $dataGroup['granularity'],
                'product_type' => $dataGroup['product_type'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'sent' => $dataPoint['sent'] ?? 0,
                'delivered' => $dataPoint['delivered'] ?? 0,
                'read' => $dataPoint['read'] ?? 0,
                'json_data' => $dataPoint,
            ]);

            // Limpiar datos anteriores de clicks y costos para este registro
            $analytics->clickedData()->delete();
            $analytics->costData()->delete();

            // Guardar datos de clicks
            if (isset($dataPoint['clicked']) && is_array($dataPoint['clicked'])) {
                foreach ($dataPoint['clicked'] as $clickData) {
                    WhatsappModelResolver::make('template_analytics_clicked', [
                        'template_analytics_id' => $analytics->id,
                        'type' => $clickData['type'],
                        'button_content' => $clickData['button_content'] ?? null,
                        'count' => $clickData['count'] ?? 0,
                    ])->save();
                }
            }

            // Guardar datos de costos
            if (isset($dataPoint['cost']) && is_array($dataPoint['cost'])) {
                foreach ($dataPoint['cost'] as $costData) {
                    WhatsappModelResolver::make('template_analytics_cost', [
                        'template_analytics_id' => $analytics->id,
                        'type' => $costData['type'],
                        'value' => $costData['value'] ?? null,
                        'currency' => 'USD', // Valor por defecto
                    ])->save();
                }
            }
        });
    }
}