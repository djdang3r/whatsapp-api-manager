<?php

namespace ScriptDevelop\WhatsappManager\Services;

use Illuminate\Support\Facades\Log;
use ScriptDevelop\WhatsappManager\WhatsappApi\ApiClient;
use ScriptDevelop\WhatsappManager\WhatsappApi\Endpoints;
//use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;
use ScriptDevelop\WhatsappManager\Repositories\WhatsappBusinessAccountRepository;

use Illuminate\Database\Eloquent\Model;
use ScriptDevelop\WhatsappManager\Support\WhatsappModelResolver;

/**
 * Servicio para interactuar con la API de WhatsApp Business.
 * Proporciona métodos para gestionar cuentas empresariales, números de teléfono y perfiles.
 */
class WhatsappService
{
    /**
     * La cuenta empresarial de WhatsApp actualmente configurada.
     *
     * @var Model|null
     */
    protected ?Model $businessAccount = null;

    /**
     * Constructor de la clase.
     *
     * @param ApiClient $apiClient Cliente para realizar solicitudes a la API de WhatsApp.
     * @param WhatsappBusinessAccountRepository $accountRepo Repositorio para gestionar cuentas empresariales.
     */
    public function __construct(
        protected ApiClient $apiClient,
        protected WhatsappBusinessAccountRepository $accountRepo
    ) {}

    /**
     * Establece la cuenta empresarial a usar.
     *
     * @param string $accountId El ID de la cuenta empresarial.
     * @return self
     */
    public function forAccount(string $accountId): self
    {
        $this->businessAccount = $this->accountRepo->find($accountId);
        return $this;
    }

    /**
     * Asegura que una cuenta empresarial esté configurada.
     *
     * @return void
     * @throws \RuntimeException Si no se ha configurado una cuenta empresarial.
     */
    protected function ensureAccountIsSet(): void
    {
        if (!$this->businessAccount) {
            throw new \RuntimeException('Debes establecer una cuenta primero usando forAccount()');
        }
    }

    /**
     * Obtiene los headers de autenticación para las solicitudes a la API.
     *
     * @return array Los headers de autenticación.
     */
    protected function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->businessAccount->api_token
        ];
    }

    /**
     * Obtiene información de una cuenta empresarial de WhatsApp.
     *
     * @param string $whatsappBusinessId El ID de la cuenta empresarial de WhatsApp.
     * @return array La respuesta de la API con los detalles de la cuenta empresarial.
     */
    public function getBusinessAccount(string $whatsappBusinessId): array
    {
        $response = $this->apiClient->request(
            'GET',
            Endpoints::GET_BUSINESS_ACCOUNT,
            ['whatsapp_business_id' => $whatsappBusinessId],
            null,
            ['fields' => 'id,name,timezone_id,currency,country,status,whatsapp_business_manager_messaging_limit,message_template_namespace'],
            $this->getAuthHeaders()
        );

        Log::channel('whatsapp')->debug('Respuesta de getBusinessAccount:', $response);
        return $response;
    }

    /**
     * Obtiene el primary_funding_id de una cuenta empresarial.
     *
     * Este campo requiere permisos de BSP (Business Solution Provider).
     * Si la app no tiene esos permisos, la API devuelve error #10 (403).
     *
     * @param string $whatsappBusinessId El ID de la cuenta empresarial.
     * @return string|null El primary_funding_id, o null si no tiene permisos o no está configurado.
     */
    public function getBusinessAccountFundingId(string $whatsappBusinessId): ?string
    {
        $response = $this->apiClient->request(
            'GET',
            Endpoints::GET_BUSINESS_ACCOUNT,
            ['whatsapp_business_id' => $whatsappBusinessId],
            null,
            ['fields' => 'primary_funding_id'],
            $this->getAuthHeaders()
        );

        Log::channel('whatsapp')->debug('Respuesta de getBusinessAccountFundingId:', $response);
        return $response['primary_funding_id'] ?? null;
    }

    /**
     * Obtiene las aplicaciones suscritas a una cuenta empresarial de WhatsApp.
     *
     * @param string $whatsappBusinessId El ID de la cuenta empresarial de WhatsApp.
     * @return array La respuesta de la API con las aplicaciones suscritas.
     */
    public function getBusinessAccountApp(string $whatsappBusinessId): array
    {
        $response = $this->apiClient->request(
            'GET',
            Endpoints::GET_BUSINESS_ACCOUNT_SUBSCRIPTIONS, // Cambiado a SUBSCRIPTIONS
            ['whatsapp_business_id' => $whatsappBusinessId],
            headers: $this->getAuthHeaders()
        );

        Log::channel('whatsapp')->debug('Respuesta de getBusinessAccountApp:', $response);
        return $response;
    }

    /**
     * Obtiene los números de teléfono asociados a una cuenta empresarial.
     *
     * @param string $whatsappBusinessId El ID de la cuenta empresarial de WhatsApp.
     * @return array La respuesta de la API con los números de teléfono.
     */
    public function getPhoneNumbers(string $whatsappBusinessId): array
    {
        $response = $this->apiClient->request(
            'GET',
            Endpoints::GET_PHONE_NUMBERS,
            ['whatsapp_business_id' => $whatsappBusinessId],
            headers: $this->getAuthHeaders()
        );

        Log::channel('whatsapp')->debug('Respuesta de getPhoneNumbers API:', $response);
        return $response['data'] ?? $response;
    }

    public function getPhoneNumberNameStatus(string $phoneNumberId): array
    {
        $url = Endpoints::build(
            Endpoints::GET_PHONE_DETAILS,
            [
                'version' => config('whatsapp.api.version'),
                'phone_number_id' => $phoneNumberId
            ]
        ) . '?fields=name_status';

        Log::channel('whatsapp')->debug('URL de estado del nombre:', ['url' => $url]);

        return $this->apiClient->request(
            'GET',
            $url,
            headers: $this->getAuthHeaders()
        );
    }

    /**
     * Obtiene los detalles de un número de teléfono específico.
     *
     * @param string $phoneNumberId El ID del número de teléfono.
     * @return array La respuesta de la API con los detalles del número de teléfono.
     */
    public function getPhoneNumberDetails(string $phoneNumberId): array
    {
        $fields = urlencode('verified_name,code_verification_status,display_phone_number,'
        . 'quality_rating,platform_type,throughput,webhook_configuration,'
        . 'is_official_business_account,is_pin_enabled,status');

        $url = Endpoints::build(
            Endpoints::GET_PHONE_DETAILS,
            [
                'version' => config('whatsapp.api.version'),
                'phone_number_id' => $phoneNumberId
            ]
        ) . '?fields=' . $fields;

        Log::channel('whatsapp')->debug('URL de detalles de número:', ['url' => $url]);

        return $this->apiClient->request(
            'GET',
            $url,
            headers: $this->getAuthHeaders()
        );
    }

    /**
     * Obtiene el perfil empresarial asociado a un número de teléfono.
     *
     * @param string $phoneNumberId El ID del número de teléfono.
     * @return array La respuesta de la API con los detalles del perfil empresarial.
     */
    public function getBusinessProfile(string $phoneNumberId): array
    {
        $url = Endpoints::build(Endpoints::GET_BUSINESS_PROFILE, [
            'phone_number_id' => $phoneNumberId
        ]) . '?' . http_build_query([
            'fields' => 'about,address,description,email,profile_picture_url,websites,vertical'
        ]);

        Log::channel('whatsapp')->debug('URL de Solicitud de Perfil:', ['url' => $url]);

        $response = $this->apiClient->request(
            'GET',
            $url,
            headers: $this->getAuthHeaders()
        );

        return $response;
    }

    /**
     * Configura un token temporal para realizar solicitudes a la API.
     *
     * @param string $token El token temporal.
     * @return self
     */
    public function withTempToken(string $token): self
    {
        // Resolver el modelo configurado para la cuenta empresarial
        $businessAccount = WhatsappModelResolver::make('business_account', ['api_token' => $token]);

        // Asignar la instancia del modelo a la propiedad
        $this->businessAccount = $businessAccount;

        return $this;
    }

    /**
     * Actualiza una cuenta empresarial
     */
    public function updateBusinessAccount(string $businessAccountId, array $data): Model
    {
        $account = $this->accountRepo->find($businessAccountId);

        if (isset($data['api_token'])) {
            $account->api_token = $data['api_token'];
        }

        $account->fill($data);
        $account->save();

        return $account;
    }

    /**
     * Suscribe una aplicación a la cuenta empresarial de WhatsApp actual
     *
     * @param array $subscribedFields Campos a suscribir (opcional)
     * @return array
     */
    public function subscribeApp(?array $subscribedFields = null): array
    {
        $this->ensureAccountIsSet();

        // Si no se proporcionan campos, usar los de configuración
        if ($subscribedFields === null) {
            $subscribedFields = config('whatsapp.webhook.subscribed_fields', []);
        }

        $requestBody = [];
        if (!empty($subscribedFields)) {
            $requestBody['subscribed_fields'] = $subscribedFields;
        }

        Log::channel('whatsapp')->debug('Suscribiendo aplicación', [
            'business_id' => $this->businessAccount->whatsapp_business_id,
            'subscribed_fields' => $subscribedFields
        ]);

        $response = $this->apiClient->request(
            'POST',
            Endpoints::SUBSCRIBE_APP,
            ['whatsapp_business_id' => $this->businessAccount->whatsapp_business_id],
            $requestBody,
            headers: $this->getAuthHeaders()
        );

        Log::channel('whatsapp')->debug('Respuesta de subscribeApp:', $response);
        return $response;
    }

    /**
     * Obtiene los campos suscritos actualmente para una cuenta empresarial
     *
     * @param string $whatsappBusinessId
     * @return array
     */
    public function getSubscribedFields(string $whatsappBusinessId): array
    {
        $this->ensureAccountIsSet();

        $response = $this->apiClient->request(
            'GET',
            Endpoints::GET_BUSINESS_ACCOUNT_SUBSCRIPTIONS,
            ['whatsapp_business_id' => $whatsappBusinessId],
            headers: $this->getAuthHeaders()
        );

        Log::channel('whatsapp')->debug('Campos suscritos actuales:', $response);

        // Extraer campos suscritos de la respuesta
        $subscribedFields = [];
        if (isset($response['data'][0]['subscribed_fields'])) {
            $subscribedFields = $response['data'][0]['subscribed_fields'];
        }

        return $subscribedFields;
    }

    /**
     * Actualiza los campos suscritos para una cuenta empresarial
     *
     * @param string $whatsappBusinessId
     * @param array $subscribedFields
     * @return array
     */
    public function updateSubscribedFields(string $whatsappBusinessId, array $subscribedFields): array
    {
        // Primero establecemos la cuenta
        $this->forAccount($whatsappBusinessId);
        // Luego llamamos a subscribeApp con los campos
        return $this->subscribeApp($subscribedFields);
    }

    /**
     * Registra un nuevo número telefónico
     */
    public function registerPhoneNumber(string $businessAccountId, array $phoneData): Model
    {
        $this->forAccount($businessAccountId);
        $account = $this->accountRepo->find($businessAccountId);
        $registrationService = app(AccountRegistrationService::class); // Resuelve aquí
        return $registrationService->registerSinglePhoneNumber($account, $phoneData);
    }

    /**
     * Elimina un número telefónico
     */
    public function deletePhoneNumber(string $phoneNumberId): bool
    {
        $phone = WhatsappModelResolver::phone_number()->find($phoneNumberId);

        if ($phone) {
            if ($phone->businessProfile) {
                $phone->businessProfile->delete();
            }
            return $phone->delete();
        }

        return false;
    }

    /**
     * Configura el webhook para un número
     */
    public function configureWebhook(string $phoneNumberId, string $url, string $verifyToken): array
    {
        $this->ensureAccountIsSet();

        $phone = WhatsappModelResolver::phone_number()->find($phoneNumberId);
        if (!$phone) {
            throw new \RuntimeException('Número telefónico no encontrado');
        }

        $apiPhoneId = $phone->api_phone_number_id;

        $endpointUrl = Endpoints::build(Endpoints::CONFIGURE_WEBHOOK, [
            'phone_number_id' => $apiPhoneId
        ]);

        Log::channel('whatsapp')->debug('Configurando webhook', [
            'url' => $endpointUrl,
            'webhook_url' => $url,
            'verify_token' => $verifyToken
        ]);

        Log::channel('whatsapp')->debug('Configurando webhook', [
            'url' => $endpointUrl,
            'webhook_url' => $url,
            'verify_token' => $verifyToken
        ]);

        $response = $this->apiClient->request(
            'POST',
            $endpointUrl,
            [], // Parámetros de consulta
            [   // Cuerpo de la solicitud
                'webhook_url' => $url,
                'verify_token' => $verifyToken,
            ],
            [   // Opciones
                'headers' => $this->getAuthHeaders()
            ]
        );

        $phone->update([
            'webhook_configuration' => [
                'url' => $url,
                'verify_token' => $verifyToken
            ]
        ]);

        return $response;
    }

    /**
     * Obtiene las aplicaciones suscritas a la cuenta empresarial actual
     *
     * @return array
     */
    public function subscribedApps(): array
    {
        $this->ensureAccountIsSet();

        $response = $this->apiClient->request(
            'GET',
            Endpoints::GET_BUSINESS_ACCOUNT_SUBSCRIPTIONS,
            ['whatsapp_business_id' => $this->businessAccount->whatsapp_business_id],
            headers: $this->getAuthHeaders()
        );

        Log::channel('whatsapp')->debug('Respuesta de subscribedApps:', $response);
        return $response;
    }

    /**
     * Cancela la suscripción de una aplicación a la cuenta empresarial actual
     *
     * @return array
     */
    public function unsubscribeApp(): array
    {
        $this->ensureAccountIsSet();

        Log::channel('whatsapp')->debug('Cancelando suscripción de aplicación', [
            'business_id' => $this->businessAccount->whatsapp_business_id
        ]);

        $response = $this->apiClient->request(
            'DELETE',
            Endpoints::UNSUBSCRIBE_APP,
            ['whatsapp_business_id' => $this->businessAccount->whatsapp_business_id],
            headers: $this->getAuthHeaders()
        );

        Log::channel('whatsapp')->debug('Respuesta de unsubscribeApp:', $response);
        return $response;
    }

    /**
     * Sobrescribe la URL de devolución de llamada de webhooks para la WABA actual.
     *
     * @param string $url URL alternativa (max 200 caracteres).
     * @param string $verifyToken Token de verificación para la URL.
     * @return array
     */
    public function overrideWabaWebhook(string $url, string $verifyToken): array
    {
        $this->ensureAccountIsSet();

        $requestBody = [
            'override_callback_uri' => $url,
            'verify_token' => $verifyToken
        ];

        Log::channel('whatsapp')->debug('Sobrescribiendo webhook de WABA', [
            'business_id' => $this->businessAccount->whatsapp_business_id,
            'url' => $url
        ]);

        return $this->apiClient->request(
            'POST',
            Endpoints::SUBSCRIBE_APP,
            ['whatsapp_business_id' => $this->businessAccount->whatsapp_business_id],
            $requestBody,
            headers: $this->getAuthHeaders()
        );
    }

    /**
     * Elimina la URL de devolución de llamada alternativa de la WABA actual,
     * restaurando la configurada en el panel de la aplicación.
     *
     * @return array
     */
    public function removeWabaWebhookOverride(): array
    {
        $this->ensureAccountIsSet();

        Log::channel('whatsapp')->debug('Eliminando sobreescritura de webhook de WABA', [
            'business_id' => $this->businessAccount->whatsapp_business_id
        ]);

        // Según la documentación oficial, se envía el POST sin parámetros en el cuerpo
        return $this->apiClient->request(
            'POST',
            Endpoints::SUBSCRIBE_APP,
            ['whatsapp_business_id' => $this->businessAccount->whatsapp_business_id],
            [],
            headers: $this->getAuthHeaders()
        );
    }

    /**
     * Sobrescribe la URL de devolución de llamada de webhooks para un número telefónico.
     *
     * @param string $phoneNumberId El ID del número de teléfono local.
     * @param string $url URL alternativa.
     * @param string $verifyToken Token de verificación.
     * @return array
     */
    public function overridePhoneWebhook(string $phoneNumberId, string $url, string $verifyToken): array
    {
        $this->ensureAccountIsSet();

        $phone = WhatsappModelResolver::phone_number()->find($phoneNumberId);
        if (!$phone) {
            throw new \RuntimeException('Número telefónico no encontrado');
        }

        $apiPhoneId = $phone->api_phone_number_id;

        $endpointUrl = Endpoints::build(Endpoints::MANAGE_PHONE_NUMBER, [
            'phone_number_id' => $apiPhoneId
        ]);

        $requestBody = [
            'webhook_configuration' => [
                'override_callback_uri' => $url,
                'verify_token' => $verifyToken
            ]
        ];

        Log::channel('whatsapp')->debug('Sobrescribiendo webhook de Phone Number', [
            'phone_number_id' => $apiPhoneId,
            'url' => $url
        ]);

        $response = $this->apiClient->request(
            'POST',
            $endpointUrl,
            [], // Parámetros de consulta
            $requestBody,
            ['headers' => $this->getAuthHeaders()]
        );

        $phone->update([
            'webhook_configuration' => [
                'url' => $url,
                'verify_token' => $verifyToken
            ]
        ]);

        return $response;
    }

    /**
     * Elimina la URL de devolución de llamada alternativa de un número telefónico.
     *
     * @param string $phoneNumberId El ID del número de teléfono local.
     * @return array
     */
    public function removePhoneWebhookOverride(string $phoneNumberId): array
    {
        $this->ensureAccountIsSet();

        $phone = WhatsappModelResolver::phone_number()->find($phoneNumberId);
        if (!$phone) {
            throw new \RuntimeException('Número telefónico no encontrado');
        }

        $apiPhoneId = $phone->api_phone_number_id;

        $endpointUrl = Endpoints::build(Endpoints::MANAGE_PHONE_NUMBER, [
            'phone_number_id' => $apiPhoneId
        ]);

        $requestBody = [
            'webhook_configuration' => [
                'override_callback_uri' => ''
            ]
        ];

        Log::channel('whatsapp')->debug('Eliminando sobreescritura de webhook de Phone Number', [
            'phone_number_id' => $apiPhoneId
        ]);

        $response = $this->apiClient->request(
            'POST',
            $endpointUrl,
            [], // Parámetros de consulta
            $requestBody,
            ['headers' => $this->getAuthHeaders()]
        );

        $phone->update([
            'webhook_configuration' => null
        ]);

        return $response;
    }

    /**
     * Registra un número telefónico en la API de WhatsApp
     * Nota: Limitado a 10 solicitudes por número de negocio en 72 horas
     *
     * @param string $phoneNumberId
     * @param array $data Datos de registro
     * @return array
     */
    public function registerPhone(string $phoneNumberId, array $data = []): array
    {
        $this->ensureAccountIsSet();

        // Campos por defecto para el registro
        $fields = $data['fields'] ?? 'primary_funding_id';

        $url = Endpoints::build(
            Endpoints::GET_BUSINESS_ACCOUNT,
            ['whatsapp_business_id' => $phoneNumberId]
        ) . '?' . http_build_query(['fields' => $fields]);

        Log::channel('whatsapp')->debug('Registrando número telefónico', [
            'phone_number_id' => $phoneNumberId,
            'fields' => $fields
        ]);

        $response = $this->apiClient->request(
            'GET',
            $url,
            headers: $this->getAuthHeaders()
        );

        Log::channel('whatsapp')->debug('Respuesta de registerPhone:', $response);
        return $response;
    }

    /**
     * Actualiza el perfil de empresa del número de teléfono en la API de WhatsApp
     * y sincroniza los campos escalares en la base de datos local.
     *
     * Campos permitidos: about, address, description, email, vertical,
     *                    profile_picture_handle, websites
     *
     * @param string $phoneNumberId ID de la API del número de teléfono (api_phone_number_id)
     * @param array  $data          Campos a actualizar
     * @return array Respuesta de la API ({ "success": true })
     */
    public function updateBusinessProfile(string $phoneNumberId, array $data): array
    {
        $this->ensureAccountIsSet();

        $endpoint = Endpoints::build(Endpoints::GET_BUSINESS_PROFILE, [
            'phone_number_id' => $phoneNumberId,
        ]);

        $payload = array_merge(['messaging_product' => 'whatsapp'], $data);

        $response = $this->apiClient->request(
            'POST',
            $endpoint,
            [],
            $payload,
            [],
            $this->getAuthHeaders()
        );

        Log::channel('whatsapp')->debug('Respuesta de updateBusinessProfile:', $response);

        // Sincronizar campos escalares en la BD local
        if (isset($response['success']) && $response['success']) {
            $phone = WhatsappModelResolver::phone_number()
                ->where('api_phone_number_id', $phoneNumberId)
                ->with('businessProfile')
                ->first();

            if ($phone && $phone->businessProfile) {
                $syncable = array_intersect_key($data, array_flip([
                    'about', 'address', 'description', 'email', 'vertical',
                ]));
                if (!empty($syncable)) {
                    $phone->businessProfile->update($syncable);
                }
            }
        }

        return $response;
    }

    /**
     * Solicita un cambio de nombre visible al número de teléfono.
     * El nombre queda en estado PENDING_REVIEW hasta que Meta lo aprueba o rechaza.
     * El webhook phone_number_name_update notifica la decisión final.
     *
     * @param string $phoneNumberId  ID de la API del número de teléfono (api_phone_number_id)
     * @param string $newDisplayName Nuevo nombre visible solicitado
     * @return array Respuesta de la API ({ "success": true })
     */
    public function updateDisplayName(string $phoneNumberId, string $newDisplayName): array
    {
        $this->ensureAccountIsSet();

        $url = Endpoints::build(Endpoints::MANAGE_PHONE_NUMBER, [
            'phone_number_id' => $phoneNumberId,
        ]) . '?' . http_build_query(['new_display_name' => $newDisplayName]);

        $response = $this->apiClient->request(
            'POST',
            $url,
            [],
            [],
            [],
            $this->getAuthHeaders()
        );

        Log::channel('whatsapp')->debug('Respuesta de updateDisplayName:', $response);

        // Persistir estado pendiente en la BD para que la app pueda mostrarlo
        if (isset($response['success']) && $response['success']) {
            WhatsappModelResolver::phone_number()
                ->where('api_phone_number_id', $phoneNumberId)
                ->update([
                    'new_display_name' => $newDisplayName,
                    'new_name_status'  => 'PENDING_REVIEW',
                ]);
        }

        return $response;
    }

    /**
     * Consulta el estado actual del nombre visible en revisión directamente desde la API
     * y sincroniza new_display_name y new_name_status en la BD local.
     *
     * @param string $phoneNumberId ID de la API del número de teléfono (api_phone_number_id)
     * @return array Respuesta de la API ({ new_display_name, new_name_status, id })
     */
    public function getDisplayNamePendingStatus(string $phoneNumberId): array
    {
        $this->ensureAccountIsSet();

        $url = Endpoints::build(Endpoints::MANAGE_PHONE_NUMBER, [
            'phone_number_id' => $phoneNumberId,
        ]) . '?' . http_build_query(['fields' => 'new_display_name,new_name_status']);

        $response = $this->apiClient->request(
            'GET',
            $url,
            [],
            null,
            [],
            $this->getAuthHeaders()
        );

        Log::channel('whatsapp')->debug('Respuesta de getDisplayNamePendingStatus:', $response);

        // Sincronizar BD
        $updateData = array_filter([
            'new_display_name' => $response['new_display_name'] ?? null,
            'new_name_status'  => $response['new_name_status'] ?? null,
        ], fn($v) => $v !== null);

        if (!empty($updateData)) {
            WhatsappModelResolver::phone_number()
                ->where('api_phone_number_id', $phoneNumberId)
                ->update($updateData);
        }

        return $response;
    }

    /**
     * Envía una solicitud de estado de Cuenta de Empresa Oficial (OBA).
     * La aprobación/rechazo llega por notificación de Meta Business Suite —
     * no existe un webhook específico; se recomienda consultar periódicamente
     * con getOfficialBusinessAccountStatus().
     *
     * @param string $phoneNumberId ID de la API del número de teléfono (api_phone_number_id)
     * @param array  $data          Datos del formulario OBA:
     *                              - additional_supporting_information (string, opcional)
     *                              - business_website_url (string)
     *                              - parent_business_or_brand (string)
     *                              - primary_country_of_operation (string)
     *                              - primary_language (string)
     *                              - supporting_links (array de URLs, máx. 5)
     * @return array Respuesta de la API ({ "success": true })
     */
    public function requestOfficialBusinessAccount(string $phoneNumberId, array $data): array
    {
        $this->ensureAccountIsSet();

        $endpoint = Endpoints::build(Endpoints::OFFICIAL_BUSINESS_ACCOUNT, [
            'phone_number_id' => $phoneNumberId,
        ]);

        $response = $this->apiClient->request(
            'POST',
            $endpoint,
            [],
            $data,
            [],
            $this->getAuthHeaders()
        );

        Log::channel('whatsapp')->debug('Respuesta de requestOfficialBusinessAccount:', $response);

        // Marcar como pendiente en BD si el envío fue exitoso
        if (isset($response['success']) && $response['success']) {
            WhatsappModelResolver::phone_number()
                ->where('api_phone_number_id', $phoneNumberId)
                ->update(['oba_status' => 'PENDING']);
        }

        return $response;
    }

    /**
     * Consulta el estado actual de la solicitud OBA directamente desde la API
     * y sincroniza oba_status e is_official en la BD local.
     *
     * @param string $phoneNumberId ID de la API del número de teléfono (api_phone_number_id)
     * @return array Respuesta de la API con el campo official_business_account
     */
    public function getOfficialBusinessAccountStatus(string $phoneNumberId): array
    {
        $this->ensureAccountIsSet();

        $url = Endpoints::build(Endpoints::MANAGE_PHONE_NUMBER, [
            'phone_number_id' => $phoneNumberId,
        ]) . '?' . http_build_query(['fields' => 'official_business_account,is_official_business_account']);

        $response = $this->apiClient->request(
            'GET',
            $url,
            [],
            null,
            [],
            $this->getAuthHeaders()
        );

        Log::channel('whatsapp')->debug('Respuesta de getOfficialBusinessAccountStatus:', $response);

        // Sincronizar BD
        $obaStatus  = $response['official_business_account']['oba_status'] ?? null;
        $isOfficial = $response['is_official_business_account'] ?? null;

        $updateData = array_filter([
            'oba_status' => $obaStatus,
            'is_official' => $isOfficial !== null ? (bool) $isOfficial : null,
        ], fn($v) => $v !== null);

        if (!empty($updateData)) {
            WhatsappModelResolver::phone_number()
                ->where('api_phone_number_id', $phoneNumberId)
                ->update($updateData);
        }

        return $response;
    }

    /**
     * Sube una imagen y la establece como foto de perfil de empresa en un solo paso.
     *
     * Internamente crea la sesión de carga, sube el archivo y llama a updateBusinessProfile()
     * con el handle resultante — el usuario solo provee la ruta local y el mime type.
     *
     * @param string $phoneNumberId ID de la API del número de teléfono (api_phone_number_id)
     * @param string $filePath      Ruta absoluta al archivo de imagen (jpg, png)
     * @param string $mimeType      MIME type del archivo (por defecto image/jpeg)
     * @return array Respuesta de la API ({ "success": true })
     */
    public function updateBusinessProfilePicture(
        string $phoneNumberId,
        string $filePath,
        string $mimeType = 'image/jpeg'
    ): array {
        $this->ensureAccountIsSet();

        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("El archivo no existe: {$filePath}");
        }

        // 1. Crear sesión de carga
        $sessionId = $this->createProfilePictureUploadSession($filePath, $mimeType);

        // 2. Subir el archivo y obtener el handle
        $handle = $this->uploadProfilePictureFile($sessionId, $filePath);

        // 3. Actualizar perfil con el handle
        return $this->updateBusinessProfile($phoneNumberId, [
            'profile_picture_handle' => $handle,
        ]);
    }

    /**
     * Crea una sesión de carga para la foto de perfil de empresa.
     * Retorna el ID de sesión a usar en el upload.
     */
    private function createProfilePictureUploadSession(string $filePath, string $mimeType): string
    {
        $appId = !empty($this->businessAccount->app_id)
            ? $this->businessAccount->app_id
            : config('whatsapp.meta_auth.client_id');

        if (empty($appId)) {
            throw new \RuntimeException(
                "No se encontró un App ID válido. Verifica 'whatsapp.meta_auth.client_id'."
            );
        }

        $baseUrl = config('whatsapp.api.base_url', 'https://graph.facebook.com');
        $version = config('whatsapp.api.version', 'v22.0');
        $url     = rtrim($baseUrl, '/') . '/' . ltrim($version, '/') . "/{$appId}/uploads";

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode([
                'file_name' => basename($filePath),
                'file_type' => $mimeType,
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->businessAccount->api_token,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if (curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException("cURL error al crear sesión de carga: {$error}");
        }
        curl_close($curl);

        if ($httpCode !== 200) {
            throw new \RuntimeException(
                "Error al crear sesión de carga. HTTP {$httpCode}: {$response}"
            );
        }

        $data = json_decode($response, true);
        $sessionId = $data['id'] ?? null;

        if (!$sessionId) {
            throw new \RuntimeException('No se pudo obtener el ID de sesión de carga.');
        }

        Log::channel('whatsapp')->info('Sesión de carga de foto de perfil creada.', [
            'session_id' => $sessionId,
        ]);

        return $sessionId;
    }

    /**
     * Sube el archivo de imagen a la sesión de carga y retorna el handle resultante.
     */
    private function uploadProfilePictureFile(string $sessionId, string $filePath): string
    {
        $baseUrl = config('whatsapp.api.base_url', 'https://graph.facebook.com');
        $version = config('whatsapp.api.version', 'v22.0');
        $url     = rtrim($baseUrl, '/') . '/' . ltrim($version, '/') . "/{$sessionId}";

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => file_get_contents($filePath),
            CURLOPT_HTTPHEADER     => [
                'file_offset: 0',
                'Content-Type: application/octet-stream',
                'Authorization: OAuth ' . $this->businessAccount->api_token,
            ],
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if (curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException("cURL error al subir foto de perfil: {$error}");
        }
        curl_close($curl);

        if ($httpCode !== 200) {
            throw new \RuntimeException(
                "Error al subir foto de perfil. HTTP {$httpCode}: {$response}"
            );
        }

        $data   = json_decode($response, true);
        $handle = $data['h'] ?? null;

        if (!$handle) {
            throw new \RuntimeException('No se pudo obtener el handle del archivo subido.');
        }

        Log::channel('whatsapp')->info('Foto de perfil de empresa subida exitosamente.', [
            'handle' => $handle,
        ]);

        return $handle;
    }
}