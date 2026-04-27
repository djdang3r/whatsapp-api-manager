<?php

namespace ScriptDevelop\WhatsappManager\Services;

use InvalidArgumentException;
//use ScriptDevelop\WhatsappManager\Models\WhatsappFlow;
//use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;
use ScriptDevelop\WhatsappManager\WhatsappApi\ApiClient;
use ScriptDevelop\WhatsappManager\WhatsappApi\Endpoints;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection;

use Illuminate\Database\Eloquent\Model;
use ScriptDevelop\WhatsappManager\Support\WhatsappModelResolver;

class FlowService
{
    protected ApiClient $apiClient;

    public function __construct(ApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    public function edit(Model $flow): FlowEditor
    {
        return new FlowEditor(
            $flow,
            $this->apiClient,
            $this
        );
    }

    public function editor(Model $flow): FlowEditor
    {
        return $this->edit($flow);
    }

    /**
     * Sincroniza flujos desde la API de WhatsApp
     */
    public function syncFlows(Model $account): Collection
    {
        $endpoint = Endpoints::build(Endpoints::GET_FLOWS, [
            'waba_id' => $account->whatsapp_business_id,
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $account->api_token,
        ];

        try {
            $response = $this->apiClient->request('GET', $endpoint, [], null, [], $headers);
            $flows = $response['data'] ?? [];

            foreach ($flows as $flowData) {
                // Obtener detalles completos del flujo
                $flowDetailEndpoint = Endpoints::build(Endpoints::GET_FLOW, [
                    'flow_id' => $flowData['id'],
                ]);
                $flowDetail = $this->apiClient->request(
                    'GET',
                    $flowDetailEndpoint,
                    [],
                    null,
                    [
                        'fields' => 'id,name,categories,preview,status,validation_errors,json_version,data_api_version,data_channel_uri,health_status,whatsapp_business_account,application'
                    ],
                    $headers
                );

                $this->storeOrUpdateFlow($account->whatsapp_business_id, $flowDetail);
            }

            // Marcar flujos no encontrados como inactivos
            $apiFlowIds = collect($flows)->pluck('id')->toArray();
            WhatsappModelResolver::flow()->where('whatsapp_business_account_id', $account->whatsapp_business_id)
                ->whereNotIn('wa_flow_id', $apiFlowIds)
                ->update(['status' => 'INACTIVE']);

            return WhatsappModelResolver::flow()->where('whatsapp_business_account_id', $account->whatsapp_business_id)->get();

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error al sincronizar flujos: ' . $e->getMessage(), [
                'endpoint' => $endpoint,
                'business_id' => $account->whatsapp_business_id
            ]);
            throw $e;
        }
    }

    /**
     * Crea o actualiza un flujo en la base de datos
     */
    protected function storeOrUpdateFlow(string $businessId, array $flowData): Model
    {
        $flow = WhatsappModelResolver::flow()->updateOrCreate(
            ['wa_flow_id' => $flowData['id']],
            [
                'whatsapp_business_account_id' => $businessId,
                'name' => $flowData['name'],
                'json_structure' => !empty($flowData['json_structure']) ? $flowData['json_structure'] : null,
                'status' => strtolower($flowData['status'] ?? 'draft'),
                'version' => $flowData['version'] ?? '1.0',

                'categories' => $flowData['categories'] ?? null,
                'preview_url' => $flowData['preview']['preview_url'] ?? null,
                'preview_expires_at' => $flowData['preview']['expires_at'] ?? null,
                'validation_errors' => $flowData['validation_errors'] ?? null,
                'json_version' => $flowData['json_version'] ?? null,
                'health_status' => $flowData['health_status'] ?? null,
                'application_id' => $flowData['application']['id'] ?? null,
                'application_name' => $flowData['application']['name'] ?? null,
                'application_link' => $flowData['application']['link'] ?? null,
            ]
        );

        Log::channel('whatsapp')->info('Flujo actualizado en la base de datos:', ['flow' => $flow]);

        // Procesar screens y elements si existen en json_structure
        if (!empty($flowData['json_structure']['screens'])) {
            $this->syncScreensAndElements($flow, $flowData['json_structure']['screens']);
        }

        return $flow;
    }

    /**
     * Obtiene un flujo por ID de API
     */
    public function getFlowById(string $flowId): ?Model
    {
        return WhatsappModelResolver::flow()->where('wa_flow_id', $flowId)->first();
    }

    /**
     * Crea un nuevo constructor de flujos
     */
    public function createFlowBuilder(Model $account): FlowBuilder
    {
        return new FlowBuilder($this->apiClient, $account, $this);
    }

    /**
     * Crea un nuevo builder de flujos (alias para createFlowBuilder)
     *
     * @param Model $account
     * @return FlowBuilder
     */
    public function builder(Model $account): FlowBuilder
    {
        return $this->createFlowBuilder($account);
    }

    public function syncFlowById(Model $account, string $flowId): ?Model
    {
        $endpoint = Endpoints::build(Endpoints::GET_FLOW, [
            'flow_id' => $flowId,
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $account->api_token,
        ];

        $queryParams = [
            'fields' => 'id,name,categories,preview,status,validation_errors,json_version,data_api_version,data_channel_uri,health_status,whatsapp_business_account,application',
        ];

        try {
            $response = $this->apiClient->request('GET', $endpoint, [], null, $queryParams, $headers);

            Log::channel('whatsapp')->info('Respuesta de la API para sincronización de flujo:', ['response' => $response]);

            // Asegurar que la respuesta tiene los datos necesarios
            if (empty($response['id']) || empty($response['name'])) {
                Log::channel('whatsapp')->error('Respuesta de flujo inválida', ['response' => $response]);
                return null;
            }

            // Guardar o actualizar el flujo en la base de datos
            $updatedFlow = $this->storeOrUpdateFlow($account->whatsapp_business_id, $response);

            // Log de los datos procesados
            Log::channel('whatsapp')->info('Datos procesados para sincronización de flujo:', ['flow' => $updatedFlow]);

            return $updatedFlow;

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error al sincronizar flujo por ID: ' . $e->getMessage(), [
                'flow_id' => $flowId,
            ]);
            return null;
        }
    }

    /**
     * Sincroniza las pantallas y elementos de un flujo.
     *
     * @param Model $flow
     * @param array $screens
     * @return void
     */
    public function syncScreensAndElements(Model $flow, array $screens): void
    {
        foreach ($screens as $screenData) {
            if (empty($screenData['name'])) {
                throw new InvalidArgumentException("El campo 'name' es obligatorio para sincronizar las pantallas.");
            }

            $screen = $flow->screens()->updateOrCreate(
                ['name' => $screenData['name']],
                [
                    'title' => $screenData['title'] ?? '',
                    'content' => $screenData['content'] ?? null,
                    'is_start' => $screenData['is_start'] ?? false,
                    'order' => $screenData['order'] ?? 0,
                    'validation_rules' => $screenData['validation_rules'] ?? null,
                    'next_screen_logic' => $screenData['next_screen_logic'] ?? null,
                    'extra_logic' => $screenData['extra_logic'] ?? null,
                ]
            );

            if (!empty($screenData['elements'])) {
                foreach ($screenData['elements'] as $elementData) {
                    $screen->elements()->updateOrCreate(
                        ['name' => $elementData['name']],
                        [
                            'type' => $elementData['type'] ?? '',
                            'label' => $elementData['label'] ?? '',
                            'placeholder' => $elementData['placeholder'] ?? null,
                            'default_value' => $elementData['default_value'] ?? null,
                            'options' => $elementData['options'] ?? null,
                            'style_json' => $elementData['style_json'] ?? null,
                            'required' => $elementData['required'] ?? false,
                        ]
                    );
                }
            }
        }
    }

    /**
     * Actualiza los metadatos de un flujo en Meta (p. ej. endpoint_uri).
     *
     * @param Model $flow El flujo que se desea actualizar.
     * @param array $data Los datos a actualizar (p. ej. ['endpoint_uri' => '...']).
     * @return array La respuesta de la API de Meta.
     * @throws InvalidArgumentException Si el flujo no tiene un ID de Meta válido.
     */
    public function updateFlow(Model $flow, array $data): array
    {
        if (empty($flow->wa_flow_id)) {
            throw new InvalidArgumentException('El flujo no tiene un ID de Meta válido.');
        }

        $endpoint = Endpoints::build(Endpoints::UPDATE_FLOW_METADATA, [
            'flow_id' => $flow->wa_flow_id,
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $flow->whatsappBusinessAccount->api_token,
        ];

        return $this->apiClient->request('POST', $endpoint, [], $data, [], $headers);
    }

    /**
     * Publica un flujo en la API de WhatsApp.
     *
     * @param Model $flow El flujo que se desea publicar.
     * @return bool Indica si la publicación fue exitosa.
     * @throws InvalidArgumentException Si el flujo no tiene un ID válido.
     * @throws \RuntimeException Si ocurre un error durante la publicación.
     */
    public function publish(Model $flow): bool
    {
        // Validar que el flujo tenga un ID válido
        if (empty($flow->wa_flow_id)) {
            throw new InvalidArgumentException('El flujo no tiene un ID válido, no puede ser publicado.');
        }

        $endpoint = Endpoints::build(Endpoints::PUBLISH_FLOW, [
            'flow_id' => $flow->wa_flow_id,
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $flow->whatsappBusinessAccount->api_token,
        ];

        try {
            $response = $this->apiClient->request(
                'POST',
                $endpoint,
                [],
                null,
                [],
                $headers
            );

            Log::channel('whatsapp')->info('Flujo publicado exitosamente.', [
                'flow_id' => $flow->wa_flow_id,
                'response' => $response,
            ]);

            // Actualizar el estado del flujo en la base de datos
            $flow->update(['status' => 'published']);

            // Sincronizar el flujo con la API para obtener los datos actualizados
            $this->syncFlowById($flow->whatsappBusinessAccount, $flow->wa_flow_id);

            return true;
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error al publicar el flujo: ' . $e->getMessage(), [
                'flow_id' => $flow->wa_flow_id,
            ]);
            throw $e;
        }
    }

    /**
     * Establece la llave pública de encriptación para un número de teléfono en Meta.
     */
    public function setBusinessPublicKey(Model $account, string $publicKey): array
    {
        $endpoint = $account->phone_number_id . '/whatsapp_business_encryption';

        $response = $this->apiClient->request(
            'POST',
            $endpoint,
            [],
            ['business_public_key' => $publicKey],
            [],
            ['Authorization' => 'Bearer ' . $account->api_token]
        );

        return $response;
    }

    /**
     * Obtiene el estado de la llave pública registrada en Meta.
     */
    public function getBusinessPublicKeyStatus(Model $account): array
    {
        $endpoint = $account->phone_number_id . '/whatsapp_business_encryption';

        return $this->apiClient->request(
            'GET',
            $endpoint,
            [],
            null,
            ['fields' => 'business_public_key,business_public_key_status'],
            ['Authorization' => 'Bearer ' . $account->api_token]
        );
    }

    /**
     * Obtiene el estado de la llave pública para un número de teléfono específico.
     * Usa api_phone_number_id del modelo WhatsappPhoneNumber y el api_token del account.
     */
    public function getPhoneNumberPublicKeyStatus(Model $phoneNumber, Model $account): array
    {
        $endpoint = $phoneNumber->api_phone_number_id . '/whatsapp_business_encryption';

        return $this->apiClient->request(
            'GET',
            $endpoint,
            [],
            null,
            ['fields' => 'business_public_key,business_public_key_status'],
            ['Authorization' => 'Bearer ' . $account->api_token]
        );
    }

    /**
     * Sube la llave pública de encriptación para un número de teléfono específico.
     * Usa api_phone_number_id del modelo WhatsappPhoneNumber y el api_token del account.
     */
    public function setPhoneNumberPublicKey(Model $phoneNumber, Model $account, string $publicKey): array
    {
        $endpoint = $phoneNumber->api_phone_number_id . '/whatsapp_business_encryption';

        return $this->apiClient->request(
            'POST',
            $endpoint,
            [],
            ['business_public_key' => $publicKey],
            [],
            ['Authorization' => 'Bearer ' . $account->api_token]
        );
    }

    /**
     * Clona un conjunto de flujos de un WABA origen al WABA destino actual.
     * Si no se especifican nombres de flujos, se clonan todos.
     * 
     * @param Model $account La cuenta destino.
     * @param string $sourceWabaId ID del WABA origen.
     * @param array|null $flowNames Arreglo de nombres de flujos a migrar.
     */
    public function migrateFlows(Model $account, string $sourceWabaId, ?array $flowNames = null): array
    {
        $endpoint = Endpoints::build(Endpoints::MIGRATE_FLOWS, [
            'waba_id' => $account->whatsapp_business_id,
        ]);

        $payload = [
            'source_waba_id' => $sourceWabaId,
        ];

        if (!empty($flowNames)) {
            $payload['source_flow_names'] = json_encode($flowNames);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $account->api_token,
        ];

        $response = $this->apiClient->request('POST', $endpoint, [], $payload, [], $headers);

        return $response;
    }

    /**
     * Regenera y obtiene una URL de previsualización fresca invalidando la anterior.
     * 
     * @param Model $account
     * @param string $flowId ID del flujo en WhatsApp (wa_flow_id)
     */
    public function getFreshPreviewUrl(Model $account, string $flowId): ?array
    {
        $endpoint = Endpoints::build(Endpoints::GET_FLOW, [
            'flow_id' => $flowId,
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $account->api_token,
        ];

        $queryParams = [
            'fields' => 'preview.invalidate(true)',
        ];

        try {
            $response = $this->apiClient->request('GET', $endpoint, [], null, $queryParams, $headers);

            if (!empty($response['preview'])) {
                // Actualizamos la base local
                $flow = WhatsappModelResolver::flow()->where('wa_flow_id', $flowId)->first();
                if ($flow) {
                    $flow->update([
                        'preview_url' => $response['preview']['preview_url'] ?? null,
                        'preview_expires_at' => $response['preview']['expires_at'] ?? null,
                    ]);
                }
            }

            return $response;
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error al solicitar regeneración preventiva de preview URL: ' . $e->getMessage(), [
                'flow_id' => $flowId,
            ]);
            return null;
        }
    }

    /**
     * Descarga el JSON del flow desde Meta vía el endpoint de assets y lo persiste en BD.
     * Útil cuando json_structure es null tras una sincronización (p. ej. flows creados externamente).
     *
     * @param Model $flow Flujo cuyo JSON se desea obtener.
     * @return array|null La estructura JSON del flow, o null si no pudo obtenerse.
     */
    public function syncFlowJson(Model $flow): ?array
    {
        if (empty($flow->wa_flow_id)) {
            return null;
        }

        try {
            $assets = $this->getFlowAssets($flow);
            $data   = $assets['data'] ?? [];

            // Buscar el asset de tipo FLOW_JSON con download_url
            $asset = collect($data)->first(
                fn($a) => ($a['asset_type'] ?? '') === 'FLOW_JSON' && !empty($a['download_url'])
            );

            if (!$asset) {
                Log::channel('whatsapp')->warning('No se encontró asset FLOW_JSON para el flow', [
                    'flow_id' => $flow->wa_flow_id,
                    'assets'  => $data,
                ]);
                return null;
            }

            // Descargar el JSON desde la URL firmada de Meta
            $token    = $flow->whatsappBusinessAccount->api_token;
            $curl     = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL            => $asset['download_url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            ]);
            $body      = curl_exec($curl);
            $curlError = curl_error($curl);
            curl_close($curl);

            if ($curlError || !$body) {
                Log::channel('whatsapp')->error('Error descargando JSON del flow', [
                    'flow_id' => $flow->wa_flow_id,
                    'error'   => $curlError,
                ]);
                return null;
            }

            $jsonStructure = json_decode($body, true);

            if (!$jsonStructure) {
                return null;
            }

            // Persistir en BD
            $flow->update(['json_structure' => json_encode($jsonStructure)]);

            Log::channel('whatsapp')->info('JSON del flow sincronizado desde assets', [
                'flow_id' => $flow->wa_flow_id,
            ]);

            return $jsonStructure;

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('syncFlowJson error', [
                'flow_id' => $flow->wa_flow_id,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Obtiene la lista de Assets asociados a un Flow.
     * Típicamente usado para obtener la URL de descarga (download_url) del JSON publicado.
     *
     * @param Model $flow Flujo del que se desean obtener los assets.
     */
    public function getFlowAssets(Model $flow): array
    {
        if (empty($flow->wa_flow_id)) {
            throw new InvalidArgumentException('El flujo no tiene un ID válido de Meta.');
        }

        $endpoint = Endpoints::build(Endpoints::LIST_FLOW_ASSETS, [
            'flow_id' => $flow->wa_flow_id,
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $flow->whatsappBusinessAccount->api_token,
        ];

        return $this->apiClient->request('GET', $endpoint, [], null, [], $headers);
    }

    /**
     * Marca un Flujo como DEPRECADO.
     * Operación irreversible que evita que Meta siga enviando el flujo a terminales.
     *
     * @param Model $flow Flujo a deprecar.
     * @return bool
     */
    public function deprecate(Model $flow): bool
    {
        if (empty($flow->wa_flow_id)) {
            throw new InvalidArgumentException('El flujo no tiene un ID válido para ser deprecado.');
        }

        $endpoint = Endpoints::build(Endpoints::DEPRECATE_FLOW, [
            'flow_id' => $flow->wa_flow_id,
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $flow->whatsappBusinessAccount->api_token,
        ];

        try {
            $this->apiClient->request('POST', $endpoint, [], [], [], $headers);
            $flow->update(['status' => 'deprecated']);
            return true;
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error al deprecar el flujo: ' . $e->getMessage(), [
                'flow_id' => $flow->wa_flow_id,
            ]);
            return false;
        }
    }

    /**
     * Elimina el flujo de los servidores de Meta y de la base local.
     * Irreversible. Sólo puede invocarse contra un Flujo en status DRAFT.
     *
     * @param Model $flow Flujo a eliminar.
     * @return bool
     */
    public function delete(Model $flow): bool
    {
        if (empty($flow->wa_flow_id)) {
            throw new InvalidArgumentException('El flujo no tiene un ID válido para ser eliminado de Meta.');
        }

        $endpoint = Endpoints::build(Endpoints::DELETE_FLOW, [
            'flow_id' => $flow->wa_flow_id,
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $flow->whatsappBusinessAccount->api_token,
        ];

        try {
            $this->apiClient->request('DELETE', $endpoint, [], [], [], $headers);
            $flow->delete(); // Soft-delete o hard-delete según defina tu modelo Eloquent
            return true;
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error al eliminar el flujo: ' . $e->getMessage(), [
                'flow_id' => $flow->wa_flow_id,
            ]);
            return false;
        }
    }
}