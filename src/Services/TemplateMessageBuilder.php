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
use ScriptDevelop\WhatsappManager\Support\WhatsappModelResolver;

class TemplateMessageBuilder
{
    protected Model $account;
    protected Model $phone;
    protected ApiClient $apiClient;
    protected ?Model $template = null;
    protected TemplateService $templateService;
    protected string $phoneNumber;
    protected string $templateIdentifier; // Puede ser nombre o ID
    protected ?string $language = null; // Opcional
    protected array $components = [];
    protected array $templateStructure = []; // Estructura de la plantilla
    protected ?Model $contact = null;
    protected array $buttonParameters = [];
    protected array $buttonTextIndexMap = [];
    protected ?Model $templateVersion = null;

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
     * Obtiene la versión aprobada más reciente.
     */
    protected function getLatestApprovedTemplateVersion(string $templateName): ?Model
    {
        $template = WhatsappModelResolver::template()
            ->where('name', $templateName)
            ->first();

        if (!$template) return null;

        return $template->versions()
            ->where('status', 'APPROVED')
            ->latest()
            ->first();
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
     * Establece el identificador de la plantilla (nombre o ID).
     *
     * @param string $templateIdentifier El identificador de la plantilla.
     * @return self
     */
    public function usingTemplate(string $templateIdentifier, ?string $versionId = null): self
    {
        $this->templateIdentifier = $templateIdentifier;

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

        if( $type=='IMAGE' ){
            //foreach ($content as $item) {
                $formattedParams[] = [
                    'type' => 'image',
                    'image' => [
                        'link' => $content
                    ]
                ];
            //}

            $this->components['HEADER']['type'] = 'header';
        }
        else{
            if (!is_array($content)) {
                $content = [$content];
            }

            foreach ($content as $item) {
                $formattedParams[] = [
                    'type' => 'text',
                    'link' => $item
                ];
            }

            $this->components['HEADER']['type'] = $type;
        }

        $this->components['HEADER']['parameters'] = $formattedParams;

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

        // Formatear cada parámetro como objeto
        $formattedParams = [];
        foreach ($parameters as $param) {
            $formattedParams[] = [
                'type' => 'text', // Asumiendo que todos son texto
                'text' => $param
            ];
        }

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



    public function addButton(string $buttonText, array $parameters): self
    {
        $this->ensureTemplateStructureLoaded();

        // Crear mapa texto->índice si no existe
        if (empty($this->buttonTextIndexMap)) {
            foreach ($this->templateStructure['BUTTONS']['content']['buttons'] as $index => $button) {
                $this->buttonTextIndexMap[$button['text']] = $index;
            }
        }

        if (!isset($this->buttonTextIndexMap[$buttonText])) {
            $availableButtons = implode("', '", array_keys($this->buttonTextIndexMap));
            throw new InvalidArgumentException(
                "Botón '$buttonText' no encontrado. Botones disponibles: '$availableButtons'"
            );
        }

        $this->buttonParameters[$this->buttonTextIndexMap[$buttonText]] = $parameters;
        return $this;
    }

    protected function buildButtonComponents(): array
    {
        $buttonComponents = [];

        if (!isset($this->templateStructure['BUTTONS']['content']['buttons'])) {
            return [];
        }

        foreach ($this->templateStructure['BUTTONS']['content']['buttons'] as $index => $button) {
            $buttonType = strtoupper($button['type'] ?? '');
            $subType = strtolower($buttonType);

            // Solo agregar componente si tiene parámetros dinámicos
            if ($this->buttonRequiresParameters($button) && isset($this->buttonParameters[$index])) {
                $parameters = [];
                foreach ($this->buttonParameters[$index] as $param) {
                    $parameters[] = [
                        'type' => 'text',
                        'text' => $param
                    ];
                }

                $buttonComponents[] = [
                    'type' => 'button',
                    'sub_type' => $subType,
                    'index' => $index,
                    'parameters' => $parameters
                ];
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
        // Obtener versión si no se especificó
        if (!$this->templateVersion) {
            $this->templateVersion = $this->getLatestApprovedTemplateVersion($this->templateIdentifier);
        }

        if (!$this->templateVersion) {
            throw new \Exception("No hay versión aprobada para la plantilla: {$this->templateIdentifier}");
        }


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
        if (empty($this->phoneNumber)) {
            throw new InvalidArgumentException('El número de teléfono es obligatorio.');
        }

        if (empty($this->templateIdentifier)) {
            throw new InvalidArgumentException('El identificador de la plantilla es obligatorio.');
        }

        if (empty($this->components)) {
            throw new InvalidArgumentException('Debe incluir al menos un componente en el mensaje.');
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
        $template = WhatsappModelResolver::template()
            ->with('components')
            ->where('name', $this->templateIdentifier)
            ->where('whatsapp_business_id', $this->phone->businessAccount->whatsapp_business_id)
            ->first();

        if (!$template) {
            throw new InvalidArgumentException("Plantilla '{$this->templateIdentifier}' no encontrada.");
        }

        // Obtener versión activa si no se especificó
        if (!$this->templateVersion) {
            $this->templateVersion = $template->activeVersion;
        }

        $this->templateStructure = [
            'template_version_structure' => $this->templateVersion->template_structure, //No se si sea necesario esto para mas adelante, sino solo hay que comentar esta línea
            'language' => $template->language,
            'HEADER' => $template->components->where('type', 'header')->first()->toArray(),
            'BODY' => $template->components->where('type', 'body')->first()->toArray(),
            'FOOTER' => $template->components->where('type', 'footer')->first()->toArray(),
            'BUTTONS' => $template->components->where('type', 'button')->first()->toArray(),
        ];

        // Cargar la estructura desde la versión
        //$this->templateStructure = $this->templateVersion->template_structure;

        Log::channel('whatsapp')->info('Estructura de plantilla cargada desde versión', [
            'version_id' => $this->templateVersion->version_id,
            'structure' => $this->templateStructure
        ]);
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

        if (!isset($this->templateStructure[$componentType])) {
            // Si no está en la estructura principal, verificar en los componentes
            $found = false;
            foreach ($this->templateStructure['components'] ?? [] as $component) {
                if (strtoupper($component['type'] ?? '') === $componentType) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                throw new InvalidArgumentException("Componente '$componentType' no definido en la plantilla.");
            }
        }

        // Validación especial para headers
        if ($componentType === 'HEADER' && $subType) {
            $allowedFormats = $this->templateStructure['HEADER']['content']['format'] ?? [];
            $allowedFormats = array_map('strtoupper', (array)$allowedFormats);

            if (!in_array(strtoupper($subType), $allowedFormats)) {
                throw new InvalidArgumentException("Formato '$subType' no permitido para el header. Formatos válidos: " . implode(', ', $allowedFormats));
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

        //Aquí hay problema, no se están agregando los componentes de HEADER, BODY y FOOTER
        dd($this->templateStructure);

        // Procesar HEADER, BODY, FOOTER
        foreach ($this->templateStructure['components'] ?? [] as $componentDef) {
            $componentType = strtoupper($componentDef['type'] ?? '');

            if (isset($this->components[$componentType])) {
                $components[] = [
                    'type' => strtolower($componentType),
                    'parameters' => $this->components[$componentType]['parameters'] ?? []
                ];
            }
        }

        // Procesar botones dinámicos
        $buttonComponents = $this->buildButtonComponents();
        $components = array_merge($components, $buttonComponents);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->phoneNumber,
            'type' => 'template',
            'template' => [
                'name' => $this->templateIdentifier,
                'language' => ['code' => $this->language],
                'components' => $components
            ]
        ];

        dd($payload);

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

        return $response;
    }

    protected function ensureTemplateStructureLoaded(): void
    {
        if (empty($this->templateStructure)) {
            throw new InvalidArgumentException("Debes establecer la plantilla usando ->usingTemplate(...) antes de agregar componentes.");
        }
    }
}
