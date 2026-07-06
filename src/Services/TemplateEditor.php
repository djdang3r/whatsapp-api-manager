<?php

namespace ScriptDevelop\WhatsappManager\Services;

use ScriptDevelop\WhatsappManager\WhatsappApi\ApiClient;
use ScriptDevelop\WhatsappManager\WhatsappApi\Endpoints;
use ScriptDevelop\WhatsappManager\Exceptions\TemplateComponentException;
use ScriptDevelop\WhatsappManager\Exceptions\TemplateUpdateException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

use Illuminate\Database\Eloquent\Model;
use ScriptDevelop\WhatsappManager\Support\WhatsappModelResolver;

class TemplateEditor extends TemplateBuilder
{
    /**
     * Instancia de la plantilla existente
     * @var Model
     */
    protected Model $template;

    /**
     * Constructor del editor de plantillas
     *
     * @param Model $template Plantilla existente a editar
     * @param ApiClient $apiClient Cliente API
     * @param TemplateService $templateService Servicio de plantillas
     */
    public function __construct(
        Model $template,
        ApiClient $apiClient,
        TemplateService $templateService,
        FlowService $flowService
    ) {
        parent::__construct($apiClient, $template->businessAccount, $templateService, $flowService);
        $this->template = $template;
        $this->flowService = $flowService;
        $this->loadExistingTemplate();
    }

    /**
     * Carga la plantilla existente para edición
     */
    protected function loadExistingTemplate(): void
    {
        // Cargar datos desde el JSON almacenado
        $this->templateData = json_decode($this->template->json, true);

        // Conservar metadatos esenciales
        $this->templateData['name'] = $this->template->name;
        $this->templateData['language'] = $this->template->language;
        $this->templateData['category'] = $this->template->category->name;

        // Cargar formato de parámetros existente
        $this->parameterFormat = $this->templateData['parameter_format'] ?? 'POSITIONAL';

        // Inicializar contador de botones
        $this->buttonCount = $this->countExistingButtons();
    }

    /**
     * Cuenta los botones existentes en la plantilla
     */
    protected function countExistingButtons(): int
    {
        foreach ($this->templateData['components'] as $component) {
            if ($component['type'] === 'BUTTONS') {
                return count($component['buttons'] ?? []);
            }
        }
        return 0;
    }

    /**
     * Actualiza la plantilla en la API y en la base de datos
     *
     * @return Model Plantilla actualizada
     * @throws TemplateUpdateException
     */
    public function update(): Model
    {
        try {
            $this->validateForUpdate();
            $this->sanitizeTemplateData();

            // Actualizar en la API de WhatsApp
            $fullTemplateResponse = $this->updateTemplateInApi();

            // Actualizar en la base de datos
            $this->updateTemplateInDatabase($fullTemplateResponse);

            // Sincronizar relaciones de flujo si hay botones tipo FLOW
            $this->syncFlowRelations();

            // Reiniciar el estado del builder
            $this->resetBuilder();

            return $this->template->fresh();
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $response = $e->getResponse();
            $responseBody = json_decode($response->getBody(), true);
            $errorCode = $responseBody['error']['code'] ?? null;
            $errorSubcode = $responseBody['error']['error_subcode'] ?? null;

            if ($errorCode === 100 && $errorSubcode === 2388023) {
                throw new TemplateUpdateException(
                    'No puedes actualizar una plantilla con el mismo nombre e idioma inmediatamente después de eliminar otra. ' .
                    'Espera 4 semanas o usa un nombre diferente.'
                );
            }

            Log::channel('whatsapp')->error('Error de API al actualizar plantilla', [
                'error' => $responseBody,
                'status' => $response->getStatusCode()
            ]);
            throw $e;
        } catch (\Exception $e) {
            $arr_error = [];
            $arr_error[] = $e->getMessage();
            try{
                if( method_exists($e, 'getDetails') ){
                    $details = $e->getDetails();

                    if( $details ){
                        if( isset($details['error']) ){
                            $detailsError = $details['error'];
                            if( isset($detailsError['error_user_title']) ){
                                $arr_error[] = $detailsError['error_user_title'];
                            }
                            if( isset($detailsError['error_user_msg']) ){
                                $arr_error[] = $detailsError['error_user_msg'];
                            }
                        }
                    }
                }
            }
            catch (\Exception $ex) {
                //dd($ex);
            }

            Log::channel('whatsapp')->error('Error general al actualizar plantilla', [
                'error_message' => $arr_error,
            ]);
            throw new TemplateUpdateException('Error actualizando plantilla: ' . implode(', ', $arr_error), 0, $e);
        }
    }

    /**
     * Validaciones específicas para actualización
     */
    protected function validateForUpdate(): void
    {
        $this->validateTemplate();

        // No se puede cambiar la categoría de plantillas existentes
        $originalCategory = $this->template->category->name;
        if ($this->templateData['category'] !== $originalCategory) {
            throw new InvalidArgumentException(
                "No se puede cambiar la categoría de una plantilla existente. Original: $originalCategory, Nueva: {$this->templateData['category']}"
            );
        }

        // Validar estado de la plantilla
        //Nota Cuau: Si se debería poder editar una plantilla aprobada, pero cuando se guarde en base de dato shay que crear una nueva versión
        /*if ($this->template->status === 'APPROVED') {
            throw new InvalidArgumentException(
                'No se pueden modificar plantillas aprobadas. Crea una nueva versión.'
            );
        }*/

        // Validar que el cuerpo existe
        if (!$this->componentExists('BODY')) {
            throw new TemplateComponentException('El componente BODY es obligatorio en todas las plantillas.');
        }

        // Validar límite de botones
        if ($this->buttonCount > 10) {
            throw new TemplateComponentException('No se pueden tener más de 10 botones en una plantilla.');
        }

        // Validar que solo hay un componente de cada tipo (excepto botones)
        $componentCounts = array_count_values(
            array_column($this->templateData['components'], 'type')
        );

        foreach (['HEADER', 'BODY', 'FOOTER'] as $componentType) {
            if (($componentCounts[$componentType] ?? 0) > 1) {
                throw new TemplateComponentException("Solo puede haber un componente $componentType por plantilla.");
            }
        }
    }

    /**
     * Actualiza la plantilla en la API de WhatsApp
     */
    protected function updateTemplateInApi(): array
    {
        $endpoint = Endpoints::build(Endpoints::UPDATE_TEMPLATE, [
            'template_id' => $this->template->wa_template_id,
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $this->account->api_token,
            'Content-Type' => 'application/json',
        ];

        Log::channel('whatsapp')->debug('Actualizando plantilla en API', [
            'endpoint' => $endpoint,
            'data' => $this->templateData
        ]);

        $response = $this->apiClient->request(
            'POST',
            $endpoint,
            [],
            $this->templateData,
            [],
            $headers
        );

        Log::channel('whatsapp')->info('Respuesta recibida de la API al editar plantilla.', [
            'response' => $response
        ]);

        return $response;
    }

    /**
     * Actualiza la plantilla en la base de datos
     */
    protected function updateTemplateInDatabase($apiResponse): void
    {
        try {
            $endpoint = Endpoints::build(Endpoints::GET_TEMPLATE, [
                'template_id' => $apiResponse['id'],
            ]);

            $headers = [
                'Authorization' => 'Bearer ' . $this->account->api_token,
            ];

            $fullTemplateResponse = $this->apiClient->request(
                'GET',
                $endpoint,
                [],
                null,
                [],
                $headers
            );

            // Actualizar el registro con los datos completos que incluyen URLs
            $this->template->update([
                'name' => $fullTemplateResponse['name'] ?? $this->templateData['name'],
                'json' => json_encode($fullTemplateResponse, JSON_UNESCAPED_UNICODE),
                'status' => $fullTemplateResponse['status'] ?? 'PENDING', // Vuelve a estado de revisión a menos que la API indique lo contrario
            ]);

            //2026-03-12: Por lo pronto se comenta el crear una versión inicial, no va a ser necesaria, cuando el webhook se dispare y apruebe la plantilla en ese momento que se genere la versión, para evitar duplicados (Cuando la plantilla tiene un header de tipo imagen la URL siempre es distinta aunque sea un poco, esto hace que el HASH generado sea siempre distinto y provoque crear una nueva versión)
            //$this->createInitialVersion($this->template, $fullTemplateResponse);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error al obtener detalles completos de la plantilla', [
                'template_id' => $apiResponse['id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Reinicia el estado del builder
     */
    protected function resetBuilder(): void
    {
        $this->templateData = ['components' => []];
        $this->buttonCount = 0;
    }

    /*public function setParameterFormat(string $format): self
    {
        parent::setParameterFormat($format);
        return $this;
    }*/

    /**
     * =================================================================
     * GESTIÓN COMPLETA DE COMPONENTES
     * =================================================================
     */

    /**
     * HEADER MANAGEMENT
     */

    public function addHeader(string $format, string $content, ?array $example = null): self
    {
        if ($this->hasHeader()) {
            throw new TemplateComponentException('La plantilla ya tiene un HEADER. Use changeHeader() para modificarlo.');
        }

        return parent::addHeader($format, $content, $example);
    }

    public function changeHeader(string $format, string $content, ?array $example = null): self
    {
        if (!$this->hasHeader()) {
            throw new TemplateComponentException('La plantilla no tiene un HEADER. Use addHeader() para agregar uno.');
        }

        $this->removeHeader();
        return parent::addHeader($format, $content, $example);
    }

    public function removeHeader(): self
    {
        $this->removeComponent('HEADER');
        return $this;
    }

    public function hasHeader(): bool
    {
        return $this->componentExists('HEADER');
    }

    public function getHeader(): ?array
    {
        return $this->getComponent('HEADER');
    }

    /**
     * BODY MANAGEMENT
     */

    public function addBody(string $text, ?array $example = null): self
    {
        if ($this->hasBody()) {
            throw new TemplateComponentException('La plantilla ya tiene un BODY. Use changeBody() para modificarlo.');
        }

        return parent::addBody($text, $example);
    }

    public function changeBody(string $text, ?array $example = null): self
    {
        if (!$this->hasBody()) {
            throw new TemplateComponentException('La plantilla no tiene un BODY. Use addBody() para agregar uno.');
        }

        $this->removeBody();
        return parent::addBody($text, $example);
    }

    public function removeBody(): self
    {
        $this->removeComponent('BODY');
        return $this;
        //throw new TemplateComponentException('El componente BODY es obligatorio y no puede ser eliminado.');
    }

    public function hasBody(): bool
    {
        return $this->componentExists('BODY');
    }

    public function getBody(): ?array
    {
        return $this->getComponent('BODY');
    }

    /**
     * FOOTER MANAGEMENT
     */

    public function addFooter(string $text): self
    {
        if ($this->hasFooter()) {
            throw new TemplateComponentException('La plantilla ya tiene un FOOTER. Use changeFooter() para modificarlo.');
        }

        return parent::addFooter($text);
    }

    public function changeFooter(string $text): self
    {
        if (!$this->hasFooter()) {
            throw new TemplateComponentException('La plantilla no tiene un FOOTER. Use addFooter() para agregar uno.');
        }

        $this->removeFooter();
        return parent::addFooter($text);
    }

    public function removeFooter(): self
    {
        $this->removeComponent('FOOTER');
        return $this;
    }

    public function hasFooter(): bool
    {
        return $this->componentExists('FOOTER');
    }

    public function getFooter(): ?array
    {
        return $this->getComponent('FOOTER');
    }

    /**
     * AUTHENTICATION BODY MANAGEMENT
     *
     * Las plantillas de autenticación usan add_security_recommendation en lugar de text en el BODY.
     */

    public function addAuthenticationBody(bool $addSecurityRecommendation = true): self
    {
        if ($this->hasBody()) {
            throw new TemplateComponentException('La plantilla ya tiene un BODY. Use changeBody() para modificarlo.');
        }

        return parent::addAuthenticationBody($addSecurityRecommendation);
    }

    /**
     * AUTHENTICATION FOOTER MANAGEMENT
     *
     * Las plantillas de autenticación usan code_expiration_minutes en lugar de text en el FOOTER.
     */

    public function addAuthenticationFooter(?int $codeExpirationMinutes = null): self
    {
        if ($this->hasFooter()) {
            throw new TemplateComponentException('La plantilla ya tiene un FOOTER. Use changeFooter() para modificarlo.');
        }

        return parent::addAuthenticationFooter($codeExpirationMinutes);
    }

    /**
     * BUTTONS MANAGEMENT
     */

    public function addButton(string $type, string $text, ?string $urlOrPhone = null, ?array $example = null): self
    {
        if ($this->buttonCount >= 10) {
            throw new TemplateComponentException('No se pueden agregar más de 10 botones a una plantilla.');
        }

        return parent::addButton($type, $text, $urlOrPhone, $example);
    }

    /**
     * Agrega un botón de tipo FLOW a la plantilla.
     *
     * @param string      $text           Texto visible del botón (máx. 25 caracteres)
     * @param string      $flowId         wa_flow_id del flujo en Meta
     * @param string|null $navigateScreen ID de la pantalla inicial (requerido si flowAction = NAVIGATE)
     * @param string      $flowAction     'NAVIGATE' (por defecto) o 'DATA_EXCHANGE'
     * @param string|null $flowIcon       Icono opcional: DEFAULT, DOCUMENT, PROMOTION, REVIEW
     * @return self
     * @throws TemplateComponentException
     */
    public function addFlowButton(string $text, string $flowId, ?string $navigateScreen = null, string $flowAction = 'NAVIGATE', ?string $flowIcon = null): self
    {
        if ($this->buttonCount >= 10) {
            throw new TemplateComponentException('No se pueden agregar más de 10 botones a una plantilla.');
        }

        return parent::addFlowButton($text, $flowId, $navigateScreen, $flowAction, $flowIcon);
    }

    public function getButtons(): array
    {
        $buttonsComponent = $this->getComponentsByType('BUTTONS');
        return $buttonsComponent[0]['buttons'] ?? [];
    }

    public function removeButtonAt(int $index): self
    {
        $buttons = $this->getButtons();

        if (!isset($buttons[$index])) {
            throw new TemplateComponentException("No existe un botón en la posición $index");
        }

        $this->removeButton($index);
        return $this;
    }

    public function removeAllButtons(): self
    {
        $this->removeComponent('BUTTONS');
        $this->buttonCount = 0;
        return $this;
    }

    public function hasButtons(): bool
    {
        return $this->componentExists('BUTTONS');
    }

    /**
     * =================================================================
     * MÉTODOS AUXILIARES
     * =================================================================
     */

    /**
     * Verifica si un componente existe
     */
    public function hasComponent(string $type): bool
    {
        return $this->componentExists($type);
    }

    /**
     * Obtiene los datos de un componente específico
     */
    public function getComponent(string $type): ?array
    {
        $components = $this->getComponentsByType($type);
        return $components[0] ?? null;
    }

    /**
     * Elimina un componente por tipo
     */
    protected function removeComponent(string $type): self
    {
        $this->templateData['components'] = array_values(array_filter(
            $this->templateData['components'],
            fn($component) => $component['type'] !== $type
        ));

        if ($type === 'BUTTONS') {
            $this->buttonCount = 0;
        }

        return $this;
    }

    /**
     * Elimina un botón por índice
     */
    protected function removeButton(int $index): self
    {
        $buttonsComponent = $this->getComponentsByType('BUTTONS');

        if (empty($buttonsComponent)) {
            throw new TemplateComponentException('No existe un componente BUTTONS');
        }

        $componentIndex = array_key_first($buttonsComponent);
        $buttons = $buttonsComponent[$componentIndex]['buttons'] ?? [];

        if (!isset($buttons[$index])) {
            throw new TemplateComponentException("Índice de botón inválido: $index");
        }

        unset($buttons[$index]);
        $buttons = array_values($buttons);

        $this->templateData['components'][$componentIndex]['buttons'] = $buttons;
        $this->buttonCount--;

        if (empty($buttons)) {
            unset($this->templateData['components'][$componentIndex]);
            $this->templateData['components'] = array_values($this->templateData['components']);
        }

        return $this;
    }

    /**
     * Obtiene componentes por tipo
     */
    protected function getComponentsByType(string $type): array
    {
        return array_filter($this->templateData['components'], fn($c) => $c['type'] === $type);
    }

    /**
     * Sincroniza las relaciones de flujo para botones tipo FLOW.
     */
    protected function syncFlowRelations(): void
    {
        $flowButtonIds = [];
        foreach ($this->getButtons() as $button) {
            if (($button['type'] ?? null) === 'FLOW' && !empty($button['flow_id'])) {
                $flow = $this->flowService->getFlowById($button['flow_id']);
                if ($flow) {
                    // Actualiza o crea la relación en la tabla pivote
                    $this->template->flows()->syncWithoutDetaching([$flow->flow_id => [
                        'flow_button_label' => $button['text'] ?? 'Iniciar flujo'
                    ]]);
                    $flowButtonIds[] = $flow->flow_id;
                }
            }
        }
        // Elimina relaciones obsoletas
        if (!empty($flowButtonIds)) {
            $this->template->flows()->wherePivotNotIn('flow_id', $flowButtonIds)->detach();
        } else {
            // Si ya no hay botones FLOW, elimina todas las relaciones
            $this->template->flows()->detach();
        }
    }
}