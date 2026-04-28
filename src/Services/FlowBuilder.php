<?php

namespace ScriptDevelop\WhatsappManager\Services;

use ScriptDevelop\WhatsappManager\WhatsappApi\ApiClient;
use ScriptDevelop\WhatsappManager\WhatsappApi\Endpoints;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;
use ScriptDevelop\WhatsappManager\Support\WhatsappModelResolver;
use InvalidArgumentException;

class FlowBuilder
{
    protected array $flowData = [];
    protected array $screens = [];
    protected ?ScreenBuilder $currentScreen = null;
    protected ApiClient $apiClient;
    protected Model $account;
    protected FlowService $flowService;

    public function __construct(ApiClient $apiClient, Model $account, FlowService $flowService)
    {
        $this->apiClient = $apiClient;
        $this->account = $account;
        $this->flowService = $flowService;
    }

    // ==========================================
    // Metadatos del Flujo
    // ==========================================
    public function name(string $name): self
    {
        if (strlen($name) > 120) {
            throw new InvalidArgumentException('El nombre del flujo no puede exceder los 120 caracteres.');
        }
        $this->flowData['name'] = $name;
        return $this;
    }

    public function description(string $description): self
    {
        $this->flowData['description'] = $description;
        return $this;
    }

    public function cloneFlowId(string $flowId): self
    {
        $this->flowData['clone_flow_id'] = $flowId;
        return $this;
    }

    public function endpointUri(string $uri): self
    {
        $this->flowData['endpoint_uri'] = $uri;
        return $this;
    }

    public function type(string $type): self
    {
        $validTypes = ['AUTHENTICATION', 'MARKETING', 'UTILITY', 'SERVICE'];
        if (!in_array($type, $validTypes)) {
            throw new InvalidArgumentException('Tipo de flujo inválido. Valores permitidos: ' . implode(', ', $validTypes));
        }
        $this->flowData['flow_type'] = $type;
        return $this;
    }

    public function category(string $category): self
    {
        $validCategories = [
            'SIGN_UP',
            'SIGN_IN',
            'APPOINTMENT_BOOKING',
            'LEAD_GENERATION',
            'SHOPPING',
            'CONTACT_US',
            'CUSTOMER_SUPPORT',
            'SURVEY',
            'OTHER'
        ];
        if (!in_array($category, $validCategories)) {
            throw new InvalidArgumentException('Categoría inválida: ' . $category);
        }
        $this->flowData['categories'] = [$category];
        return $this;
    }

    public function setJsonStructure(array $structure): self
    {
        $this->flowData['json_structure'] = $structure;
        return $this;
    }

    // ==========================================
    // Gestión de Pantallas (Fluent API)
    // ==========================================
    public function screen(string $name): ScreenBuilder
    {
        if (empty($name)) {
            throw new InvalidArgumentException('El nombre de la pantalla es obligatorio.');
        }

        if ($this->currentScreen) {
            $this->screens[] = $this->currentScreen->build();
        }

        $this->currentScreen = new ScreenBuilder($this, $name);
        return $this->currentScreen;
    }

    // ==========================================
    // Compilación a Formato WhatsApp (Meta API)
    // ==========================================
    public function build(): array
    {
        if ($this->currentScreen) {
            $this->screens[] = $this->currentScreen->build();
            $this->currentScreen = null;
        }

        if (empty($this->flowData['name'])) {
            throw new InvalidArgumentException('El nombre del flujo es obligatorio.');
        }

        $whatsappScreens = [];
        foreach ($this->screens as $screen) {
            $whatsappScreens[] = $this->convertToWhatsappScreen($screen);
        }

        $this->flowData['json_structure'] = [
            'version' => config('whatsapp.flows.default_version', '7.3'),
            'data_api_version' => config('whatsapp.flows.data_api_version', '3.0'),
            'routing_model' => new \stdClass(),
            'screens' => $whatsappScreens,
        ];

        // Lógica de categorías por defecto
        if (empty($this->flowData['categories'])) {
            $typeToCategory = [
                'AUTHENTICATION' => 'SIGN_IN',
                'MARKETING' => 'LEAD_GENERATION',
                'UTILITY' => 'CUSTOMER_SUPPORT',
                'SERVICE' => 'CUSTOMER_SUPPORT',
            ];
            $this->flowData['categories'] = [$typeToCategory[$this->flowData['flow_type'] ?? 'UTILITY'] ?? 'OTHER'];
        }

        $this->flowData['endpoint_uri'] = config('app.url') . '/whatsapp/flows/endpoint';
        $this->flowData['data_api_version'] = config('whatsapp.flows.data_api_version', '3.0');

        return $this->flowData;
    }

    protected function convertToWhatsappScreen(array $screen): array
    {
        $children = [];
        $footerElement = null;

        foreach ($screen['elements'] ?? [] as $element) {
            if ($element['type'] === 'button' || $element['type'] === 'Footer') {
                $footerElement = $element;
            } else {
                $children[] = $this->convertToWhatsappElement($element);
            }
        }

        if (!empty($screen['title'])) {
            array_unshift($children, ['type' => 'TextHeading', 'text' => $screen['title']]);
        }

        if (!empty($footerElement)) {
            $children[] = [
                'type' => 'Footer',
                'label' => $footerElement['label'] ?? 'Continuar',
                'on-click-action' => $footerElement['action'] ?? [
                    'name' => 'complete',
                    'payload' => (object) []
                ]
            ];
        }

        return [
            'id' => strtoupper($screen['name']),
            'title' => $screen['title'] ?? '',
            'terminal' => $screen['terminal'] ?? false,
            'success' => $screen['success'] ?? false,
            'data' => !empty($screen['data']) ? $screen['data'] : (object) [],
            'layout' => [
                'type' => 'SingleColumnLayout',
                'children' => [
                    [
                        'type' => 'Form',
                        'name' => 'flow_path',
                        'children' => $children
                    ]
                ]
            ]
        ];
    }

    protected function convertToWhatsappElement(array $element): array
    {
        $base = $element;
        $type = strtolower($element['type']);

        switch ($type) {
            case 'input':
                $base['type'] = 'TextInput';
                break;
            case 'dropdown':
                $base['type'] = 'Dropdown';
                if (isset($base['options'])) {
                    $base['options'] = array_map(fn($o) => ['id' => $o['value'], 'title' => $o['label']], $base['options']);
                }
                break;
            case 'photopicker':
                $base['type'] = 'PhotoPicker';
                $base['photo-source'] = $element['photo-source'] ?? 'camera_gallery';
                break;
            case 'checkbox':
                $base['type'] = 'Checkbox';
                break;
            case 'radiobuttonsgroup':
                $base['type'] = 'RadioButtonsGroup';
                break;
            case 'if':
                $base['type'] = 'If';
                break;
            default:
                // Si el tipo ya es PascalCase (ej. TextHeading), lo dejamos pasar
                $base['type'] = $element['type'];
        }

        unset($base['action']); // Las acciones solo van en el Footer/Buttons

        return array_filter($base, fn($v) => $v !== null && $v !== '');
    }

    // ==========================================
    // Guardado y Comunicación con la API
    // ==========================================
    public function save(): Model
    {
        $flowData = $this->build();
        $headers = [
            'Authorization' => 'Bearer ' . $this->account->api_token,
            'Content-Type' => 'application/json',
        ];

        try {
            // 1. Crear el flujo en Meta
            $endpoint = Endpoints::build(Endpoints::CREATE_FLOW, ['waba_id' => $this->account->whatsapp_business_id]);
            
            $payload = [
                'name' => $flowData['name'],
                'categories' => $flowData['categories'],
            ];

            if (!empty($this->flowData['clone_flow_id'])) {
                $payload['clone_flow_id'] = $this->flowData['clone_flow_id'];
            }
            if (!empty($this->flowData['endpoint_uri'])) {
                $payload['endpoint_uri'] = $this->flowData['endpoint_uri'];
            }

            $response = $this->apiClient->request('POST', $endpoint, [], $payload, [], $headers);

            $flowId = $response['id'];

            // 2. Subir el JSON (Assets)
            $assetEndpoint = Endpoints::build(Endpoints::UPDATE_FLOW_ASSETS, ['flow_id' => $flowId]);
            $this->apiClient->request('POST', $assetEndpoint, [], [
                'multipart' => [
                    ['name' => 'name', 'contents' => 'flow.json'],
                    ['name' => 'asset_type', 'contents' => 'FLOW_JSON'],
                    [
                        'name' => 'file',
                        'contents' => json_encode($flowData['json_structure'], JSON_UNESCAPED_UNICODE),
                        'filename' => 'flow.json',
                        'headers' => ['Content-Type' => 'application/json']
                    ]
                ]
            ], [], ['Authorization' => 'Bearer ' . $this->account->api_token]);

            // 3. Crear registro local
            $metaAppId = config('whatsapp.meta_auth.client_id');
            $flow = WhatsappModelResolver::flow()->create([
                'whatsapp_business_account_id' => $this->account->whatsapp_business_id,
                'wa_flow_id' => $flowId,
                'name' => $flowData['name'],
                'json_structure' => $flowData['json_structure'],
                'status' => 'DRAFT',
                'application_id' => $metaAppId,
                'application_name' => config('app.name', 'WhatsApp Business'),
                'application_link' => 'https://business.facebook.com/apps/' . $metaAppId,
            ]);

            $this->flowService->syncScreensAndElements($flow, $this->screens);

            return $flow;

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error en FlowBuilder: ' . $e->getMessage());
            throw $e;
        }
    }
}