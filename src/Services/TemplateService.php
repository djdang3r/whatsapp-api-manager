<?php

namespace ScriptDevelop\WhatsappManager\Services;

use InvalidArgumentException;
//use ScriptDevelop\WhatsappManager\Models\Template;
//use ScriptDevelop\WhatsappManager\Models\TemplateComponent;
//use ScriptDevelop\WhatsappManager\Models\TemplateCategory;
//use ScriptDevelop\WhatsappManager\Models\TemplateLanguage;
use ScriptDevelop\WhatsappManager\WhatsappApi\ApiClient;
use ScriptDevelop\WhatsappManager\WhatsappApi\Endpoints;
//use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;
//use ScriptDevelop\WhatsappManager\Models\WhatsappPhoneNumber;
//use ScriptDevelop\WhatsappManager\Models\WhatsappTemplateFlow;
//use ScriptDevelop\WhatsappManager\Models\WhatsappFlow;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ScriptDevelop\WhatsappManager\Jobs\CompressTemplateMediaJob;
use ScriptDevelop\WhatsappManager\Services\TemplateMediaCompressionService;

use Illuminate\Database\Eloquent\Model;
use ScriptDevelop\WhatsappManager\Support\WhatsappModelResolver;

/**
 * Servicio para gestionar las plantillas de WhatsApp.
 * Proporciona métodos para sincronizar, crear, actualizar, eliminar y enviar plantillas.
 */
class TemplateService
{
    /**
     * Cliente para realizar solicitudes a la API de WhatsApp.
     * @var ApiClient
     */
    protected ApiClient $apiClient;

    /**
     * Servicio para gestionar flujos de WhatsApp.
     * @var FlowService
     */
    protected FlowService $flowService;

    /**
     * Constructor de la clase.
     *
     * @param ApiClient $apiClient Cliente para realizar solicitudes a la API de WhatsApp.
     */
    public function __construct(ApiClient $apiClient, FlowService $flowService)
    {
        $this->apiClient = $apiClient;
        $this->flowService = $flowService;
    }

    /**
     * Sincroniza todas las plantillas desde la API de WhatsApp.
     *
     * @param Model $account La cuenta empresarial de WhatsApp.
     * @return \Illuminate\Database\Eloquent\Collection Colección de plantillas sincronizadas.
     */
    public function getTemplates(Model $account)
    {
        $this->flowService->syncFlows($account);

        $endpoint = Endpoints::build(Endpoints::GET_TEMPLATES, [
            'waba_id' => $account->whatsapp_business_id,
        ]);

        Log::channel('whatsapp')->info('Iniciando sincronización de plantillas.', [
            'endpoint' => $endpoint,
            'business_id' => $account->whatsapp_business_id,
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $account->api_token,
        ];

        Log::channel('whatsapp')->info('Iniciando sincronización de plantillas.', [
            'endpoint' => $endpoint,
            'headers' => $headers,
        ]);

        try {
            $templates = [];

            $nextCursor = null;

            do {
                $query = [];
                if ($nextCursor) {
                    $query['after'] = $nextCursor;
                }

                $response = $this->apiClient->request(
                    'GET',
                    $endpoint,
                    [],
                    null,
                    $query,
                    $headers
                );

                Log::channel('whatsapp')->info('Respuesta recibida de la API.', [
                    'response' => $response,
                ]);

                if (isset($response['data']) && !empty($response['data'])) {
                    $templates = array_merge($templates, $response['data']);
                    $nextCursor = $response['paging']['cursors']['after'] ?? null;
                } else {
                    $nextCursor = null;
                }
            } while ($nextCursor);

            foreach ($templates as $templateData) {
                $this->validateTemplateData($templateData);
                $template = $this->storeOrUpdateTemplate($account, $templateData);

                // Crear nueva versión si la plantilla fue actualizada
                $this->createOrUpdateVersion($template, $templateData);
            }

            if( !empty($templates) ){
                $apiTemplateIds = collect($templates)->pluck('id')->toArray();
                if( !empty($apiTemplateIds) ){
                    WhatsappModelResolver::template()->where('whatsapp_business_id', $account->whatsapp_business_id)
                        ->whereNotIn('wa_template_id', $apiTemplateIds)
                        ->where('status', '<>', 'INACTIVE')
                        ->update(['status' => 'INACTIVE']);
                }
            }

            return WhatsappModelResolver::template()->where('whatsapp_business_id', $account->whatsapp_business_id)
                ->with(['category', 'components'])
                ->get();

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error al sincronizar plantillas.', [
                'error_message' => $e->getMessage(),
                'endpoint' => $endpoint,
                'business_id' => $account->whatsapp_business_id,
            ]);
            throw $e;
        }
    }

    /**
     * Crea una nueva versión de plantilla.
     */
    protected function createOrUpdateVersion(Model $template, array $templateData): Model
    {
        // Generar hash único de la estructura actual
        $structureHash = md5(json_encode($templateData['components'] ?? []));

        // Buscar si ya existe esta versión
        $existingVersion = WhatsappModelResolver::template_version()
            ->where('template_id', $template->template_id)
            ->where('version_hash', $structureHash)
            ->first();

        if ($existingVersion) {
            // Actualizar estado si cambió
            if ($existingVersion->status !== $templateData['status']) {
                $existingVersion->update(['status' => $templateData['status']]);
            }

            $this->createOrUpdateDefaultTemplateVersion($templateData['status'] ?? 'PENDING', $template, $existingVersion);

            return $existingVersion;
        }

        $templateVersion = WhatsappModelResolver::template_version()->create([
            'template_id' => $template->template_id,
            'version_hash' => $structureHash,
            'template_structure' => $templateData['components'] ?? [],
            'status' => $templateData['status'],
            'is_active' => ($templateData['status'] === 'APPROVED'),
        ]);

        $this->createOrUpdateDefaultTemplateVersion($templateData['status'] ?? 'PENDING', $template, $templateVersion);

        //Guardar el archivo del header si es que tiene
        $headerFormat = null;
        $headerUrlMultimedia = null;
        foreach ($templateData['components'] ?? [] as $component) {
            if (Str::upper($component['type']) === 'HEADER') {
                $headerFormat = Str::upper($component['format']) ?? null;
                $headerUrlMultimedia = $component['example']['header_handle'][0] ?? null;
                break;
            }
        }
        if ($headerFormat && $headerUrlMultimedia && in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
            $this->saveTemplateVersionMedia($templateVersion, $headerUrlMultimedia, $headerFormat);
        }

        Log::channel('whatsapp')->info('New template version created', [
            'template_id' => $template->template_id,
            'version_id' => $templateVersion->version_id,
        ]);

        // Crear nueva versión
        return $templateVersion;
    }

    public function saveTemplateVersionMedia(Model $version, string $mediaUrl, string $mediaType): void
    {
        $maxTemplateMediaSize = (int) config('whatsapp.media.max_file_size.video', 16 * 1024 * 1024);

        // Ejecutar inmediatamente (sin queue) para mantener el flujo actual.
        // Futuro: reemplazar por CompressTemplateMediaJob::dispatch(...)
        /*$compressionJob = new CompressTemplateMediaJob(
            $template,
            $version,
            $mediaUrl,
            $mediaType,
            $maxTemplateMediaSize,
            3
        );
        $compressionResult = $compressionJob->handle(new TemplateMediaCompressionService());*/

        if(
            config('whatsapp.using_queue_download_multimedia', false)===true and
            config('whatsapp.package_ffmpeg_installed', false) and
            config('whatsapp.package_php_gd_installed', false)
        ){
            CompressTemplateMediaJob::dispatch(
                $version,
                $mediaUrl,
                $mediaType,
                $maxTemplateMediaSize,
                3
            )
            ->onQueue(config('whatsapp.queue_multimedia_name', 'default')); // Puedes especificar la queue que desees
        }
    }

    private function getFileExtension(?string $mimeType): string
    {
        //Prevenir que el mimetype sea parecido a esto: "audio/ogg; codecs=opus", así son las notas de voz
        if ($mimeType && str_contains($mimeType, ';')) {
            $mimeType = explode(';', $mimeType)[0];
        }

        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'audio/ogg', 'audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr' => 'ogg',
            'video/mp4', 'video/3gpp' => 'mp4',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt',
            'image/webp' => 'webp',
            default => function () use ($mimeType) {
                    Log::channel('whatsapp')->warning("Extensión desconocida para MIME type: {$mimeType}");
                    return 'bin';
                },
        };
    }

    /**
     * Crea o actualiza la versión predeterminada de una plantilla Aprobada.
     *
     * @param string $status
     * @param Model $template
     * @param Model $version
     * @return void
     */
    protected function createOrUpdateDefaultTemplateVersion(string $status, Model $template, Model $version): void
    {
        if( $status === 'APPROVED' && $template && $version ){
            $templateVersionDefaultModel = config('whatsapp.models.template_version_default');
            $templateVersionDefaultModel::upsertDefault($template->template_id, $version->version_id);
        }
    }

    /**
     * Obtiene una plantilla por su ID desde la API de WhatsApp.
     *
     * @param Model $account La cuenta empresarial de WhatsApp.
     * @param string $templateId El ID de la plantilla.
     * @return Model La plantilla obtenida.
     * @throws InvalidArgumentException Si el ID de la plantilla no es válido.
     */
    public function getTemplateById(Model $account, string $templateId): Model
    {
        if (empty($templateId)) {
            throw new InvalidArgumentException('El ID de la plantilla es obligatorio.');
        }

        $endpoint = Endpoints::build(Endpoints::GET_TEMPLATE, [
            'template_id' => $templateId,
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $account->api_token,
        ];

        Log::channel('whatsapp')->info('Obteniendo plantilla desde la API.', [
            'endpoint' => $endpoint,
            'headers' => $headers,
        ]);

        try {
            $response = $this->apiClient->request(
                'GET',
                $endpoint,
                [],
                null,
                [],
                $headers
            );

            Log::channel('whatsapp')->info('Respuesta recibida de la API.', [
                'response' => $response,
            ]);

            $this->validateTemplateData($response);

            // Almacenar o actualizar la plantilla en la base de datos
            $template = $this->storeOrUpdateTemplate($account, $response);

            // Crear nueva versión si la plantilla fue actualizada
            $this->createOrUpdateVersion($template, $response);

            return $template;
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error al obtener la plantilla.', [
                'error_message' => $e->getMessage(),
                'endpoint' => $endpoint,
                'template_id' => $templateId,
            ]);
            throw $e;
        }
    }

    /**
     * Obtiene solo una plantilla que coincida parcialmente por su nombre desde la API de WhatsApp.
     * Nota: Si deseas obtener una coincidencia exacta por nombre y lenguaje, se recomienda usar getTemplateByNameLanguage.
     *
     * @param Model $account La cuenta empresarial de WhatsApp.
     * @param string $templateName El nombre de la plantilla.
     * @return Model|null La plantilla obtenida o null si no existe.
     */
    public function getTemplateByName(Model $account, string $templateName): ?Model
    {
        if (!$account) {
            throw new InvalidArgumentException('La cuenta de WhatsApp es obligatoria.');
        }

        if (empty($templateName)) {
            throw new InvalidArgumentException('El nombre de la plantilla es obligatorio.');
        }

        $endpoint = Endpoints::build(Endpoints::GET_TEMPLATES, [
            'waba_id' => $account->whatsapp_business_id,
        ]);

        $query = [
            'name' => $templateName,
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $account->api_token,
        ];

        Log::channel('whatsapp')->info('Obteniendo plantilla por nombre desde la API.', [
            'endpoint' => $endpoint,
            'query' => $query,
            'headers' => $headers,
        ]);

        try {
            $response = $this->apiClient->request(
                'GET',
                $endpoint,
                [],
                null,
                $query,
                $headers
            );

            Log::channel('whatsapp')->info('Respuesta recibida de la API.', [
                'response' => $response,
            ]);

            $templateData = $response['data'][0] ?? null;

            if (!$templateData) {
                Log::channel('whatsapp')->warning('No se encontró la plantilla con el nombre especificado.', [
                    'template_name' => $templateName,
                ]);
                return null;
            }

            $this->validateTemplateData($templateData);

            // Almacenar o actualizar la plantilla en la base de datos
            $template = $this->storeOrUpdateTemplate($account, $templateData);

            // Crear nueva versión si la plantilla fue actualizada
            $this->createOrUpdateVersion($template, $templateData);

            return $template;
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error al obtener la plantilla por nombre.', [
                'error_message' => $e->getMessage(),
                'endpoint' => $endpoint,
                'template_name' => $templateName,
            ]);
            throw $e;
        }
    }

    /**
     * Obtiene TODAS las plantillas que coincidan parcialmente por el nombre desde la API de WhatsApp.
     * Nota: Si deseas obtener una coincidencia exacta por nombre y lenguaje, se recomienda usar getTemplateByNameLanguage.
     *
     * @param Model $account La cuenta empresarial de WhatsApp.
     * @param string $templateName El nombre de la plantilla.
     * @return array|null Las plantillas obtenidas o null si no existen.
     */
    public function getTemplatesByName(Model $account, string $templateName): ?array
    {
        if (!$account) {
            throw new InvalidArgumentException('La cuenta de WhatsApp es obligatoria.');
        }

        if (empty($templateName)) {
            throw new InvalidArgumentException('El nombre de la plantilla es obligatorio.');
        }

        $endpoint = Endpoints::build(Endpoints::GET_TEMPLATES, [
            'waba_id' => $account->whatsapp_business_id,
        ]);

        $query = [
            'name' => $templateName,
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $account->api_token,
        ];

        Log::channel('whatsapp')->info('Obteniendo plantilla por nombre desde la API.', [
            'endpoint' => $endpoint,
            'query' => $query,
            'headers' => $headers,
        ]);

        try {
            $response = $this->apiClient->request(
                'GET',
                $endpoint,
                [],
                null,
                $query,
                $headers
            );

            Log::channel('whatsapp')->info('Respuesta recibida de la API.', [
                'response' => $response,
            ]);

            $templatesData = $response['data'] ?? [];

            if (empty($templatesData)) {
                Log::channel('whatsapp')->warning('No se encontraron plantillas con el nombre especificado.', [
                    'template_name' => $templateName,
                ]);
                return null;
            }

            $templates = [];

            foreach( $templatesData as $templateData ){
                $this->validateTemplateData($templateData);

                // Almacenar o actualizar la plantilla en la base de datos
                $template = $this->storeOrUpdateTemplate($account, $templateData);

                // Crear nueva versión si la plantilla fue actualizada
                $this->createOrUpdateVersion($template, $templateData);
                $templates[] = $template;
            }

            return $templates;
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error al obtener la plantilla por nombre.', [
                'error_message' => $e->getMessage(),
                'endpoint' => $endpoint,
                'template_name' => $templateName,
            ]);
            throw $e;
        }
    }

    /**
     * Obtiene una plantilla por su nombre y lenguaje exacto desde la API de WhatsApp.
     *
     * @param Model $account La cuenta empresarial de WhatsApp.
     * @param string $templateName El nombre de la plantilla.
     * @param string $language El ID del idioma de la plantilla.
     * @return Model|null La plantilla obtenida o null si no existe.
     * @throws InvalidArgumentException Si los parámetros requeridos están vacíos.
     */
    public function getTemplateByNameLanguage(Model $account, string $templateName, string $language): ?Model
    {
        if (!$account) {
            throw new InvalidArgumentException('La cuenta de WhatsApp es obligatoria.');
        }

        if (empty($templateName)) {
            throw new InvalidArgumentException('El nombre de la plantilla es obligatorio.');
        }

        if (empty($language)) {
            throw new InvalidArgumentException('El lenguaje de la plantilla es obligatorio.');
        }

        $endpoint = Endpoints::build(Endpoints::GET_TEMPLATES, [
            'waba_id' => $account->whatsapp_business_id,
        ]);

        $query = [
            'name' => $templateName,
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $account->api_token,
        ];

        Log::channel('whatsapp')->info('Obteniendo plantilla por nombre y lenguaje exacto desde la API.', [
            'endpoint' => $endpoint,
            'query' => $query,
            'headers' => $headers,
        ]);

        try {
            $response = $this->apiClient->request(
                'GET',
                $endpoint,
                [],
                null,
                $query,
                $headers
            );

            // Filtrar la respuesta para encontrar la plantilla con el nombre y lenguaje exactos
            $templateData = collect($response['data'] ?? [])->first(function ($item) use ($templateName, $language) {
                return $item['name'] === $templateName && $item['language'] === $language;
            });

            Log::channel('whatsapp')->info('Respuesta recibida de la API.', [
                'response' => $response,
                'templateData' => $templateData,
            ]);

            if (!$templateData) {
                Log::channel('whatsapp')->warning('No se encontró la plantilla con el nombre especificado.', [
                    'template_name' => $templateName,
                ]);
                return null;
            }

            $this->validateTemplateData($templateData);

            // Almacenar o actualizar la plantilla en la base de datos
            $template = $this->storeOrUpdateTemplate($account, $templateData);

            // Crear nueva versión si la plantilla fue actualizada
            $this->createOrUpdateVersion($template, $templateData);

            return $template;
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error al obtener la plantilla por nombre.', [
                'error_message' => $e->getMessage(),
                'endpoint' => $endpoint,
                'template_name' => $templateName,
                'language' => $language,
            ]);
            throw $e;
        }
    }

    /**
     * Crea una plantilla de utilidad.
     *
     * @param Model $account La cuenta empresarial de WhatsApp.
     * @return TemplateBuilder El constructor de plantillas.
     */
    public function createUtilityTemplate(Model $account): TemplateBuilder
    {
        return (new TemplateBuilder($this->apiClient, $account, $this, $this->flowService))
            ->setCategory('UTILITY'); // Categoría específica para plantillas transaccionales
    }

    /**
     * Crea una plantilla de marketing.
     *
     * @param Model $account La cuenta empresarial de WhatsApp.
     * @return TemplateBuilder El constructor de plantillas.
     */
    public function createMarketingTemplate(Model $account): TemplateBuilder
    {
        return (new TemplateBuilder($this->apiClient, $account, $this, $this->flowService))
            ->setCategory('MARKETING'); // Categoría específica para plantillas de marketing
    }

    /**
     * Crea una plantilla de autenticación.
     *
     * @param Model $account La cuenta empresarial de WhatsApp.
     * @return TemplateBuilder El constructor de plantillas.
     */
    public function createAuthenticationTemplate(Model $account): TemplateBuilder
    {
        return (new TemplateBuilder($this->apiClient, $account, $this, $this->flowService))
            ->setCategory('AUTHENTICATION'); // Categoría específica para plantillas de autenticación
    }

    /**
     * Elimina una plantilla por su ID.
     *
     * @param Model $account La cuenta empresarial de WhatsApp.
     * @param string $templateId El ID de la plantilla.
     * @param bool $hardDelete Indica si se debe realizar un borrado permanente.
     * @return bool True si la plantilla fue eliminada, false en caso contrario.
     */
    public function deleteTemplateById(Model $account, string $templateId, bool $hardDelete = false): bool
    {
        $template = WhatsappModelResolver::template()->where('wa_template_id', $templateId)->first();

        if (!$template) {
            Log::channel('whatsapp')->error('La plantilla no existe!', [
                'wa_template_id' => $templateId
            ]);

            return false;
        }

        $endpoint = Endpoints::build(Endpoints::DELETE_TEMPLATE, [
            'waba_id' => $account->whatsapp_business_id,
        ]);

        $query = [
            'hsm_id' => $templateId,
            'name' => $template->name
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $account->api_token,
        ];

        Log::channel('whatsapp')->info('Eliminando plantilla por ID desde la API.', [
            'endpoint' => $endpoint,
            'query' => $query,
            'headers' => $headers,
        ]);

        try {
            $response = $this->apiClient->request(
                'DELETE',
                $endpoint,
                [],
                null,
                $query,
                $headers
            );

            Log::channel('whatsapp')->info('Respuesta recibida de la API.', [
                'response' => $response,
            ]);

            if ($response['success'] ?? false) {
                if ($template) {
                    $template->flows()->detach();
                    if ($hardDelete) {
                        $template->forceDelete(); // Hard delete
                    } else {
                        $template->delete(); // Soft delete
                    }
                }

                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error al eliminar la plantilla por ID.', [
                'error_message' => $e->getMessage(),
                'template_id' => $templateId,
            ]);
            throw $e;
        }
    }

    /**
     * Elimina una plantilla por su nombre.
     *
     * @param Model $account La cuenta empresarial de WhatsApp.
     * @param string $templateName El nombre de la plantilla.
     * @param bool $hardDelete Indica si se debe realizar un borrado permanente.
     * @return bool True si la plantilla fue eliminada, false en caso contrario.
     */
    public function deleteTemplateByName(Model $account, string $templateName, bool $hardDelete = false): bool
    {
        $template = WhatsappModelResolver::template()->where('name', $templateName)->first();

        if (!$template) {
            Log::channel('whatsapp')->error('La plantilla no existe!', [
                'name' => $templateName
            ]);

            return false;
        }

        $endpoint = Endpoints::build(Endpoints::DELETE_TEMPLATE, [
            'waba_id' => $account->whatsapp_business_id,
        ]);

        $query = [
            'name' => $templateName
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $account->api_token,
        ];

        Log::channel('whatsapp')->info('Eliminando plantilla por nombre desde la API.', [
            'endpoint' => $endpoint,
            'query' => $query,
            'headers' => $headers,
        ]);

        try {
            $response = $this->apiClient->request(
                'DELETE',
                $endpoint,
                [],
                null,
                $query,
                $headers
            );

            Log::channel('whatsapp')->info('Respuesta recibida de la API.', [
                'response' => $response,
            ]);

            if ($response['success'] ?? false) {
                if ($template) {
                    $template->flows()->detach();
                    if ($hardDelete) {
                        $template->forceDelete(); // Hard delete
                    } else {
                        $template->delete(); // Soft delete
                    }
                }

                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error al eliminar la plantilla por nombre.', [
                'error_message' => $e->getMessage(),
                'template_name' => $templateName,
            ]);
            throw $e;
        }
    }

    /**
     * Crea un mensaje de plantilla para enviar.
     *
     * @param Model $phone Numero de telefono de WhatsApp.
     * @return TemplateMessageBuilder El constructor del mensaje de plantilla.
     */
    public function sendTemplateMessage(Model $phone): TemplateMessageBuilder
    {
        return new TemplateMessageBuilder($this->apiClient, $phone, $this);
    }

    /**
     * Crea o actualiza una plantilla en la base de datos.
     *
     * @param string $businessId El ID de la cuenta empresarial.
     * @param array $templateData Los datos de la plantilla.
     * @return Model La plantilla creada o actualizada.
     */
    protected function storeOrUpdateTemplate(Model $account, array $templateData): Model
    {
        Log::channel('whatsapp')->info('Procesando plantilla.', [
            'template_id' => $templateData['id'],
            'template_name' => $templateData['name'],
        ]);

        $template = WhatsappModelResolver::template()->updateOrCreate(
            [
                'wa_template_id' => $templateData['id'],
            ],
            [
                'whatsapp_business_id' => $account->whatsapp_business_id,
                'name' => $templateData['name'],
                'language' => $templateData['language'],
                'category_id' => $this->getCategoryId($templateData['category']),
                'status' => $templateData['status'],
                'json' => json_encode($templateData),
            ]
        );

        Log::channel('whatsapp')->info('Plantilla guardada en la base de datos.', [
            'template_id' => $template->template_id,
        ]);

        // Sincronizar los componentes de la plantilla
        $this->syncTemplateComponents($account, $template, $templateData['components'] ?? []);

        return $template;
    }

    /**
     * Sincroniza la relación entre plantilla y flujo
     */
    protected function syncTemplateFlowRelation(Model $account, string $templateId, string $apiFlowId, string $buttonLabel): ?Model
    {
        try {
            // Validar que el flujo existe en la base de datos
            $flow = $this->flowService->getFlowById($apiFlowId);
            if (!$flow) {
                Log::channel('whatsapp')->error('Flujo no encontrado en la base de datos', [
                    'flow_id' => $apiFlowId,
                    'template_id' => $templateId
                ]);
                return null;
            }

            // Usar el ULID local del flujo
            WhatsappModelResolver::template_flow()->updateOrCreate(
                [
                    'template_id' => $templateId,
                    'flow_id' => $flow->flow_id, // ULID local
                ],
                [
                    'flow_button_label' => $buttonLabel,
                ]
            );

            return $flow;
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error en syncTemplateFlowRelation', [
                'error' => $e->getMessage(),
                'account_id' => $account->whatsapp_business_id,
                'template_id' => $templateId,
                'flow_id' => $apiFlowId
            ]);
            return null;
        }
    }

    protected function getLanguageId(string $templateData): string
    {
        $language = WhatsappModelResolver::template_language()->find($templateData);

        return $language->category_id;
    }

    /**
     * Obtiene el ID de la categoría o la crea si no existe.
     *
     * @param string $categoryName El nombre de la categoría.
     * @return string El ID de la categoría.
     */
    protected function getCategoryId(string $categoryName): string
    {
        $category = WhatsappModelResolver::template_category()->firstOrCreate(
            ['name' => $categoryName],
            ['description' => ucfirst($categoryName)]
        );

        Log::channel('whatsapp')->info('Categoría procesada.', [
            'category_id' => $category->category_id,
            'category_name' => $category->name,
        ]);

        return $category->category_id;
    }

    /**
     * Sincroniza los componentes de una plantilla.
     *
     * @param Model $template La plantilla a sincronizar.
     * @param array $components Los componentes de la plantilla.
     * @return void
     */
    protected function syncTemplateComponents(Model $account, Model $template, array $components): void
    {
        Log::channel('whatsapp')->info('Sincronizando componentes de la plantilla.', [
            'template_id' => $template->template_id,
            'component_count' => count($components),
        ]);

        try {
            $currentLocalFlowIds = []; // Para relaciones actuales

            foreach ($components as $componentData) {
                $type = strtolower($componentData['type']);
                if ($type === 'buttons') {
                    $type = 'button';
                }

                // 1. Sincronizar componente principal
                $component = WhatsappModelResolver::template_component()->updateOrCreate(
                    [
                        'template_id' => $template->template_id,
                        'type' => $type,
                    ],
                    [
                        'content' => $this->getComponentContent($componentData),
                        'parameters' => $componentData['parameters'] ?? [],
                    ]
                );

                // 2. Sincronizar relaciones con flujos (si es componente de botones)
                if ($type === 'button' && isset($componentData['buttons'])) {
                    foreach ($componentData['buttons'] as $button) {
                        if ($button['type'] === 'FLOW' && isset($button['flow_id'])) {
                            $flow = $this->syncTemplateFlowRelation(
                                $account,
                                $template->template_id,
                                $button['flow_id'],
                                $button['text'] ?? 'Iniciar flujo'
                            );

                            // Almacenar ULID local en lugar de ID de API
                            if ($flow) {
                                $currentLocalFlowIds[] = $flow->flow_id;
                            }
                        }
                    }
                }
            }

            // 3. Eliminar relaciones obsoletas
            WhatsappModelResolver::template_flow()->where('template_id', $template->template_id)
                ->whereNotIn('flow_id', $currentLocalFlowIds)
                ->delete();

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error al sincronizar componentes.', [
                'template_id' => $template->template_id,
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Obtiene el contenido de un componente según su tipo.
     *
     * @param array $componentData Los datos del componente.
     * @return array|null El contenido del componente o null si no aplica.
     */
    protected function getComponentContent(array $componentData): ?array
    {
        Log::channel('whatsapp')->info('Obteniendo contenido del componente.', [
            'component_type' => $componentData['type'],
        ]);

        switch ($componentData['type']) {
            case 'HEADER':
                return [
                    'format' => $componentData['format'] ?? null,
                    'text' => $componentData['text'] ?? null,
                    'example' => $componentData['example'] ?? null,
                ];
            case 'BODY':
            case 'FOOTER':
                return [
                    'text' => $componentData['text'] ?? null,
                ];
            case 'BUTTONS':
                return [
                    'buttons' => $componentData['buttons'] ?? [],
                ];
            default:
                return null;
        }
    }

    /**
     * Valida los datos de una plantilla.
     *
     * @param array $templateData Los datos de la plantilla.
     * @return void
     * @throws InvalidArgumentException Si los datos no son válidos.
     */
    protected function validateTemplateData(array $templateData): void
    {
        if (empty($templateData['name'])) {
            throw new InvalidArgumentException('El nombre de la plantilla es obligatorio.');
        }

        if (empty($templateData['language'])) {
            throw new InvalidArgumentException('El idioma de la plantilla es obligatorio.');
        }

        if (empty($templateData['category'])) {
            throw new InvalidArgumentException('La categoría de la plantilla es obligatoria.');
        }

        if (empty($templateData['components']) || !is_array($templateData['components'])) {
            throw new InvalidArgumentException('Los componentes de la plantilla son obligatorios.');
        }

        $validCategories = ['AUTHENTICATION', 'MARKETING', 'UTILITY'];

        if (!in_array($templateData['category'], $validCategories)) {
            throw new InvalidArgumentException('Categoría inválida');
        }

        if (isset($templateData['parameter_format'])) {
            $validFormats = ['POSITIONAL', 'NAMED'];
            if (!in_array($templateData['parameter_format'], $validFormats)) {
                throw new InvalidArgumentException('Formato de parámetro inválido');
            }
        }
    }

    /**
     * Crea una sesión de carga para un archivo.
     *
     * @param Model $account La cuenta empresarial de WhatsApp.
     * @param string $filePath La ruta del archivo.
     * @param string $mimeType El tipo MIME del archivo.
     * @return string El ID de la sesión de carga.
     * @throws \RuntimeException Si ocurre un error durante la creación de la sesión.
     */
    public function createUploadSession(Model $account, string $filePath, string $mimeType): string
    {
        // Extraer solo el nombre del archivo
        $fileName = basename($filePath);

        // Obtener el App ID (Usar el de la cuenta, o el global si es nulo por registro embebido)
        $appId = !empty($account->app_id) ? $account->app_id : config('whatsapp.meta_auth.client_id');

        if (empty($appId)) {
            throw new \RuntimeException("No se encontró un App ID válido para crear la sesión de carga. Verifica la configuración 'whatsapp.meta_auth.client_id'.");
        }

        // Construir la URL completa
        $baseUrl = config('whatsapp.api.base_url', 'https://graph.facebook.com');
        $version = config('whatsapp.api.version', 'v22.0');
        $url = rtrim($baseUrl, '/') . '/' . ltrim($version, '/') . '/' . $appId . '/uploads';

        $headers = [
            'Authorization: Bearer ' . $account->api_token,
            'Content-Type: application/json',
        ];

        $body = [
            'file_name'   => $fileName,
            'file_type'   => $mimeType,
            'file_length' => filesize($filePath),
        ];

        Log::channel('whatsapp')->info('Creando sesión de carga.', [
            'url' => $url,
            'body' => $body,
        ]);

        // Configurar cURL
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        // Ejecutar la solicitud
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        // Manejar errores de cURL
        if (curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException("Error en cURL: $error");
        }

        curl_close($curl);

        // Registrar la respuesta
        Log::channel('whatsapp')->info('Respuesta de la API al crear la sesión de carga.', [
            'response' => $response,
            'http_code' => $httpCode,
        ]);

        // Validar el código de respuesta HTTP
        if ($httpCode !== 200) {
            throw new \RuntimeException("Error al crear la sesión de carga. Código HTTP: $httpCode. Respuesta: $response");
        }

        // Decodificar la respuesta JSON
        $responseData = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Error al decodificar la respuesta JSON: ' . json_last_error_msg());
        }

        // Obtener el ID de la sesión de carga
        $uploadSessionId = $responseData['id'] ?? null;

        if (!$uploadSessionId) {
            throw new \Exception('No se pudo obtener el ID de la sesión de carga.');
        }

        Log::channel('whatsapp')->info('Sesión de carga creada exitosamente.', ['uploadSessionId' => $uploadSessionId]);

        return $uploadSessionId;
    }

    /**
     * Sube un archivo a la sesión de carga.
     *
     * @param Model $account La cuenta empresarial de WhatsApp.
     * @param string $sessionId El ID de la sesión de carga.
     * @param string $filePath La ruta del archivo.
     * @param string $mimeType El tipo MIME del archivo.
     * @return string El identificador del archivo subido.
     * @throws InvalidArgumentException Si el archivo no existe.
     * @throws \RuntimeException Si ocurre un error durante la carga.
     */
    public function uploadMediaOriginWilfredo(Model $account, string $sessionId, string $filePath, string $mimeType): string
    {
        // Validar que el archivo exista
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("El archivo no existe: $filePath");
        }

        // Construir la URL completa
        $baseUrl = config('whatsapp.api.base_url', 'https://graph.facebook.com');
        $version = config('whatsapp.api.version', 'v22.0');
        $url = rtrim($baseUrl, '/') . '/' . ltrim($version, '/') . "/$sessionId";

        Log::channel('whatsapp')->info('URL final para la carga de medios:', ['url' => $url]);

        // Leer el contenido del archivo
        $fileContents = file_get_contents($filePath);

        // Configurar cURL
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $fileContents,
            CURLOPT_HTTPHEADER => [
                'file_offset: 0',
                'Content-Type: application/octet-stream',
                'Authorization: OAuth ' . $account->api_token,
            ],
        ]);

        // Ejecutar la solicitud
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        // Manejar errores de cURL
        if (curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException("Error en cURL: $error");
        }

        curl_close($curl);

        // Registrar la respuesta
        Log::channel('whatsapp')->info('Respuesta de la API después de subir el archivo.', [
            'response' => $response,
            'http_code' => $httpCode,
        ]);

        // Validar el código de respuesta HTTP
        if ($httpCode !== 200) {
            throw new \RuntimeException("Error al subir el archivo. Código HTTP: $httpCode. Respuesta: $response");
        }

        // Decodificar la respuesta JSON
        $responseData = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Error al decodificar la respuesta JSON: ' . json_last_error_msg());
        }

        // Obtener el identificador del archivo
        $handle = $responseData['h'] ?? null;

        if (!$handle) {
            throw new \Exception('No se pudo obtener el identificador del archivo.');
        }

        Log::channel('whatsapp')->info('Archivo subido exitosamente.', ['handle' => $handle]);

        return $handle;
    }

    /**
     * Sube un archivo a la sesión de carga.
     *
     * @param Model $account La cuenta empresarial de WhatsApp.
     * @param string $sessionId El ID de la sesión de carga.
     * @param string $filePath La ruta del archivo.
     * @param string $mimeType El tipo MIME del archivo.
     * @return string El identificador del archivo subido.
     * @throws InvalidArgumentException Si el archivo no existe.
     * @throws \RuntimeException Si ocurre un error durante la carga.
     */
    public function uploadMedia(Model $account, string $sessionId, string $filePath, string $mimeType, int $maxRetries = 3): string
    {
        // Validar que el archivo exista
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("El archivo no existe: $filePath");
        }

        $fileSize = filesize($filePath);

        // Limpiar sessionId para remover prefijo duplicado 'upload:'
        $cleanSessionId = str_starts_with($sessionId, 'upload:') ? substr($sessionId, 7) : $sessionId;
        $baseUrl = config('whatsapp.api.base_url', 'https://graph.facebook.com');
        $version = config('whatsapp.api.version', 'v22.0');
        $url = rtrim($baseUrl, '/') . '/' . ltrim($version, '/') . "/upload:$cleanSessionId";

        Log::channel('whatsapp')->info('Iniciando carga de archivo (resumable).', [
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'url' => $url,
            'session_id' => $sessionId,
        ]);

        // Según documentación Meta Graph API: cada petición debe enviar todos los bytes
        // restantes desde file_offset. Si se interrumpe, se consulta el offset con GET
        // y se reanuda desde ese punto.
        $fileOffset = 0;
        $retryCount = 0;
        $noProgressCount = 0;  // Contador de intentos sin avance de offset
        $maxNoProgressAttempts = 3;  // Fallar si no avanzamos en 3 intentos consecutivos
        $lastProgressOffset = 0;  // Último offset donde hubo progreso real

        while ($retryCount < $maxRetries) {
            $fileHandle = fopen($filePath, 'rb');

            if ($fileHandle === false) {
                throw new \RuntimeException("No se pudo abrir el archivo para lectura: $filePath");
            }

            try {
                fseek($fileHandle, $fileOffset);
                $bytesRemaining = $fileSize - $fileOffset;

                $progressPercent = ($fileOffset / $fileSize) * 100;
                Log::channel('whatsapp')->info('Enviando chunk de archivo.', [
                    'file_offset'      => $fileOffset,
                    'bytes_remaining'  => $bytesRemaining,
                    'progress_percent' => round($progressPercent, 2),
                    'session_id'       => $sessionId,
                ]);

                // Usamos Guzzle en lugar de cURL directo porque Guzzle usa internamente
                // CURLOPT_POST + CURLOPT_POSTFIELDSIZE + CURLOPT_READFUNCTION, que es la
                // única combinación binary-safe garantizada: POSTFIELDSIZE informa el tamaño
                // exacto a libcurl sin que tenga que usar strlen() en C (que se detiene en
                // bytes nulos 0x00 presentes en archivos MP4 desde el primer megabyte).
                // LimitStream informa a Guzzle exactamente cuántos bytes enviar, lo que
                // también permite reanudar correctamente desde un offset específico.
                $phpStream     = \GuzzleHttp\Psr7\Utils::streamFor($fileHandle);
                $limitedStream = new \GuzzleHttp\Psr7\LimitStream($phpStream, $bytesRemaining);

                $guzzle = new \GuzzleHttp\Client(['http_errors' => false]);
                $guzzleResponse = $guzzle->request('POST', $url, [
                    'headers' => [
                        'Authorization' => 'OAuth ' . $account->api_token,
                        'file_offset'   => (string) $fileOffset,
                        'Content-Type'  => $mimeType,
                        'Content-Length'=> (string) $bytesRemaining,
                    ],
                    'expect'          => false,
                    'body'            => $limitedStream,
                    'timeout'         => 120,
                    'connect_timeout' => 30,
                ]);

                // Desvinculamos el handle del wrapper antes del fclose manual.
                $phpStream->detach();
                if (is_resource($fileHandle)) {
                    fclose($fileHandle);
                }

                $httpCode = $guzzleResponse->getStatusCode();
                $response = (string) $guzzleResponse->getBody();

                //dd($fileOffset, $bytesRemaining, $sessionId, $url, $mimeType, $response, $httpCode);

                Log::channel('whatsapp')->debug('Respuesta recibida de Meta.', [
                    'http_code'       => $httpCode,
                    'bytes_expected'  => $bytesRemaining,
                    'response_preview'=> substr($response, 0, 500),
                    'session_id'      => $sessionId,
                ]);

                // HTTP 200/201: Verificar que se subieron TODOS los bytes antes de confirmar
                if ($httpCode === 200 || $httpCode === 201) {
                    // Consultar el offset para verificar que la carga se completó realmente
                    $uploadOffsetData = $this->getUploadOffset($account, $sessionId);
                    $finalOffset = $uploadOffsetData['offset'] ?? null;

                    // Si no se puede confirmar offset, no se acepta como éxito.
                    if ($finalOffset === null) {
                        throw new \RuntimeException(
                            'Meta respondió éxito HTTP, pero no se pudo confirmar file_offset. Reintentando para evitar archivo truncado.'
                        );
                    }

                    // Detectar si hubo progreso
                    if ($finalOffset !== null && $finalOffset > $lastProgressOffset) {
                        $lastProgressOffset = $finalOffset;
                        $noProgressCount = 0;  // Reiniciar contador de estancamiento
                    } else {
                        $noProgressCount++;
                    }

                    // Fallar si no avanzamos después de varios intentos
                    if ($noProgressCount >= $maxNoProgressAttempts) {
                        throw new \RuntimeException(
                            "La carga se estancó. El offset no avanza desde: $finalOffset / $fileSize bytes. " .
                            "Meta no está aceptando más datos. Posibles causas: " .
                            "(1) Sesión expirada, (2) Archivo corrupto, (3) Token revocado."
                        );
                    }

                    if ($finalOffset < $fileSize) {
                        // No se subieron todos los bytes, reanudar la carga
                        Log::channel('whatsapp')->warning('HTTP 200 pero offset incompleto. Reanudando carga.', [
                            'final_offset' => $finalOffset,
                            'expected_size' => $fileSize,
                            'no_progress_count' => $noProgressCount,
                            'session_id' => $sessionId,
                        ]);
                        $fileOffset = $finalOffset;
                        $retryCount = 0;
                        continue;
                    }

                    // Offset completo, obtener el handle
                    $responseData = json_decode($response, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \RuntimeException('JSON inválido: ' . json_last_error_msg());
                    }

                    // Priorizar el handle canónico consultado por sesión ya completada.
                    // Si no está disponible, usar el retornado en la respuesta del POST.
                    $rawHandle = $responseData['h'] ?? null;
                    $handle = $rawHandle;
                    //$handle = $this->normalizeUploadHandle($rawHandle);

                    //dd($responseData, $rawHandle, $handle, $finalOffset, $uploadOffsetData);

                    if (!$handle) {
                        throw new \RuntimeException('No se pudo obtener el identificador del archivo.');
                    }

                    Log::channel('whatsapp')->info('Archivo subido exitosamente. Carga completada al 100%.', [
                        'handle' => $handle,
                        'file_size' => $fileSize,
                        'final_offset' => $finalOffset,
                        'mime_type' => $mimeType,
                        'session_id' => $sessionId,
                        'total_attempts' => ($retryCount + 1),
                    ]);

                    return $handle;
                }

                // HTTP 206: Carga parcial aceptada — según Meta, verificar offset y reanudar
                if ($httpCode === 206) {
                    $uploadOffsetData = $this->getUploadOffset($account, $sessionId);
                    $partialOffset = $uploadOffsetData['offset'] ?? null;

                    if ($partialOffset !== null && $partialOffset >= 0) {
                        Log::channel('whatsapp')->info('HTTP 206: carga parcial aceptada, consultando offset alcanzado.', [
                            'previous_offset' => $fileOffset,
                            'returned_offset' => $partialOffset,
                            'session_id' => $sessionId,
                        ]);

                        // Si el offset retornado es mayor, avanzamos desde ahí
                        if ($partialOffset > $fileOffset) {
                            $fileOffset = $partialOffset;
                            $retryCount = 0;
                            continue;
                        }
                    }

                    throw new \RuntimeException("HTTP 206 pero no se pudo obtener el offset válido.");
                }

                // Cualquier otro código HTTP es un error
                throw new \RuntimeException("HTTP Error $httpCode. Respuesta: " . substr($response, 0, 500));

            } catch (\Exception $e) {
                if (is_resource($fileHandle)) {
                    fclose($fileHandle);
                }

                $retryCount++;

                Log::channel('whatsapp')->warning("Error en intento $retryCount/$maxRetries. Verificando offset alcanzado.", [
                    'error'          => $e->getMessage(),
                    'current_offset' => $fileOffset,
                    'session_id'     => $sessionId,
                ]);

                if ($retryCount >= $maxRetries) {
                    Log::channel('whatsapp')->error('Error crítico en uploadMedia: máximos reintentos alcanzados.', [
                        'error_message' => $e->getMessage(),
                        'file_path'     => $filePath,
                        'session_id'    => $sessionId,
                        'last_offset'   => $fileOffset,
                        'file_size'     => $fileSize,
                    ]);

                    throw new \RuntimeException("Falló la carga después de $maxRetries intentos. " . $e->getMessage());
                }

                // Antes de reintentar, consultar el offset alcanzado según Meta docs
                $uploadOffsetData = $this->getUploadOffset($account, $sessionId);
                $recoveredOffset = $uploadOffsetData['offset'] ?? null;

                // Detectar si hubo progreso
                if ($recoveredOffset !== null && $recoveredOffset > $lastProgressOffset) {
                    $lastProgressOffset = $recoveredOffset;
                    $noProgressCount = 0;  // Reiniciar contador
                } else {
                    $noProgressCount++;
                }

                // Fallar si estamos estancados
                if ($noProgressCount >= $maxNoProgressAttempts) {
                    throw new \RuntimeException(
                        "ESTANCAMIENTO DETECTADO: El offset no avanza desde: " . ($recoveredOffset ?? $fileOffset) . " / $fileSize bytes. " .
                        "Después de {$noProgressCount} intentos consecutivos sin progreso. " .
                        "Verifica: (1) que el token sea válido, (2) que la sesión no haya expirado, (3) que el archivo no sea corrupto."
                    );
                }

                if ($recoveredOffset !== null && $recoveredOffset > $fileOffset) {
                    Log::channel('whatsapp')->info('Offset recuperado de la sesión. Reanudando carga.', [
                        'previous_offset' => $fileOffset,
                        'recovered_offset' => $recoveredOffset,
                        'no_progress_count' => $noProgressCount,
                        'session_id' => $sessionId,
                    ]);
                    $fileOffset = $recoveredOffset;
                } else {
                    Log::channel('whatsapp')->warning('No se pudo recuperar offset, reintentando desde último offset conocido.', [
                        'file_offset' => $fileOffset,
                        'recovered_offset' => $recoveredOffset,
                        'no_progress_count' => $noProgressCount,
                        'session_id' => $sessionId,
                    ]);
                }

                // Backoff exponencial: esperar entre reintentos
                $waitSeconds = min(30, 2 ** ($retryCount - 1));
                Log::channel('whatsapp')->info("Esperando $waitSeconds segundos antes de reintentar.", [
                    'retry_count' => $retryCount,
                    'session_id' => $sessionId,
                ]);
                usleep($waitSeconds * 1000000);
            }
        }

        throw new \RuntimeException("Falló la carga después de $maxRetries intentos.");
    }


    // public function uploadMedia(Model $account, string $sessionId, string $filePath, string $mimeType): string
    // {
    //     // Validar que el archivo exista
    //     if (!file_exists($filePath)) {
    //         throw new InvalidArgumentException("El archivo no existe: $filePath");
    //     }

    //     // Construir la URL completa
    //     $baseUrl = config('whatsapp.api.base_url', 'https://graph.facebook.com');
    //     $version = config('whatsapp.api.version', 'v22.0');
    //     $url = rtrim($baseUrl, '/') . '/' . ltrim($version, '/') . "/$sessionId";

    //     Log::channel('whatsapp')->info('=== INICIO UPLOAD MEDIA ===', [
    //         'url' => $url,
    //         'session_id' => $sessionId,
    //         'file_path' => $filePath,
    //         'file_size' => filesize($filePath),
    //         'mime_type' => $mimeType
    //     ]);

    //     // Leer el contenido del archivo
    //     $fileContents = file_get_contents($filePath);
    //     $fileSize = filesize($filePath);

    //     // Configurar cURL
    //     $curl = curl_init();

    //     $headers = [
    //         'Authorization: Bearer ' . $account->api_token,
    //         'file_offset: 0',
    //         'Content-Type: ' . $mimeType,
    //         'Content-Length: ' . $fileSize,
    //     ];

    //     curl_setopt_array($curl, [
    //         CURLOPT_URL => $url,
    //         CURLOPT_RETURNTRANSFER => true,
    //         CURLOPT_ENCODING => '',
    //         CURLOPT_MAXREDIRS => 1,
    //         CURLOPT_TIMEOUT => 60,
    //         CURLOPT_FOLLOWLOCATION => false,
    //         CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    //         CURLOPT_CUSTOMREQUEST => 'POST',
    //         CURLOPT_POSTFIELDS => $fileContents,
    //         CURLOPT_HTTPHEADER => $headers,
    //         CURLOPT_SSL_VERIFYPEER => true,
    //         CURLOPT_SSL_VERIFYHOST => 2,
    //     ]);

    //     // Ejecutar la solicitud
    //     $response = curl_exec($curl);
    //     $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    //     $curlError = curl_error($curl);
    //     $curlErrno = curl_errno($curl);

    //     curl_close($curl);

    //     // LOG DETALLADO
    //     Log::channel('whatsapp')->info('=== RESPONSE DEBUG ===', [
    //         'http_code' => $httpCode,
    //         'curl_error' => $curlError,
    //         'curl_errno' => $curlErrno,
    //         'response_size' => strlen($response),
    //     ]);

    //     // Validar respuesta
    //     if ($httpCode !== 200) {
    //         throw new \RuntimeException("HTTP Error: $httpCode. Curl: $curlError. Response: $response");
    //     }

    //     if ($curlErrno) {
    //         throw new \RuntimeException("cURL Error ($curlErrno): $curlError");
    //     }

    //     // ANALIZAR LA RESPUESTA
    //     $this->analyzeUploadResponse($response, $sessionId, $filePath);

    //     // Decodificar JSON
    //     $responseData = json_decode($response, true);

    //     if (json_last_error() !== JSON_ERROR_NONE) {
    //         Log::channel('whatsapp')->error('JSON decode error:', [
    //             'error' => json_last_error_msg(),
    //             'raw_body' => $response
    //         ]);
    //         throw new \RuntimeException('Invalid JSON response: ' . json_last_error_msg());
    //     }

    //     $handle = $responseData['h'] ?? null;

    //     if (!$handle) {
    //         throw new \Exception('No handle in response. Response: ' . $response);
    //     }

    //     // Validar que sea un único handle
    //     if (is_string($handle) && substr_count($handle, "\n") > 0) {
    //         $handle = $this->handleMultipleHandles($handle, $sessionId, $filePath);
    //     }

    //     Log::channel('whatsapp')->info('=== UPLOAD COMPLETED ===', [
    //         'handle' => $handle,
    //         'handle_length' => strlen($handle)
    //     ]);

    //     return $handle;
    // }

    // /**
    //  * Analiza la respuesta del upload en detalle
    //  */
    // protected function analyzeUploadResponse(string $body, string $sessionId, string $filePath): void
    // {
    //     $lineCount = substr_count($body, "\n") + 1;
    //     $isJson = json_decode($body, true) !== null;

    //     Log::channel('whatsapp')->info('=== RESPONSE ANALYSIS ===', [
    //         'line_count' => $lineCount,
    //         'is_valid_json' => $isJson,
    //         'first_200_chars' => substr($body, 0, 200),
    //     ]);

    //     if ($lineCount > 1) {
    //         Log::channel('whatsapp')->warning('Multiple lines detected in response', [
    //             'lines' => explode("\n", $body),
    //         ]);
    //     }
    // }

    // /**
    //  * Maneja el caso de múltiples handles
    //  */
    // protected function handleMultipleHandles(string $handle, string $sessionId, string $filePath): string
    // {
    //     $handles = explode("\n", $handle);
    //     $validHandles = array_filter(array_map('trim', $handles), function($h) {
    //         return !empty($h) && preg_match('/^\d+:/', $h);
    //     });

    //     Log::channel('whatsapp')->error('MULTIPLE HANDLES DETECTED - USING FIRST AS WORKAROUND', [
    //         'total_handles' => count($handles),
    //         'valid_handles' => count($validHandles),
    //         'all_handles' => $handles
    //     ]);

    //     if (!empty($validHandles)) {
    //         $firstHandle = $validHandles[0];
    //         Log::channel('whatsapp')->warning('WORKAROUND: Using first valid handle', [
    //             'selected_handle' => $firstHandle
    //         ]);
    //         return $firstHandle;
    //     }

    //     throw new \RuntimeException("No valid handles found in multiple handles response");
    // }

    /**
     * Valida un archivo multimedia antes de subirlo.
     *
     * @param string $filePath La ruta del archivo.
     * @param string $mimeType El tipo MIME del archivo.
     * @return void
     * @throws InvalidArgumentException Si el archivo no es válido.
     */
    public function validateMediaFile(string $filePath, string $mimeType): void
    {
        $fileSize = filesize($filePath);
        $mediaType = $this->getMediaTypeFromMimeType($mimeType);

        // Obtener configuraciones desde whatsapp.php
        $maxFileSize = config("whatsapp.media.max_file_size.$mediaType");
        $allowedMimeTypes = config("whatsapp.media.allowed_types.$mediaType");

        // Validar tamaño del archivo
        if ($fileSize > $maxFileSize) {
            throw new InvalidArgumentException("El archivo excede el tamaño máximo permitido de " . ($maxFileSize / 1024 / 1024) . " MB.");
        }

        // Validar tipo MIME del archivo
        if (!in_array($mimeType, $allowedMimeTypes)) {
            throw new InvalidArgumentException("El tipo de archivo no es permitido. Tipo recibido: $mimeType.");
        }

        Log::channel('whatsapp')->info('Archivo validado correctamente.', [
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
        ]);
    }

    /**
     * Prepara media para que sea aceptada por WhatsApp templates.
     *
     * Para video, fuerza H.264 + AAC cuando detecta codecs incompatibles (ej. HEVC).
     * Devuelve la ruta/mimetype final, si requiere limpieza de archivo temporal, e indica si hubo cambios.
     *
     * @return array{file_path:string,mime_type:string,cleanup:bool,changed:bool}
     */
    public function prepareMediaForTemplateUpload(string $filePath, string $mimeType): array
    {
        $mediaType = $this->getMediaTypeFromMimeType($mimeType);

        // El proceso de verificación/transcodificación por codecs solo se activa
        // cuando el integrador marca explícitamente que quiere usar ffmpeg.
        $ffmpegFeatureEnabled = (bool) config('whatsapp.package_ffmpeg_installed', false);

        if (!$ffmpegFeatureEnabled) {
            Log::channel('whatsapp')->info('La verificación de codecs está deshabilitada. Se subirá el archivo tal cual.', [
                'file_path' => $filePath,
                'mime_type' => $mimeType,
                'media_type' => $mediaType,
            ]);
            return [
                'file_path' => $filePath,
                'mime_type' => $mimeType,
                'cleanup' => false,
                'changed' => false,
            ];
        }

        if ($mediaType !== 'video') {
            Log::channel('whatsapp')->info('El archivo no es video, no se requiere verificación de codecs. Se subirá tal cual.', [
                'file_path' => $filePath,
                'mime_type' => $mimeType,
                'media_type' => $mediaType,
            ]);
            return [
                'file_path' => $filePath,
                'mime_type' => $mimeType,
                'cleanup' => false,
                'changed' => false,
            ];
        }

        $codecs = $this->probeVideoCodecs($filePath);
        $videoCodec = strtolower((string) ($codecs['video_codec'] ?? ''));
        $audioCodec = strtolower((string) ($codecs['audio_codec'] ?? ''));

        $isVideoCompatible = in_array($videoCodec, ['h264', 'avc1'], true);
        $isAudioCompatible = ($audioCodec === '' || in_array($audioCodec, ['aac', 'mp4a'], true));

        if ($isVideoCompatible && $isAudioCompatible) {
            return [
                'file_path' => $filePath,
                'mime_type' => $mimeType,
                'cleanup' => false,
                'changed' => false,
            ];
        }

        if (!$this->isFfmpegBinaryAvailable()) {
            throw new InvalidArgumentException(
                'El video usa codecs no compatibles con Meta (se requiere H.264/AAC) y ffmpeg no está disponible para convertirlo automáticamente.'
            );
        }

        $convertedPath = $this->buildMetaCompatibleTempPath($filePath);

        $command = sprintf(
            'ffmpeg -y -i %s -c:v libx264 -pix_fmt yuv420p -profile:v high -level 4.1 -c:a aac -b:a 128k -movflags +faststart %s 2>&1',
            escapeshellarg($filePath),
            escapeshellarg($convertedPath)
        );

        $output = shell_exec($command);

        if (!is_file($convertedPath) || filesize($convertedPath) === false || filesize($convertedPath) <= 0) {
            throw new InvalidArgumentException(
                'No se pudo convertir el video a H.264/AAC para WhatsApp. ' . trim((string) $output)
            );
        }

        Log::channel('whatsapp')->warning('Video convertido a formato compatible para template.', [
            'source_path' => $filePath,
            'converted_path' => $convertedPath,
            'source_video_codec' => $videoCodec,
            'source_audio_codec' => $audioCodec,
        ]);

        return [
            'file_path' => $convertedPath,
            'mime_type' => 'video/mp4',
            'cleanup' => true,
            'changed' => true,
        ];
    }

    /**
     * @return array{video_codec:?string,audio_codec:?string}
     */
    protected function probeVideoCodecs(string $filePath): array
    {
        $videoCodec = null;
        $audioCodec = null;

        if (!$this->isFfprobeBinaryAvailable()) {
            return [
                'video_codec' => $videoCodec,
                'audio_codec' => $audioCodec,
            ];
        }

        $videoCmd = sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
            escapeshellarg($filePath)
        );
        $audioCmd = sprintf(
            'ffprobe -v error -select_streams a:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
            escapeshellarg($filePath)
        );

        $videoOut = trim((string) shell_exec($videoCmd));
        $audioOut = trim((string) shell_exec($audioCmd));

        if ($videoOut !== '') {
            $videoCodec = strtolower(explode("\n", $videoOut)[0]);
        }
        if ($audioOut !== '') {
            $audioCodec = strtolower(explode("\n", $audioOut)[0]);
        }

        return [
            'video_codec' => $videoCodec,
            'audio_codec' => $audioCodec,
        ];
    }

    protected function isFfmpegBinaryAvailable(): bool
    {
        $output = shell_exec('ffmpeg -version 2>&1');
        return is_string($output) && stripos($output, 'ffmpeg version') !== false;
    }

    protected function isFfprobeBinaryAvailable(): bool
    {
        $output = shell_exec('ffprobe -version 2>&1');
        return is_string($output) && stripos($output, 'ffprobe version') !== false;
    }

    protected function buildMetaCompatibleTempPath(string $filePath): string
    {
        $dir = pathinfo($filePath, PATHINFO_DIRNAME);
        $name = pathinfo($filePath, PATHINFO_FILENAME);
        return $dir . DIRECTORY_SEPARATOR . $name . '.meta_h264_aac.mp4';
    }

    /**
     * Obtiene el tipo de medio a partir del tipo MIME.
     *
     * @param string $mimeType El tipo MIME del archivo.
     * @return string El tipo de medio (por ejemplo, "image", "audio").
     * @throws InvalidArgumentException Si no se puede determinar el tipo de medio.
     */
    protected function getMediaTypeFromMimeType(string $mimeType): string
    {
        // Mapear tipos MIME a categorías de medios
        $mediaTypes = config('whatsapp.media.allowed_types', []);

        foreach ($mediaTypes as $type => $mimeTypes) {
            if (in_array($mimeType, $mimeTypes)) {
                return $type;
            }
        }

        throw new InvalidArgumentException("No se pudo determinar el tipo de media para el MIME: $mimeType.");
    }

    /**
     * Consulta el offset alcanzado en una sesión de carga reanudable.
     *
     * @param Model $account La cuenta empresarial de WhatsApp.
     * @param string $sessionId El ID de la sesión de carga.
     * @return int|null El offset en bytes o null si no se pudo obtener.
     */
    protected function getUploadOffset(Model $account, string $sessionId): ?array
    {
        $cleanSessionId = str_starts_with($sessionId, 'upload:') ? substr($sessionId, 7) : $sessionId;
        $baseUrl = config('whatsapp.api.base_url', 'https://graph.facebook.com');
        $version = config('whatsapp.api.version', 'v22.0');
        $url = rtrim($baseUrl, '/') . '/' . ltrim($version, '/') . "/upload:$cleanSessionId";

        $headers = [
            'Authorization: OAuth ' . $account->api_token,
            'Authorization: Bearer ' . $account->api_token,
        ];

        $curl = curl_init();

        $response = null;
        $httpCode = 0;
        $curlErrno = 0;
        $curlError = '';

        // Intentar primero OAuth y luego Bearer para máxima compatibilidad.
        foreach ($headers as $authHeader) {
            curl_setopt_array($curl, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_HTTPGET        => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => [$authHeader],
            ]);

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($curl);
            $curlError = curl_error($curl);

            if ($curlErrno === 0 && $httpCode === 200) {
                break;
            }
        }

        curl_close($curl);

        Log::channel('whatsapp')->debug('getUploadOffset: consultando offset.', [
            'url'        => $url,
            'session_id' => $sessionId,
            'http_code'  => $httpCode,
        ]);

        if ($curlErrno) {
            Log::channel('whatsapp')->error('getUploadOffset: error cURL.', [
                'curl_errno'  => $curlErrno,
                'error'       => $curlError,
                'session_id'  => $sessionId,
            ]);
            return null;
        }

        if ($httpCode !== 200) {
            Log::channel('whatsapp')->error('getUploadOffset: respuesta inesperada del servidor.', [
                'http_code'  => $httpCode,
                'response'   => substr($response, 0, 500),
                'session_id' => $sessionId,
            ]);
            return null;
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::channel('whatsapp')->error('getUploadOffset: JSON inválido en respuesta.', [
                'json_error' => json_last_error_msg(),
                'response'   => $response,
                'session_id' => $sessionId,
            ]);
            return null;
        }

        if (!isset($data['file_offset'])) {
            Log::channel('whatsapp')->error('getUploadOffset: respuesta no contiene file_offset.', [
                'response'   => $response,
                'session_id' => $sessionId,
            ]);
            return null;
        }

        $offset = (int) $data['file_offset'];

        Log::channel('whatsapp')->debug('getUploadOffset: offset recuperado.', [
            'response'    => $response,
            'file_offset' => $offset,
            'session_id'  => $sessionId,
        ]);

        return ['offset' => $offset, 'data' => $data];
    }



    /**
     * Normaliza el valor del handle preservando el contenido exacto devuelto por Meta.
     *
     * Importante: cuando Meta devuelve múltiples líneas en `h`, ese bloque completo
     * puede representar el handle compuesto. No se debe partir en primero/último.
     */
    protected function normalizeUploadHandle(?string $rawHandle): ?string
    {
        if ($rawHandle === null) {
            return null;
        }

        $normalized = trim((string) $rawHandle);

        if ($normalized === '') {
            return null;
        }

        /*$lineCount = substr_count($normalized, "\n") + 1;
        if ($lineCount > 1) {
            Log::channel('whatsapp')->info('Meta devolvió handle multilínea; se preserva completo.', [
                'lines' => $lineCount,
            ]);
        }*/

        return $normalized;
    }
}
