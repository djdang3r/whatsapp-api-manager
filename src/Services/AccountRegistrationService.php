<?php

namespace ScriptDevelop\WhatsappManager\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ScriptDevelop\WhatsappManager\Helpers\CountryCodes;
use ScriptDevelop\WhatsappManager\Helpers\MessagingLimitHelper;
//use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;
//use ScriptDevelop\WhatsappManager\Models\WhatsappPhoneNumber;
//use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessProfile;
use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;
use ScriptDevelop\WhatsappManager\WhatsappApi\Exceptions\ApiException;
use ScriptDevelop\WhatsappManager\Exceptions\InvalidApiResponseException;
use ScriptDevelop\WhatsappManager\WhatsappApi\Validators\BusinessProfileValidator;

use Illuminate\Database\Eloquent\Model;
use ScriptDevelop\WhatsappManager\Support\WhatsappModelResolver;

/**
 * Servicio para registrar cuentas empresariales de WhatsApp y gestionar sus números telefónicos.
 * Este servicio se encarga de validar la entrada, registrar o actualizar cuentas empresariales,
 * registrar números telefónicos y vincular perfiles empresariales a los números.
 */
class AccountRegistrationService
{
    /**
     * @var WhatsappService
     */
    use GeneratesUlid;

    /**
     * Constructor del servicio de registro de cuentas empresariales.
     *
     * @param WhatsappService $whatsappService Servicio de WhatsApp.
     */
    public function __construct(
        protected WhatsappService $whatsappService
    ) {}

    /**
     * Registra una cuenta empresarial de WhatsApp y sus números telefónicos.
     *
     * @param array $data Datos de la cuenta empresarial.
     * @return Model La cuenta empresarial registrada o actualizada.
     * @throws ApiException Si ocurre un error al interactuar con la API de WhatsApp.
     * @throws InvalidApiResponseException Si la respuesta de la API es inválida.
     */
    public function register(array $data, ?array $subscribedFields = null): Model
    {
        Log::channel('whatsapp')->info('Iniciando registro de cuenta', ['business_id' => $data['business_id']]);

        $this->validateInput($data);

        try {
            // 1. Registrar/Actualizar cuenta empresarial (el token se encripta automáticamente)
            $accountData = $this->fetchAccountData($data);
            $suscriptions = $this->fetchAccountDataSuscriptions($data);

            $account = $this->upsertBusinessAccount($data['api_token'], $accountData, $suscriptions);

            // 1b. Intentar obtener el método de pago (requiere permisos BSP — opcional)
            $this->syncPrimaryFundingId($data['api_token'], $data['business_id'], $account);


            // 2. Suscribir aplicación a webhooks (usando campos proporcionados o de configuración)
            $this->whatsappService->forAccount($account->whatsapp_business_id)
                ->subscribeApp($subscribedFields);

            // 3. Registrar números telefónicos
            $this->registerPhoneNumbers($account);

            // 4. Vincular perfiles empresariales
            $this->linkBusinessProfilesToPhones($account);

            return $account->load(['phoneNumbers.businessProfile']);

        } catch (ApiException | InvalidApiResponseException $e) {
            Log::channel('whatsapp')->error("Error registro cuenta: {$e->getMessage()}");
            throw $e;
        }
    }


    /**
     * Valida la entrada de datos para el registro de la cuenta empresarial.
     *
     * @param array $data Datos de la cuenta empresarial.
     * @throws \InvalidArgumentException Si los datos son inválidos.
     */
    private function validateInput(array $data): void
    {
        if (empty($data['api_token']) || empty($data['business_id'])) {
            throw new \InvalidArgumentException('Token API y Business ID son requeridos');
        }
    }

    /**
     * Obtiene los datos de la cuenta empresarial desde la API de WhatsApp.
     *
     * @param array $data Datos de la cuenta empresarial.
     * @return array Datos de la cuenta empresarial.
     * @throws ApiException Si ocurre un error al interactuar con la API de WhatsApp.
     */
    protected function fetchAccountData(array $data): array
    {
        // Usa el token temporal SIN encriptar para la verificación inicial
        $response = $this->whatsappService
            ->withTempToken($data['api_token'])
            ->getBusinessAccount($data['business_id']);

        Log::channel('whatsapp')->debug('RESPUESTA API BUSINESS ACCOUNT:', [
            'business_id' => $data['business_id'],
            'response' => $response
        ]);

        return $response;
    }

    protected function fetchAccountDataSuscriptions(array $data): array
    {
        // Usa el token temporal SIN encriptar para la verificación inicial
        $response = $this->whatsappService
            ->withTempToken($data['api_token'])
            ->getBusinessAccountApp($data['business_id']);

        Log::channel('whatsapp')->debug('RESPUESTA API BUSINESS ACCOUNT SUSCRIPTIONS:', [
            'business_id' => $data['business_id'],
            'response' => $response
        ]);

        return $response;
    }

    /**
     * Intenta sincronizar el primary_funding_id de la cuenta.
     *
     * Este campo requiere permisos de BSP. Si la API devuelve error de permisos
     * (o cualquier ApiException), se loguea como warning y se continúa sin interrumpir
     * el registro. El campo quedará null para cuentas sin permisos BSP.
     */
    private function syncPrimaryFundingId(string $apiToken, string $businessId, Model $account): void
    {
        try {
            $fundingId = $this->whatsappService
                ->withTempToken($apiToken)
                ->getBusinessAccountFundingId($businessId);

            if ($fundingId !== $account->primary_funding_id) {
                $account->primary_funding_id = $fundingId;
                $account->save();
            }
        } catch (ApiException $e) {
            Log::channel('whatsapp')->warning('No se pudo obtener primary_funding_id (posiblemente requiere permisos BSP).', [
                'business_id' => $businessId,
                'error'       => $e->getMessage(),
                'code'        => $e->getCode(),
            ]);
        }
    }

    /**
     * Registra o actualiza la cuenta empresarial en la base de datos.
     *
     * @param string $apiToken Token de la API.
     * @param array $apiData Datos de la cuenta empresarial.
     * @return Model La cuenta empresarial registrada o actualizada.
     */
    private function upsertBusinessAccount(string $apiToken, array $apiData, $subscriptions = null): Model
    {
        $appData = [];

        if (!empty($subscriptions['data'][0]['whatsapp_business_api_data'])) {
            $appData = $subscriptions['data'][0]['whatsapp_business_api_data'];
        }

        // Obtener el límite de mensajes
        $messagingLimitTier = $apiData['whatsapp_business_manager_messaging_limit'] ?? null;
        $messagingLimitValue = MessagingLimitHelper::convertTierToLimitValue($messagingLimitTier);

        Log::channel('whatsapp')->debug('Valores de límite de mensajes extraídos:', [
            'messaging_limit_tier' => $messagingLimitTier,
            'messaging_limit_value' => $messagingLimitValue,
            'api_data_key_exists' => isset($apiData['whatsapp_business_manager_messaging_limit']),
            'api_data_value' => $apiData['whatsapp_business_manager_messaging_limit'] ?? 'NO EXISTE',
        ]);

        // El token se encripta automáticamente al guardar (vía mutador)
        $account = WhatsappModelResolver::business_account()->updateOrCreate(
            ['whatsapp_business_id' => $apiData['id']],
            [
                'name' => $apiData['name'] ?? 'Sin nombre',
                'api_token' => $apiToken, // Se encripta aquí
                'phone_number_id' => $apiData['phone_number_id'] ?? $apiData['id'],
                'timezone_id' => $apiData['timezone_id'] ?? 0,
                'app_id' => $appData['id'] ?? null,          // ID de la primera app
                'app_name' => $appData['name'] ?? null,      // Nombre de la primera app
                'app_link' => $appData['link'] ?? null,      // Link de la primera app
                'message_template_namespace' => $apiData['message_template_namespace'] ?? null,
            ]
        );

        // Actualizar explícitamente los campos de límite de mensajes
        // Esto asegura que se guarden incluso si el registro ya existía
        $account->messaging_limit_tier = $messagingLimitTier;
        $account->messaging_limit_value = $messagingLimitValue;
        $account->save();

        Log::channel('whatsapp')->debug('Cuenta guardada/actualizada:', [
            'whatsapp_business_id' => $account->whatsapp_business_id,
            'messaging_limit_tier' => $account->messaging_limit_tier,
            'messaging_limit_value' => $account->messaging_limit_value,
            'wasRecentlyCreated' => $account->wasRecentlyCreated,
        ]);

        return $account;
    }

    /**
     * Registra los números telefónicos asociados a la cuenta empresarial.
     *
     * @param Model $account La cuenta empresarial.
     * @throws ApiException Si ocurre un error al interactuar con la API de WhatsApp.
     */
    private function registerPhoneNumbers(Model $account): void
    {
        try {
            $this->whatsappService->forAccount($account->whatsapp_business_id);

            $response = $this->whatsappService->getPhoneNumbers($account->whatsapp_business_id);

            foreach ($response as $phoneData) {
                $this->updateOrCreatePhoneNumber($account, $phoneData);
            }
        } catch (ApiException $e) {
            Log::channel('whatsapp')->error("Error números telefónicos: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Actualiza o crea un número telefónico en la base de datos.
     *
     * @param Model $account La cuenta empresarial.
     * @param array $phoneData Datos del número telefónico.
     * @return Model El número telefónico registrado o actualizado.
     * @throws ApiException Si ocurre un error al interactuar con la API de WhatsApp.
     */
    private function updateOrCreatePhoneNumber(Model $account, array $phoneData): Model
    {
        try {
            $phoneDetails = $this->whatsappService->getPhoneNumberDetails($phoneData['id']);

            $nameStatusData = $this->whatsappService->getPhoneNumberNameStatus($phoneData['id']);
            $nameStatus = $nameStatusData['name_status'] ?? null;

            // Obtener todos los códigos de país ordenados por longitud (de mayor a menor)
            $countryCodes = CountryCodes::codes();
            usort($countryCodes, function($a, $b) {
                return strlen($b) <=> strlen($a);
            });

            // Extraer código de país y número
            $rawNumber = preg_replace('/[^\d]/', '', $phoneDetails['display_phone_number']);
            $countryCode = null;
            $phoneNumber = $rawNumber;

            // Buscar el código de país más largo que coincida al principio
            foreach ($countryCodes as $code) {
                if (strpos($rawNumber, $code) === 0) {
                    $countryCode = $code;
                    $phoneNumber = substr($rawNumber, strlen($code));
                    break;
                }
            }

            return WhatsappModelResolver::phone_number()->updateOrCreate(
                ['api_phone_number_id' => $phoneData['id']],
                [
                    'whatsapp_business_account_id' => $account->whatsapp_business_id,
                    'display_phone_number' => $phoneDetails['display_phone_number'] ?? null,
                    'country_code' => $countryCode,
                    'phone_number' => $phoneNumber,
                    'name_status' => $nameStatus,
                    'verified_name' => $phoneDetails['verified_name'] ?? null,
                    'code_verification_status' => $phoneDetails['code_verification_status'] ?? null,
                    'quality_rating' => $phoneDetails['quality_rating'] ?? null,
                    'platform_type' => $phoneDetails['platform_type'] ?? null,
                    'throughput' => $phoneDetails['throughput'] ?? null,
                    'api_phone_number_id' => $phoneData['id'],
                    'webhook_configuration' => $phoneDetails['webhook_configuration'] ?? null
                ]
            );
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error al guardar número', [
                'error' => $e->getMessage(),
                'data' => $phoneData
            ]);
            throw $e;
        }
    }

    /**
     * Vincula los perfiles empresariales a los números telefónicos.
     *
     * @param Model $account La cuenta empresarial.
     * @throws ApiException Si ocurre un error al interactuar con la API de WhatsApp.
     */
    private function linkBusinessProfilesToPhones(Model $account): void
    {
        foreach ($account->phoneNumbers as $phone) {
            $this->processPhoneNumberProfile($phone);
        }
    }

    /**
     * Procesa el perfil empresarial de un número telefónico.
     *
     * @param Model $phone El número telefónico.
     */
    private function processPhoneNumberProfile(Model $phone): void
    {
        try {
            $profileData = $this->whatsappService
                ->forAccount($phone->whatsapp_business_account_id)
                ->getBusinessProfile($phone->api_phone_number_id);

            if (!isset($profileData['data'][0])) {
                Log::channel('whatsapp')->warning("Perfil no encontrado para teléfono {$phone->api_phone_number_id}, se omite.");
                return;
            }

            $this->upsertBusinessProfile($phone, $profileData['data'][0]);
        } catch (ApiException | InvalidApiResponseException $e) {
            // El perfil empresarial es opcional: si Meta no permite acceder a él
            // (error #131000 u otro), logueamos como warning y continuamos.
            // Esto NO debe bloquear el registro de la cuenta.
            Log::channel('whatsapp')->warning("No se pudo obtener perfil del teléfono {$phone->api_phone_number_id}: {$e->getMessage()}");
        }
    }

    /**
     * Actualiza o crea un perfil empresarial en la base de datos.
     *
     * @param Model $phone El número telefónico.
     * @param array $profileData Datos del perfil empresarial.
     * @throws InvalidApiResponseException Si la respuesta de la API es inválida.
     */
    private function upsertBusinessProfile(Model $phone, array $profileData): void
    {
        try {
            $validator = new BusinessProfileValidator();
            $validData = $validator->validate($profileData);

            $profile = $phone->businessProfile;

            if ($profile) {
                // Actualizar perfil existente
                $profile->update($validData);
            } else {
                // Crear nuevo perfil solo si no existe
                $profile = WhatsappModelResolver::business_profile()->create($validData);
                $phone->update(['whatsapp_business_profile_id' => $profile->whatsapp_business_profile_id]);
            }

            // Sincronizar websites
            $this->syncWebsites(
                $profile,
                $this->parseWebsites($profileData['websites'] ?? [])
            );
        } catch (InvalidApiResponseException $e) {
            throw $e;
        }
    }

    /**
     * Convierte los datos de los sitios web a un formato adecuado para la base de datos.
     *
     * @param array $apiWebsites Datos de los sitios web desde la API.
     * @return array Datos de los sitios web convertidos.
     */
    private function parseWebsites(array $apiWebsites): array
    {
        return array_map(fn($website) => [
            'website' => is_array($website) ? ($website['url'] ?? null) : $website
        ], $apiWebsites);
    }

    /**
     * Sincroniza los sitios web del perfil empresarial en la base de datos.
     *
     * @param Model $profile El perfil empresarial.
     * @param array $websites Datos de los sitios web.
     */
    private function syncWebsites(Model $profile, array $websites): void
    {
        $profile->websites()->delete();

        foreach ($websites as $website) {
            if (!empty($website['website']) && filter_var($website['website'], FILTER_VALIDATE_URL)) {
                $profile->websites()->create($website);
            }
        }
    }

    /**
     * Registra un solo número telefónico
     */
    public function registerSinglePhoneNumber(Model $account, array $phoneData): Model
    {
        $phone = $this->updateOrCreatePhoneNumber($account, $phoneData);
        $this->processPhoneNumberProfile($phone);
        return $phone;
    }

    /**
     * Suscribe una aplicación a la cuenta empresarial de WhatsApp actual
     *
     * @param array $subscribedFields Campos a suscribir (opcional)
     * @return array
     */
    public function subscribeApp(?array $subscribedFields = null): array
    {
        return $this->whatsappService->subscribeApp($subscribedFields);
    }

    /**
     * Obtiene las aplicaciones suscritas a la cuenta empresarial actual
     *
     * @return array
     */
    public function subscribedApps(): array
    {
        return $this->whatsappService->subscribedApps();
    }

    /**
     * Cancela la suscripción de una aplicación a la cuenta empresarial actual
     *
     * @return array
     */
    public function unsubscribeApp(): array
    {
        return $this->whatsappService->unsubscribeApp();
    }

    /**
     * Registra un número telefónico en la API de WhatsApp
     *
     * @param string $phoneNumberId
     * @param array $data Datos de registro
     * @return array
     */
    public function registerPhone(string $phoneNumberId, array $data = []): array
    {
        return $this->whatsappService->registerPhone($phoneNumberId, $data);
    }
}