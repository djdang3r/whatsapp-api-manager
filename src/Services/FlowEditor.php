<?php

namespace ScriptDevelop\WhatsappManager\Services;

//use ScriptDevelop\WhatsappManager\Models\WhatsappFlow;
use ScriptDevelop\WhatsappManager\WhatsappApi\ApiClient;
use ScriptDevelop\WhatsappManager\WhatsappApi\Endpoints;
use Illuminate\Support\Facades\Log;

use Illuminate\Database\Eloquent\Model;
use ScriptDevelop\WhatsappManager\Support\WhatsappModelResolver;

use InvalidArgumentException;

/**
 * Servicio para edición fluida de flujos de WhatsApp.
 */
class FlowEditor
{
    protected Model $flow;
    protected ApiClient $apiClient;
    protected FlowService $flowService;
    protected array $flowData;
    protected ?array $currentScreen = null;
    protected ?int $currentScreenIndex = null;
    protected ?array $currentElement = null;
    protected ?int $currentElementIndex = null;

    /**
     * JSON pre-encodificado del editor visual.
     * Cuando está seteado, save() lo usa directamente en lugar de buildFlowJson().
     * Se pasa como string para preservar objetos vacíos como {} (no como []).
     */
    protected ?string $rawJsonString = null;

    public function __construct(Model $flow, ApiClient $apiClient, FlowService $flowService)
    {
        $this->flow = $flow;
        $this->apiClient = $apiClient;
        $this->flowService = $flowService;
        $this->flowData = $this->loadFlowData();
    }

    protected function loadFlowData(): array
    {
        $data = $this->flow->toArray();
        if (is_array($this->flow->json_structure)) {
            $data['json_structure'] = $this->flow->json_structure;
        } elseif (!empty($this->flow->json_structure)) {
            $data['json_structure'] = json_decode($this->flow->json_structure, true);
        } else {
            $data['json_structure'] = ['screens' => []];
        }
        return $data;
    }

    // --- Métodos de edición fluida ---

    public function name(string $name): self
    {
        $this->flowData['name'] = $name;
        return $this;
    }

    public function description(string $description): self
    {
        $this->flowData['description'] = $description;
        return $this;
    }

    public function category(string $category): self
    {
        $this->flowData['categories'] = [$category];
        return $this;
    }

    public function screen(string $name): self
    {
        $screens = &$this->flowData['json_structure']['screens'];
        foreach ($screens as $i => $screen) {
            if ($screen['name'] === $name) {
                $this->currentScreen = &$screens[$i];
                $this->currentScreenIndex = $i;
                return $this;
            }
        }
        // Si no existe, crear nueva pantalla
        $newScreen = [
            'name' => $name,
            'title' => '',
            'content' => '',
            'is_start' => false,
            'order' => count($screens) + 1,
            'elements' => [],
        ];
        $screens[] = $newScreen;
        $this->currentScreen = &$screens[array_key_last($screens)];
        $this->currentScreenIndex = array_key_last($screens);
        return $this;
    }

    public function title(string $title): self
    {
        if ($this->currentScreen !== null) {
            $this->flowData['json_structure']['screens'][$this->currentScreenIndex]['title'] = $title;
        }
        return $this;
    }

    public function content(string $content): self
    {
        if ($this->currentScreen !== null) {
            $this->flowData['json_structure']['screens'][$this->currentScreenIndex]['content'] = $content;
        }
        return $this;
    }

    public function isStart(bool $isStart): self
    {
        if ($this->currentScreen !== null) {
            $this->flowData['json_structure']['screens'][$this->currentScreenIndex]['is_start'] = $isStart;
        }
        return $this;
    }

    public function order(int $order): self
    {
        if ($this->currentScreen !== null) {
            $this->flowData['json_structure']['screens'][$this->currentScreenIndex]['order'] = $order;
        }
        return $this;
    }

    public function element(string $name): self
    {
        if ($this->currentScreen !== null) {
            $elements = &$this->flowData['json_structure']['screens'][$this->currentScreenIndex]['elements'];
            foreach ($elements as $i => $el) {
                if ($el['name'] === $name) {
                    $this->currentElement = &$elements[$i];
                    $this->currentElementIndex = $i;
                    return $this;
                }
            }
            // Si no existe, crear nuevo elemento
            $newElement = [
                'name' => $name,
                'type' => '',
                'label' => '',
            ];
            $elements[] = $newElement;
            $this->currentElement = &$elements[array_key_last($elements)];
            $this->currentElementIndex = array_key_last($elements);
        }
        return $this;
    }

    public function type(string $type): self
    {
        if (isset($this->currentElement)) {
            $this->currentElement['type'] = $type;
        }
        return $this;
    }

    public function label(string $label): self
    {
        if (isset($this->currentElement)) {
            $this->currentElement['label'] = $label;
        }
        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        if (isset($this->currentElement)) {
            $this->currentElement['placeholder'] = $placeholder;
        }
        return $this;
    }

    public function required(bool $required): self
    {
        if (isset($this->currentElement)) {
            $this->currentElement['required'] = $required;
        }
        return $this;
    }

    public function endElement(): self
    {
        unset($this->currentElement, $this->currentElementIndex);
        return $this;
    }

    public function endScreen(): self
    {
        unset($this->currentScreen, $this->currentScreenIndex);
        return $this;
    }

    public function removeScreen(string $name): self
    {
        $screens = &$this->flowData['json_structure']['screens'];
        $screens = array_values(array_filter($screens, fn($s) => $s['name'] !== $name));
        return $this;
    }

    public function removeElement(string $elementName): self
    {
        if ($this->currentScreen !== null) {
            $elements = &$this->flowData['json_structure']['screens'][$this->currentScreenIndex]['elements'];
            $elements = array_values(array_filter($elements, fn($el) => $el['name'] !== $elementName));
        }
        return $this;
    }

    public function reorderScreens(array $names): self
    {
        $screens = &$this->flowData['json_structure']['screens'];
        usort($screens, function($a, $b) use ($names) {
            return array_search($a['name'], $names) <=> array_search($b['name'], $names);
        });
        // Reasignar el campo 'order'
        foreach ($screens as $i => &$screen) {
            $screen['order'] = $i + 1;
        }
        return $this;
    }

    /**
     * Inyecta el JSON del flow ya encodificado como string.
     * Cuando se usa este método, save() omite buildFlowJson() y sube el JSON tal como está.
     * Preserva objetos vacíos {} correctamente — a diferencia de pasar un array PHP
     * que json_encode() convertiría a [].
     */
    public function setRawJsonStructure(string $jsonString): self
    {
        $this->rawJsonString = $jsonString;
        return $this;
    }

    // --- Guardar cambios en API y base de datos ---

    public function save(): Model
    {
        if (empty($this->flow->wa_flow_id)) {
            throw new InvalidArgumentException('El flujo no tiene wa_flow_id, no puede ser editado en la API.');
        }

        $flowId      = $this->flow->wa_flow_id;
        $accessToken = $this->flow->whatsappBusinessAccount->api_token;
        $headers     = ['Authorization' => 'Bearer ' . $accessToken];

        // 1. Actualizar metadatos del flow (nombre, categorías, descripción)
        //    Solo si hay nombre definido — en el flujo del editor visual esto es opcional.
        if (!empty($this->flowData['name'])) {
            $metaEndpoint = Endpoints::build(Endpoints::UPDATE_FLOW_METADATA, ['flow_id' => $flowId]);
            $this->apiClient->request('POST', $metaEndpoint, [], [
                'name'        => $this->flowData['name'],
                'categories'  => $this->flowData['categories'] ?? [],
                'description' => $this->flowData['description'] ?? '',
            ], [], $headers);
        }

        // 2. Determinar el JSON a subir:
        //    - rawJsonString: viene del editor visual, ya encodificado — preserva {} correctamente.
        //    - Sin rawJsonString: buildFlowJson() para el flujo builder (API fluida).
        if ($this->rawJsonString !== null) {
            $jsonString = $this->ensureSuccessOnTerminalScreen($this->rawJsonString);
        } else {
            $flowJson = $this->buildFlowJson();
            if (empty($flowJson['screens'])) {
                throw new InvalidArgumentException('El flujo debe tener al menos una pantalla.');
            }
            $jsonString = json_encode($flowJson, JSON_UNESCAPED_UNICODE);
        }

        // 3. Subir el JSON vía ApiClient (multipart) — igual que FlowBuilder::save()
        $tmpFile = tempnam(sys_get_temp_dir(), 'flow_') . '.json';
        file_put_contents($tmpFile, $jsonString);

        try {
            $assetEndpoint = Endpoints::build(Endpoints::UPDATE_FLOW_ASSETS, ['flow_id' => $flowId]);
            $this->apiClient->request('POST', $assetEndpoint, [], [
                'multipart' => [
                    ['name' => 'name',       'contents' => 'flow.json'],
                    ['name' => 'asset_type', 'contents' => 'FLOW_JSON'],
                    [
                        'name'     => 'file',
                        'contents' => fopen($tmpFile, 'r'),
                        'filename' => 'flow.json',
                        'headers'  => ['Content-Type' => 'application/json'],
                    ],
                ],
            ], [], $headers);
        } finally {
            @unlink($tmpFile);
        }

        // 4. Actualizar la base de datos local
        $this->flow->update([
            'json_structure' => $jsonString,
        ]);

        Log::channel('whatsapp')->info('FlowEditor: JSON del flow actualizado en Meta', [
            'flow_id' => $flowId,
            'via_raw' => $this->rawJsonString !== null,
        ]);

        return $this->flow->fresh();
    }

    protected function buildFlowJson(): array
    {
        $screens = [];
        $totalScreens = count($this->flowData['json_structure']['screens']);

        foreach ($this->flowData['json_structure']['screens'] as $index => $screen) {
            if (empty($screen['name'])) {
                throw new InvalidArgumentException("El campo 'name' es obligatorio para construir el JSON del flujo.");
            }

            $children = [];
            // Construir elementos válidos
            foreach ($screen['elements'] ?? [] as $element) {
                // Lógica para construir elementos...
            }

            // Determinar si esta pantalla es terminal:
            // - Si explicitly tiene terminal: true, es terminal
            // - Si es la última pantalla y no tiene next_screen_id ni routing, es terminal
            $isExplicitTerminal = ($screen['terminal'] ?? false) === true;
            $isLastScreen = $index === ($totalScreens - 1);
            $hasNextScreen = !empty($screen['next_screen_id']) || !empty($screen['routing']);

            $isTerminal = $isExplicitTerminal || ($isLastScreen && !$hasNextScreen);

            $screenData = [
                'id' => strtoupper($screen['name']),
                'title' => $screen['title'] ?? '',
                'layout' => [
                    'type' => 'SingleColumnLayout',
                    'children' => $children,
                ],
                'data' => (object)[],
            ];

            // Solo agregar terminal:true si la pantalla es terminal
            // Meta requiere que las pantallas terminales tengan success:true
            if ($isTerminal) {
                $screenData['terminal'] = true;
                // success:true indica que esta pantalla representa un resultado exitoso
                // Solo la última pantalla del flow debería tener success:true
                if ($isLastScreen) {
                    $screenData['success'] = true;
                }
            }

            $screens[] = $screenData;
        }

        return [
            'version' => $this->flowData['json_version'] ?? '7.0',
            'screens' => $screens,
        ];
    }

    /**
     * Asegura que al menos una pantalla terminal tenga success:true.
     *
     * Meta requiere que "At least one terminal screen must have property 'success' set as true."
     * Si el JSON del editor visual no tiene ninguna pantalla con success:true,
     * agregamos success:true a la última pantalla que tenga terminal:true.
     *
     * IMPORTANTE: Mantenemos el JSON como string todo lo posible para preservar los tipos.
     * Solo parseamos y re-codificamos si es necesario agregar success:true.
     *
     * @param string $jsonString JSON original del editor visual
     * @return string JSON corregido si era necesario, o el original si ya estaba correcto
     */
    protected function ensureSuccessOnTerminalScreen(string $jsonString): string
    {
        $decoded = json_decode($jsonString);

        if (!is_object($decoded) || !isset($decoded->screens) || !is_array($decoded->screens)) {
            return $jsonString;
        }

        $screens = $decoded->screens;
        $totalScreens = count($screens);

        // Buscar si alguna pantalla ya tiene success:true
        $hasSuccessScreen = false;
        foreach ($screens as $screen) {
            if (($screen->terminal ?? false) === true && ($screen->success ?? false) === true) {
                $hasSuccessScreen = true;
                break;
            }
        }

        // Si ya hay una pantalla con success:true, no modificar
        if ($hasSuccessScreen) {
            return $jsonString;
        }

        // Buscar pantallas terminales que podrían necesitar success:true
        $terminalIndexes = [];
        foreach ($screens as $index => $screen) {
            if (($screen->terminal ?? false) === true) {
                $terminalIndexes[] = $index;
            }
        }

        // Si hay pantallas con terminal:true pero sin success:true, agregar success:true
        // a la última pantalla terminal (o la última pantalla si no hay ninguna terminal)
        if (!empty($terminalIndexes)) {
            $lastTerminalIndex = end($terminalIndexes);
            $screens[$lastTerminalIndex]->terminal = true;
            $screens[$lastTerminalIndex]->success = true;
        } else {
            // No hay pantallas con terminal:true, marcar la última como terminal y success
            $lastIndex = $totalScreens - 1;
            $screens[$lastIndex]->terminal = true;
            $screens[$lastIndex]->success = true;
        }

        Log::channel('whatsapp')->info('FlowEditor: agregado success:true a pantalla terminal', [
            'flow_id' => $this->flow->wa_flow_id ?? 'unknown',
        ]);

        return json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }
}