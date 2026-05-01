<?php
namespace ScriptDevelop\WhatsappManager\Services;

//use ScriptDevelop\WhatsappManager\Models\WhatsappPhoneNumber;
//use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;
use InvalidArgumentException;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ScriptDevelop\WhatsappManager\WhatsappApi\ApiClient;
use ScriptDevelop\WhatsappManager\Exceptions\WhatsappApiException;
use ScriptDevelop\WhatsappManager\Helpers\CountryCodes;
use ScriptDevelop\WhatsappManager\WhatsappApi\Endpoints;
//use ScriptDevelop\WhatsappManager\Models\Contact;
//use ScriptDevelop\WhatsappManager\Models\Message;
//use ScriptDevelop\WhatsappManager\Models\Template;
use ScriptDevelop\WhatsappManager\Enums\MessageStatus;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use ScriptDevelop\WhatsappManager\Support\WhatsappModelResolver;

class TemplateMessageBuilder
{
    protected Model $account;
    protected Model $phone;
    protected ApiClient $apiClient;
    public ?Model $template = null;
    protected TemplateService $templateService;
    protected string $phoneNumber = '';
    protected ?string $bsuid = null;
    protected string $templateIdentifier; // Puede ser nombre
    protected ?string $templateId = null; // ID de plantilla (opcional, se puede usar el nombre)
    protected ?string $language = null; // Opcional
    protected array $components = [];
    protected array $templateStructure = []; // Estructura de la plantilla
    protected ?Model $contact = null;
    protected array $buttonParameters = [];
    protected array $buttonTextIndexMap = [];
    protected ?Model $templateVersion = null;

    protected string $parameterFormat = 'POSITIONAL';

    /**
     * Constructor de la clase TemplateMessageBuilder.
     *
     * @param ApiClient $apiClient Cliente API para realizar solicitudes.
     * @param Model $phone Número de teléfono de WhatsApp.
     * @param TemplateService $templateService Servicio para gestionar plantillas.
     */
    public function __construct(ApiClient $apiClient, Model $phone, TemplateService $templateService, ?string $versionId = null)
    {
        $this->phone = $phone;
        $this->apiClient = $apiClient;
        $this->templateService = $templateService;

        // Si se especificó una versión, cargarla
        if ($versionId) {
            $this->templateVersion = WhatsappModelResolver::template_version()->find($versionId);
        }
    }

    /**
     * Especifica la versión de plantilla a usar.
     */
    public function withVersion(string $versionId): self
    {
        $this->templateVersion = WhatsappModelResolver::template_version()->find($versionId);
        return $this;
    }

    /**
     * Establece el número de teléfono del destinatario.
     *
     * @param string $phoneNumber El número de teléfono sin el código de país.
     * @param string $countryCode El código de país (por ejemplo, "57" para Colombia).
     * @return self
     * @throws InvalidArgumentException Si el número de teléfono o el código de país no son válidos.
     */
    public function to(string $countryCode, string $phoneNumber,): self
    {
        $codes = CountryCodes::codes();

        if (!in_array($countryCode, $codes)) {
            throw new InvalidArgumentException("El código de país '$countryCode' no es válido.");
        }

        $cleanedPhoneNumber = preg_replace('/\D/', '', $phoneNumber);

        $normalizedPhone  = CountryCodes::normalizeInternationalPhone($countryCode, $cleanedPhoneNumber);
        $cleanedPhoneNumber = $normalizedPhone['phoneNumber'];

        $this->phoneNumber = $normalizedPhone['fullPhoneNumber'];

        $this->contact = WhatsappModelResolver::contact()->updateOrCreate(
        ['wa_id' => $this->phoneNumber], // Buscar por wa_id (número completo)
            [
                'phone_number' => $cleanedPhoneNumber,
                'country_code' => $countryCode
            ]
        );

        return $this;
    }

    /**
     * Establece el destinatario usando su BSUID (identificador específico de empresa).
     * Usar cuando el usuario tiene username activo y no se conoce su número de teléfono.
     * Compatible con Meta API a partir de mayo 2026.
     *
     * @param string $bsuid Identificador de usuario (ej: "CO.1234567890")
     * @return self
     */
    public function toBsuid(string $bsuid): self
    {
        $this->bsuid = $bsuid;

        $this->contact = WhatsappModelResolver::contact()->firstOrCreate(
            ['bsuid' => $bsuid]
        );

        return $this;
    }

    /**
     * Establece el identificador de la plantilla (nombre).
     *
     * @param string $templateIdentifier El identificador de la plantilla.
     * @return self
     */
    public function usingTemplate(string $templateIdentifier, ?string $versionId = null, ?string $language = null): self
    {
        $this->templateIdentifier = $templateIdentifier;
        if( !empty($language) ) {
            $this->language = $language;
        }

        // Si se especificó una versión, cargarla
        if ($versionId) {
            $this->templateVersion = WhatsappModelResolver::template_version()->find($versionId);
        }

        $this->fetchTemplateStructure();
        return $this;
    }

    /**
     * Establece el identificador de la plantilla (nombre o ID).
     *
     * @param string $templateId El ID de la plantilla.
     * @return self
     */
    public function usingTemplateById(string $templateId, ?string $versionId = null): self
    {
        $this->templateId = $templateId;

        // Si se especificó una versión, cargarla
        if ($versionId) {
            $this->templateVersion = WhatsappModelResolver::template_version()->find($versionId);
        }

        $this->fetchTemplateStructure();
        return $this;
    }

    /**
     * Agrega un componente HEADER a la plantilla.
     *
     * @param string $type El tipo de HEADER (por ejemplo, "TEXT", "IMAGE").
     * @param mixed $content El contenido del HEADER.
     * @return self
     * @throws InvalidArgumentException Si el componente no es válido.
     */
    public function addHeader(string $type, $content): self
    {
        $this->ensureTemplateStructureLoaded();
        $this->validateComponent('HEADER', $type);

        $formattedParams = [];
        $type = strtoupper($type);

        if ($type === 'TEXT') {
            $placeholders = $this->templateStructure['placeholders']['HEADER'] ?? [];

            // Procesar parámetros usando el método unificado
            $formattedParams = $this->processParameters($placeholders, $content);
        }
        elseif (in_array($type, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
            // Validar que el contenido sea una URL válida
            if (!filter_var($content, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException(
                    "El contenido para $type debe ser una URL válida"
                );
            }

            $formattedParams[] = [
                'type' => strtolower($type),
                strtolower($type) => ['link' => $content]
            ];
        }
        elseif ($type === 'LOCATION') {
            // Location no lleva parámetros, pero validamos que no se pase contenido
            if (!empty($content)) {
                throw new InvalidArgumentException(
                    "El header de tipo LOCATION no debe tener contenido"
                );
            }

            // Location se representa con un array vacío
            $formattedParams[] = [
                'type' => 'location'
            ];
        }
        else {
            throw new InvalidArgumentException(
                "Tipo de header no válido: $type. Válidos: TEXT, IMAGE, VIDEO, DOCUMENT, LOCATION"
            );
        }

        $this->components['HEADER'] = [
            'parameters' => $formattedParams
        ];

        return $this;
    }

    /**
     * Agrega un componente BODY a la plantilla.
     *
     * @param array $parameters Los parámetros dinámicos para el BODY.
     * @return self
     * @throws InvalidArgumentException Si el componente no es válido.
     */
    public function addBody(array $parameters): self
    {
        $this->ensureTemplateStructureLoaded();
        $this->validateComponent('BODY');

        $placeholders = $this->templateStructure['placeholders']['BODY'] ?? [];
        $formattedParams = $this->processParameters($placeholders, $parameters);

        $this->components['BODY'] = [
            'type' => 'BODY',
            'parameters' => $formattedParams
        ];

        return $this;
    }

    /**
     * Agrega un componente FOOTER a la plantilla.
     *
     * @param string $text El texto del FOOTER.
     * @return self
     * @throws InvalidArgumentException Si el componente no es válido.
     */
    public function addFooter(string $text): self
    {
        $this->ensureTemplateStructureLoaded();
        $this->validateComponent('FOOTER');
        $this->components['FOOTER'] = [
            'text' => $text,
        ];
        return $this;
    }

    /**
     * Agrega la configuración Tap Target para sobrescribir la acción URL de una plantilla de mensaje
     * basada en texto, imagen o sin encabezado, permitiendo cambiar el enlace de llamada a la acción en caliente.
     *
     * @param string $url La URL de destino real
     * @param string $title El título del botón interactivo
     * @return self
     */
    public function addTapTargetConfiguration(string $url, string $title): self
    {
        $this->components['TAP_TARGET_CONFIGURATION'] = [
            'type' => 'TAP_TARGET_CONFIGURATION',
            'parameters' => [
                [
                    'type' => 'tap_target_configuration',
                    'tap_target_configuration' => [
                        [
                            'url' => $url,
                            'title' => $title
                        ]
                    ]
                ]
            ]
        ];

        return $this;
    }

    /**
     * Agrega un encabezado de Producto (SPM / Multi-product Cards)
     */
    public function addHeaderProduct(string $productRetailerId, string $catalogId): self
    {
        $this->ensureTemplateStructureLoaded();
        $this->validateComponent('HEADER', 'PRODUCT');

        $this->components['HEADER'] = [
            'parameters' => [
                [
                    'type' => 'product',
                    'product' => [
                        'product_retailer_id' => $productRetailerId,
                        'catalog_id' => $catalogId
                    ]
                ]
            ]
        ];

        return $this;
    }

    /**
     * Agrega el vencimiento para una oferta de tiempo limitado
     */
    public function addLimitedTimeOfferExpiration(int $timestampMs): self
    {
        $this->ensureTemplateStructureLoaded();

        $this->components['LIMITED_TIME_OFFER'] = [
            'parameters' => [
                [
                    'type' => 'limited_time_offer',
                    'limited_time_offer' => [
                        'expiration_time_ms' => $timestampMs
                    ]
                ]
            ]
        ];

        return $this;
    }

    /**
     * Inyecta una secuencia de tarjetas dinámicas al esquema
     */
    public function addCarouselCards(\Closure $callback): self
    {
        $this->ensureTemplateStructureLoaded();

        $builder = new Builders\CarouselMessageBuilder();
        $callback($builder);

        $this->components['CAROUSEL'] = [
            'cards' => $builder->toArray()
        ];

        return $this;
    }

    public function addButton(string $buttonText, array $parameter = []): self
    {
        $this->ensureTemplateStructureLoaded();

        if (empty($this->buttonTextIndexMap)) {
            $buttonsComponent = $this->templateStructure['by_type']['BUTTONS'] ??
                                $this->templateStructure['by_type']['buttons'] ??
                                null;

            if ($buttonsComponent && isset($buttonsComponent['buttons'])) {
                foreach ($buttonsComponent['buttons'] as $index => $button) {
                    $normalizedText = strtolower(trim($button['text']));
                    $this->buttonTextIndexMap[$normalizedText] = $index;
                }
            }
        }

        if (empty($this->buttonTextIndexMap)) {
            throw new InvalidArgumentException("La plantilla no contiene botones.");
        }

        $normalizedButtonText = strtolower(trim($buttonText));
        if (!isset($this->buttonTextIndexMap[$normalizedButtonText])) {
            $availableButtons = implode("', '", array_keys($this->buttonTextIndexMap));
            throw new InvalidArgumentException(
                "Botón '$buttonText' no encontrado. Botones disponibles: '$availableButtons'"
            );
        }

        $buttonIndex = $this->buttonTextIndexMap[$normalizedButtonText];
        $button = $this->templateStructure['by_type']['BUTTONS']['buttons'][$buttonIndex] ?? null;

        if (!$button) {
            throw new InvalidArgumentException("Botón '$buttonText' no encontrado en la estructura.");
        }

        if (strtoupper($button['type'] ?? '') !== 'URL') {
            throw new InvalidArgumentException("Solo botones URL pueden tener parámetros.");
        }

        preg_match_all('/{{(.*?)}}/', $button['url'] ?? '', $matches);
        $placeholders = $matches[1] ?? [];

        if (!empty($placeholders)) {
            // Botón dinámico: requiere parámetro
            if (count($parameter) !== 1 || empty($parameter[0])) {
                throw new InvalidArgumentException("El botón '$buttonText' requiere un parámetro dinámico para la URL (ejemplo: ->addButton('Visit website', ['valor'])).");
            }
            $this->buttonParameters[$buttonIndex] = [
                [
                    'type' => 'text',
                    'text' => $parameter[0]
                ]
            ];
        } else {
            // Botón estático: NO debe recibir parámetros
            if (!empty($parameter)) {
                throw new InvalidArgumentException("El botón '$buttonText' tiene una URL estática y no debe recibir parámetros.");
            }
            $this->buttonParameters[$buttonIndex] = [];
        }

        return $this;
    }

    /**
     * Inyecta tokens y datos adicionales (flow_action_data) a un botón FLOW de la plantilla.
     */
    public function addFlowActionData(string $buttonText, string $flowToken, array $flowActionData = []): self
    {
        $this->ensureTemplateStructureLoaded();

        if (empty($this->buttonTextIndexMap)) {
            $buttonsComponent = $this->templateStructure['by_type']['BUTTONS'] ??
                                $this->templateStructure['by_type']['buttons'] ??
                                null;

            if ($buttonsComponent && isset($buttonsComponent['buttons'])) {
                foreach ($buttonsComponent['buttons'] as $index => $button) {
                    $normalizedText = strtolower(trim($button['text']));
                    $this->buttonTextIndexMap[$normalizedText] = $index;
                }
            }
        }

        if (empty($this->buttonTextIndexMap)) {
            throw new InvalidArgumentException("La plantilla no contiene botones.");
        }

        $normalizedButtonText = strtolower(trim($buttonText));
        if (!isset($this->buttonTextIndexMap[$normalizedButtonText])) {
            $availableButtons = implode("', '", array_keys($this->buttonTextIndexMap));
            throw new InvalidArgumentException(
                "Botón '$buttonText' no encontrado. Botones disponibles: '$availableButtons'"
            );
        }

        $buttonIndex = $this->buttonTextIndexMap[$normalizedButtonText];
        $button = $this->templateStructure['by_type']['BUTTONS']['buttons'][$buttonIndex] ?? null;

        if (!$button) {
            throw new InvalidArgumentException("Botón '$buttonText' no encontrado en la estructura.");
        }

        if (strtoupper($button['type'] ?? '') !== 'FLOW') {
            throw new InvalidArgumentException("Solo botones FLOW pueden tener datos de acción ('flow_action_data').");
        }

        $actionData = [
            'flow_token' => $flowToken,
        ];

        // ── Crear sesión proactiva de flow ────────────────────────────
        // Si el token es placeholder ("unused") y tenemos phone + contact,
        // generamos un token único y creamos la sesión con flow_id correcto.
        $this->maybeCreateProactiveFlowSession($button, $flowToken, $actionData);
        // ────────────────────────────────────────────────────────────────

        if (!empty($flowActionData)) {
            $actionData['flow_action_data'] = $flowActionData;
        }

        $this->buttonParameters[$buttonIndex] = [
            [
                'type' => 'action',
                'action' => $actionData
            ]
        ];

        return $this;
    }

    /**
     * Si el flow_token es un placeholder y tenemos contexto suficiente,
     * genera un token único y crea la sesión proactiva con flow_id correcto.
     *
     * Esto garantiza que cuando llegue el nfm_reply, findOrCreateSession
     * encuentre la sesión por el token único y el flow_id ya esté poblado.
     */
    protected function maybeCreateProactiveFlowSession(array $button, string &$flowToken, array &$actionData): void
    {
        $waFlowId = $button['flow_id'] ?? null;
        if (!$waFlowId) {
            return;
        }

        // Solo intervenimos si el token es un placeholder
        $isPlaceholder = in_array($flowToken, ['unused', ''], true);
        if (!$isPlaceholder && !empty($flowToken)) {
            return;
        }

        try {
            $flow = \ScriptDevelop\WhatsappManager\Models\WhatsappFlow::where('wa_flow_id', $waFlowId)->first();
            if (!$flow) {
                return;
            }

            // Generar token único por destinatario
            $uniqueToken = hash('sha256', $flow->flow_id . $this->phoneNumber . now()->timestamp);

            $sessionService = app(\ScriptDevelop\WhatsappManager\Services\Flows\FlowSessionService::class);
            $sessionService->createProactive(
                flow:        $flow,
                phoneNumber: $this->phone,
                contact:     $this->contact,
                sendMethod:  'template',
                flowToken:   $uniqueToken
            );

            // Actualizar el token en el actionData para que el template
            // se envíe con el token único (el mismo que la sesión)
            $flowToken = $uniqueToken;
            $actionData['flow_token'] = $uniqueToken;

            Log::channel('whatsapp')->info('[TemplateMessageBuilder] Sesión proactiva creada', [
                'flow_id'     => $flow->flow_id,
                'wa_flow_id'  => $waFlowId,
                'flow_token'  => $uniqueToken,
                'recipient'   => $this->phoneNumber,
            ]);
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('[TemplateMessageBuilder] Error creando sesión proactiva: ' . $e->getMessage());
        }
    }

    /**
     * Inyecta variables para un Botón de Cupón (COPY_CODE)
     */
    public function addCouponButton(string $buttonText, string $couponCode): self
    {
        $this->ensureTemplateStructureLoaded();
        $buttonIndex = $this->getButtonIndexByType('COPY_CODE', $buttonText);

        $this->buttonParameters[$buttonIndex] = [
            [
                'type' => 'coupon_code',
                'coupon_code' => $couponCode
            ]
        ];

        return $this;
    }

    /**
     * Inyecta el ID del producto miniatura a un botón CATALOG
     */
    public function addCatalogButton(string $buttonText, string $thumbnailId): self
    {
        $this->ensureTemplateStructureLoaded();
        $buttonIndex = $this->getButtonIndexByType('CATALOG', $buttonText);

        $this->buttonParameters[$buttonIndex] = [
            [
                'type' => 'action',
                'action' => [
                    'thumbnail_product_retailer_id' => $thumbnailId
                ]
            ]
        ];

        return $this;
    }

    /**
     * Inyecta dependencias al botón nativo MPM (Catálogo Multi-Producto)
     */
    public function addMpmButton(string $buttonText, string $thumbnailId, \Closure $sectionsCallback): self
    {
        $this->ensureTemplateStructureLoaded();
        $buttonIndex = $this->getButtonIndexByType('MPM', $buttonText);

        $sectionBuilder = new Builders\CommerceSectionBuilder();
        $sectionsCallback($sectionBuilder);

        $this->buttonParameters[$buttonIndex] = [
            [
                'type' => 'action',
                'action' => [
                    'thumbnail_product_retailer_id' => $thumbnailId,
                    'sections' => $sectionBuilder->toArray()
                ]
            ]
        ];

        return $this;
    }

    /**
     * Helper privado para ubicar índice de botón tolerante a fallos
     */
    private function getButtonIndexByType(string $buttonType, string $buttonText): int
    {
        if (empty($this->buttonTextIndexMap)) {
            $buttonsComponent = $this->templateStructure['by_type']['BUTTONS'] ??
                                $this->templateStructure['by_type']['buttons'] ??
                                null;

            if ($buttonsComponent && isset($buttonsComponent['buttons'])) {
                foreach ($buttonsComponent['buttons'] as $index => $button) {
                    $normalizedText = strtolower(trim($button['text']));
                    $this->buttonTextIndexMap[$normalizedText] = $index;
                }
            }
        }

        if (empty($this->buttonTextIndexMap)) {
            throw new InvalidArgumentException("La plantilla no contiene botones.");
        }

        $normalizedButtonText = strtolower(trim($buttonText));
        if (!isset($this->buttonTextIndexMap[$normalizedButtonText])) {
            throw new InvalidArgumentException("Botón '$buttonText' no encontrado.");
        }

        $buttonIndex = $this->buttonTextIndexMap[$normalizedButtonText];
        $button = $this->templateStructure['by_type']['BUTTONS']['buttons'][$buttonIndex] ?? null;

        if (!$button || strtoupper($button['type'] ?? '') !== strtoupper($buttonType)) {
            throw new InvalidArgumentException("El botón '$buttonText' no es de tipo $buttonType.");
        }

        return $buttonIndex;
    }

    protected function buildButtonComponents(): array
    {
        $buttonComponents = [];

        if (empty($this->templateStructure['by_type']['BUTTONS']['buttons'])) {
            return [];
        }

        foreach ($this->templateStructure['by_type']['BUTTONS']['buttons'] as $index => $button) {
            $type = strtoupper($button['type'] ?? '');
            
            if ($type === 'URL') {
                $needsParams = preg_match('/\{\{\d+\}\}/', $button['url'] ?? '');

                if ($needsParams && isset($this->buttonParameters[$index])) {
                    $buttonComponents[] = [
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => (string)$index,
                        'parameters' => $this->buttonParameters[$index]
                    ];
                }
            } elseif ($type === 'FLOW') {
                if (isset($this->buttonParameters[$index])) {
                    $buttonComponents[] = [
                        'type' => 'button',
                        'sub_type' => 'flow',
                        'index' => (string)$index,
                        'parameters' => $this->buttonParameters[$index]
                    ];
                }
            } elseif (in_array($type, ['COPY_CODE', 'CATALOG', 'MPM'])) {
                if (isset($this->buttonParameters[$index])) {
                    $buttonComponents[] = [
                        'type' => 'button',
                        'sub_type' => strtolower($type),
                        'index' => (string)$index,
                        'parameters' => $this->buttonParameters[$index]
                    ];
                }
            }
        }

        return $buttonComponents;
    }

    protected function buttonRequiresParameters(array $button): bool
    {
        $type = strtoupper($button['type'] ?? '');

        // Solo botones de URL pueden tener parámetros dinámicos
        if ($type !== 'URL') {
            return false;
        }

        // Verificar si la URL contiene placeholders (ej: {{1}})
        return isset($button['url']) && preg_match('/\{\{\d+\}\}/', $button['url']);
    }

    /**
     * Envía el mensaje de plantilla.
     *
     * @return array La respuesta de la API de WhatsApp.
     * @throws InvalidArgumentException Si los datos no son válidos.
     * @throws WhatsappApiException Si ocurre un error al enviar el mensaje.
     */
    public function send(): array
    {
        // Consultar la estructura de la plantilla si es necesario
        $this->fetchTemplateStructure();

        // Validar los datos
        $this->validate();

        // Establecer el idioma desde la estructura de la plantilla
        $this->language = $this->templateStructure['language'] ?? throw new InvalidArgumentException('El idioma no está definido en la estructura de la plantilla.');

        // Construir el payload
        $payload = $this->buildPayload();

        // Enviar el mensaje
        return $this->sendMessage($payload);
    }

    /**
     * Valida los datos necesarios antes de enviar el mensaje.
     *
     * @return void
     * @throws InvalidArgumentException Si falta algún dato obligatorio.
     */
    protected function validate(): void
    {
        if (empty($this->phoneNumber) && empty($this->bsuid)) {
            throw new InvalidArgumentException('Se requiere un destinatario: usa to() con teléfono o toBsuid() con BSUID.');
        }

        if (empty($this->templateIdentifier)) {
            throw new InvalidArgumentException('El identificador de la plantilla es obligatorio.');
        }

        // ✅ Permitir envíos sin componentes si la plantilla no tiene placeholders
        $hasHeaderPlaceholders = !empty($this->templateStructure['placeholders']['HEADER']);
        $hasBodyPlaceholders = !empty($this->templateStructure['placeholders']['BODY']);
        $hasFooterText = !empty($this->components['FOOTER']['text']);
        $hasButtons = !empty($this->templateStructure['by_type']['BUTTONS']['buttons']);

        $hasDynamicComponents = $hasHeaderPlaceholders || $hasBodyPlaceholders || $hasFooterText;
        $hasUserComponents = !empty($this->components) || !empty($this->buttonParameters);

        // ❌ Solo exigir componentes si la plantilla tiene placeholders dinámicos
        if ($hasDynamicComponents && !$hasUserComponents) {
            throw new InvalidArgumentException('Debes incluir al menos un componente dinámico en el mensaje.');
        }
    }

    /**
     * Obtiene la estructura de la plantilla desde la base de datos.
     *
     * @return void
     * @throws InvalidArgumentException Si la plantilla no existe en la base de datos.
     */
    // protected function fetchTemplateStructure(): void
    // {
    //     // Buscar la plantilla en la base de datos
    //     $template = WhatsappModelResolver::template()->with('components')
    //         ->where('name', $this->templateIdentifier)
    //         ->where('whatsapp_business_id', $this->account->whatsapp_business_id)
    //         ->first();

    //     if (!$template) {
    //         throw new InvalidArgumentException("La plantilla '{$this->templateIdentifier}' no existe en la base de datos.");
    //     }

    //     // Construir la estructura de la plantilla a partir de los componentes
    //     $this->templateStructure = [
    //         'language' => $template->language,
    //         'HEADER' => $template->components->where('type', 'header')->first(),
    //         'BODY' => $template->components->where('type', 'body')->first(),
    //         'FOOTER' => $template->components->where('type', 'footer')->first(),
    //         'BUTTONS' => $template->components->where('type', 'button')->all(),
    //     ];

    //     Log::channel('whatsapp')->info('Estructura de la plantilla obtenida.', ['templateStructure' => $this->templateStructure]);
    // }

    protected function fetchTemplateStructure(): void
    {

        if(  empty($this->templateIdentifier) && empty($this->templateId) ) {
            throw new InvalidArgumentException("Debes establecer el identificador de la plantilla usando ->usingTemplate(...) o ->usingTemplateById(...) antes de enviar el mensaje.");
        }

        $template = WhatsappModelResolver::template()->where('whatsapp_business_id', $this->phone->businessAccount->whatsapp_business_id);

        if( !empty($this->templateId) ) {
            $template->where('template_id', $this->templateId);
        }
        else {
            $template->where('name', $this->templateIdentifier);

            if( !empty($this->language) ) {
                $template->where('language', $this->language);
            }
        }

        $template = $template->first();

        if (!$template) {
            if( !empty($this->templateId) ) {
                throw new InvalidArgumentException("Plantilla con ID '{$this->templateId}' no encontrada.");
            }
            else{
                throw new InvalidArgumentException("Plantilla '{$this->templateIdentifier}' no encontrada.");
            }
        }

        $this->templateIdentifier = $template->name; // Aseguramos que el identificador sea el nombre de la plantilla

        $this->template = $template;

        if (!$this->templateVersion) {
            $this->templateVersion = $this->template->activeVersion;

            if( !$this->templateVersion ) {
                throw new \Exception("No hay versión aprobada para la plantilla: {$this->templateIdentifier}");
            }
        }

        $rawStructure = $this->templateVersion->template_structure;

        $this->templateStructure = [
            'language' => $this->template->language,
            'components' => $rawStructure,
            'by_type' => [],
            'placeholders' => [
                'HEADER' => [],
                'BODY' => []
            ]
        ];

        // Procesar placeholders
        foreach ($rawStructure as $component) {
            $type = strtoupper($component['type'] ?? '');
            $this->templateStructure['by_type'][$type] = $component;

            // Extraer placeholders
            if ($type === 'HEADER' && isset($component['text'])) {
                preg_match_all('/{{(.*?)}}/', $component['text'], $matches);
                $this->templateStructure['placeholders']['HEADER'] = $matches[1] ?? [];
            }

            if ($type === 'BODY' && isset($component['text'])) {
                if (isset($component['example']['body_text_named_params'])) {
                    $this->parameterFormat = 'NAMED';
                }
                preg_match_all('/{{(.*?)}}/', $component['text'], $matches);
                $this->templateStructure['placeholders']['BODY'] = $matches[1] ?? [];
            }
        }
    }

    protected function processParameters(array $placeholders, array $values): array
    {
        $parameters = [];

        if ($this->parameterFormat === 'NAMED') {
            // Check if the provided array is sequential and not associative
            $isSequential = array_is_list(array_keys($values));
            //dd($isSequential, $placeholders, $values);

            if ($isSequential) {
                // Determine if they provided the exact number of parameters needed
                if (count($values) !== count($placeholders)) {
                    throw new InvalidArgumentException(
                        "Número de parámetros no coincide. Esperados (nombrados): " .
                        count($placeholders) . ", Recibidos: " . count($values)
                    );
                }

                // Map the sequential values to the named placeholders in order
                foreach ($placeholders as $placeholder) {
                    $parameters[] = [
                        'type' => 'text',
                        'parameter_name' => $placeholder,
                        'text' => $values[$placeholder]
                    ];
                }
            } else {
                foreach ($placeholders as $placeholder) {
                    if (!isset($values[$placeholder])) {
                        throw new InvalidArgumentException(
                            "Falta parámetro nombrado: '$placeholder'"
                        );
                    }
                    $parameters[] = [
                        'type' => 'text',
                        'parameter_name' => $placeholder,
                        'text' => $values[$placeholder]
                    ];
                }
            }
        } else {
            if (count($values) !== count($placeholders)) {
                throw new InvalidArgumentException(
                    "Número de parámetros no coincide. Esperados: " .
                    count($placeholders) . ", Recibidos: " . count($values)
                );
            }

            foreach ($values as $value) {
                $parameters[] = [
                    'type' => 'text',
                    'text' => $value
                ];
            }
        }

        return $parameters;
    }

    protected function processComponent(array $component, array &$structure): void
    {
        $type = strtoupper($component['type'] ?? '');

        switch ($type) {
            case 'HEADER':
                $structure['HEADER'] = [
                    'formats' => $component['format'] ?? ['TEXT'],
                    'example' => $component['example'] ?? []
                ];
                break;

            case 'BODY':
                $structure['BODY'] = $component;
                break;

            case 'FOOTER':
                $structure['FOOTER'] = $component;
                break;

            case 'BUTTONS':
                $buttons = $component['buttons'] ?? [];
                foreach ($buttons as $index => $button) {
                    $structure['BUTTONS'][$index] = [
                        'type' => $button['type'] ?? 'UNKNOWN',
                        'text' => $button['text'] ?? '',
                        'url' => $button['url'] ?? null,
                        'phone_number' => $button['phone_number'] ?? null
                    ];
                }
                break;
        }
    }

    /**
     * Valida un componente de la plantilla.
     *
     * @param string $componentType El tipo de componente (por ejemplo, "HEADER", "BODY").
     * @param string|null $subType El subtipo del componente (si aplica).
     * @return void
     * @throws InvalidArgumentException Si el componente no es válido.
     */
    protected function validateComponent(string $componentType, ?string $subType = null): void
    {
        $componentType = strtoupper($componentType);

        // Verificar si el componente existe en el índice por tipo
        if (!isset($this->templateStructure['by_type'][$componentType])) {
            throw new InvalidArgumentException("Componente '$componentType' no definido en la plantilla.");
        }

        // Validación especial para headers
        if ($componentType === 'HEADER' && $subType) {
            $headerComponent = $this->templateStructure['by_type']['HEADER'];
            $allowedFormats = $headerComponent['format'] ?? [];
            $allowedFormats = array_map('strtoupper', (array)$allowedFormats);

            if (!in_array(strtoupper($subType), $allowedFormats)) {
                throw new InvalidArgumentException("Formato '$subType' no permitido. Formatos válidos: " . implode(', ', $allowedFormats));
            }
        }
    }

    /**
     * Construye el payload para enviar el mensaje.
     *
     * @return array El payload construido.
     */
    protected function buildPayload(): array
    {
        $components = [];

        // Solo agrega los componentes que hayan modificado el cuerpo principal
        foreach (['HEADER', 'BODY', 'FOOTER', 'TAP_TARGET_CONFIGURATION', 'LIMITED_TIME_OFFER'] as $componentType) {
            if (isset($this->components[$componentType]) && !empty($this->components[$componentType]['parameters'])) {
                $components[] = [
                    'type' => strtolower($componentType),
                    'parameters' => $this->components[$componentType]['parameters']
                ];
            }
        }

        // CAROUSEL tiene su propia lógica de Cards
        if (isset($this->components['CAROUSEL']) && !empty($this->components['CAROUSEL']['cards'])) {
            $components[] = [
                'type' => 'carousel',
                'cards' => $this->components['CAROUSEL']['cards']
            ];
        }

        // ✅ Solo agrega botones si la plantilla tiene placeholders o el usuario los define
        $buttonComponents = $this->buildButtonComponents();
        if (!empty($buttonComponents)) {
            $components = array_merge($components, $buttonComponents);
        }

        // Base del payload — soporta envío por teléfono, por BSUID, o ambos
        // (Meta da prioridad a 'to' si ambos están presentes)
        $base = ['messaging_product' => 'whatsapp', 'type' => 'template'];

        if (!empty($this->phoneNumber)) {
            $base['to'] = $this->phoneNumber;
        }
        if (!empty($this->bsuid)) {
            $base['recipient'] = $this->bsuid;
        }

        // ✅ Si no hay componentes ni placeholders, envía sin "components"
        if (empty($components)) {
            $payload = array_merge($base, [
                'template' => [
                    'name' => $this->templateIdentifier,
                    'language' => ['code' => $this->language],
                ]
            ]);
        } else {
            $payload = array_merge($base, [
                'template' => [
                    'name' => $this->templateIdentifier,
                    'language' => ['code' => $this->language],
                    'components' => $components
                ]
            ]);
        }

        Log::channel('whatsapp')->info('Payload construido para el mensaje de plantilla.', ['payload' => $payload]);

        return $payload;
    }

    /**
     * Envía el mensaje a través de la API de WhatsApp.
     *
     * @param array $payload El payload del mensaje.
     * @return array La respuesta de la API de WhatsApp.
     * @throws WhatsappApiException Si ocurre un error al enviar el mensaje.
     */
    protected function sendMessage(array $payload): array
    {
        // $endpoint = Endpoints::build(Endpoints::SEND_MESSAGE);

        $endpoint = Endpoints::build(Endpoints::SEND_MESSAGE, [
            'phone_number_id' => $this->phone->api_phone_number_id,
        ]);

        Log::channel('whatsapp')->info('Enviando mensaje de plantilla.', [
            'endpoint' => $endpoint,
            'payload' => $payload,
            'phone_number' => $this->phoneNumber,
        ]);

        //$contact = WhatsappModelResolver::contact()->where('wa_id', $this->phoneNumber)->first(); //Es innecesario, ya se creó/actualizó dentro del método "to", no tiene caso volver a llamar a la base de datos

        $message = WhatsappModelResolver::message()->create([
            'whatsapp_phone_id' => $this->phone->phone_number_id,
            'contact_id' => $this->contact->contact_id,
            'message_from' => preg_replace('/[\D+]/', '', $this->phone->display_phone_number),
            'message_to' => $this->contact->wa_id, //Se corrige esta variable, usar $this->contact en lugar de $contact
            'message_type' => 'template',
            'message_content' => NULL,
            'message_method' => 'OUTPUT',
            'status' => MessageStatus::PENDING,
            'template_version_id' => $this->templateVersion->version_id, // <-- AQUÍ GUARDAMOS LA VERSIÓN
            'json_template_payload' => json_encode(['payload' => $payload], JSON_UNESCAPED_UNICODE),
        ]);


        $response = $this->apiClient->request(
            'POST',
            $endpoint,
            data: $payload,
            headers: [
                'Authorization' => 'Bearer ' . $this->phone->businessAccount->api_token,
                'Content-Type' => 'application/json',
            ]
        );

        if (!isset($response['messages'][0]['message_status']) || $response['messages'][0]['message_status'] !== 'accepted') {
            Log::channel('whatsapp')->error('Error al enviar el mensaje de plantilla.', [
                'endpoint' => $endpoint,
                'payload' => $payload,
                'response' => $response,
            ]);

            $errorData = $response['error'] ?? ['message' => 'Estado desconocido o mensaje no creado'];
            throw new WhatsappApiException('Error al enviar el mensaje.', $errorData);

            // throw new WhatsappApiException('Error al enviar el mensaje.', $response['error'] ?? []);
        }

        $message->update([
            'wa_id' => $response['messages'][0]['id'] ?? null,
            'status' => MessageStatus::SENT,
            'json' => $response
        ]);

        Log::channel('whatsapp')->info('Mensaje enviado exitosamente.', ['response' => $response]);

        // Despachar evento para que listeners como CreateFlowSessionOnTemplateSent
        // puedan crear sesiones proactivas de flow si el template tiene botones FLOW.
        // Envuelto en try/catch para que un fallo en el listener NO rompa
        // la respuesta HTTP al frontend (el mensaje ya fue enviado a Meta).
        try {
            $eventClass = config('whatsapp.events.template.sent');
            if ($eventClass && class_exists($eventClass)) {
                Event::dispatch(new $eventClass($this, $payload, $response));
            }
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning(
                '[TemplateMessageBuilder] Error despachando TemplateMessageSent: ' . $e->getMessage()
            );
        }

        return $response;
    }

    protected function ensureTemplateStructureLoaded(): void
    {
        if (empty($this->templateStructure)) {
            throw new InvalidArgumentException("Debes establecer la plantilla usando ->usingTemplate(...) antes de agregar componentes.");
        }
    }

    // ── Helpers públicos para listeners ──────────────────────────────────

    /**
     * Phone number model usado para enviar el template.
     */
    public function getPhone(): Model
    {
        return $this->phone;
    }

    /**
     * Contacto destinatario (puede ser null si se envió sin contacto).
     */
    public function getContact(): ?Model
    {
        return $this->contact;
    }

    /**
     * Teléfono del destinatario en formato internacional (E.164).
     */
    public function getRecipientPhone(): string
    {
        return $this->phoneNumber;
    }

    /**
     * Retorna los botones FLOW del template con su índice original.
     *
     * @return array<int, array{index: int, flow_id: string, text: string}>
     */
    public function getFlowButtons(): array
    {
        $buttons = $this->templateStructure['by_type']['BUTTONS']['buttons'] ?? [];

        $flowButtons = [];
        foreach ($buttons as $index => $button) {
            if (strtoupper($button['type'] ?? '') === 'FLOW' && !empty($button['flow_id'])) {
                $flowButtons[] = [
                    'index'   => $index,
                    'flow_id' => $button['flow_id'],
                    'text'    => $button['text'] ?? '',
                ];
            }
        }

        return $flowButtons;
    }

    /**
     * Extrae el flow_token configurado para un botón FLOW específico.
     * Retorna null si no se configuró ninguno.
     */
    public function getButtonToken(int $index): ?string
    {
        $params = $this->buttonParameters[$index] ?? null;
        if (!$params || !isset($params[0]['action']['flow_token'])) {
            return null;
        }

        return $params[0]['action']['flow_token'];
    }
}