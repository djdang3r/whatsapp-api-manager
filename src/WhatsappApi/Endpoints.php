<?php

namespace ScriptDevelop\WhatsappManager\WhatsappApi;

use Illuminate\Support\Facades\Log;

/**
 * Class Endpoints
 * Define los endpoints de la API de WhatsApp Business.
 * Proporciona métodos para construir URLs con parámetros dinámicos.
 */
/**
 * @package ScriptDevelop\WhatsappManager\WhatsappApi
 */
class Endpoints
{
    // Business Account Endpoints
    const GET_BUSINESS_ACCOUNT = '{whatsapp_business_id}';
    const GET_BUSINESS_ACCOUNT_SUBSCRIPTIONS = '{whatsapp_business_id}/subscribed_apps';
    const SUBSCRIBE_APP = '{whatsapp_business_id}/subscribed_apps';
    const UNSUBSCRIBE_APP = '{whatsapp_business_id}/subscribed_apps';
    const GET_PHONE_NUMBERS = '{whatsapp_business_id}/phone_numbers';
    const GET_PHONE_DETAILS = '{phone_number_id}';
    const GET_BUSINESS_PROFILE = '{phone_number_id}/whatsapp_business_profile';
    const CONFIGURE_WEBHOOK = '{phone_number_id}/webhooks';
    const MANAGE_PHONE_NUMBER = '{phone_number_id}';
    const OFFICIAL_BUSINESS_ACCOUNT = '{phone_number_id}/official_business_account';
    const BLOCK_USERS = '{phone_number_id}/block_users';
    const UNBLOCK_USERS = '{phone_number_id}/block_users';
    const LIST_BLOCKED_USERS = '{phone_number_id}/block_users';

    // Commerce Settings (Catalog visibility)
    const WHATSAPP_COMMERCE_SETTINGS = '{phone_number_id}/whatsapp_commerce_settings';

    // Username Endpoints (disponibles desde 2026)
    const BUSINESS_USERNAME = '{phone_number_id}/username';
    const BUSINESS_USERNAME_SUGGESTIONS = '{phone_number_id}/username_suggestions';

    // Message Endpoints
    const SEND_MESSAGE = '{phone_number_id}/messages';
    const MARK_MESSAGE_AS_READ = '{phone_number_id}/messages';

    // Media Upload Endpoints
    const CREATE_RESUMABLE_UPLOAD_SESSION = 'app/uploads';
    const RESUMABLE_UPLOAD_FILE = '{upload_id}';
    const CREATE_UPLOAD_SESSION = '{app_id}/uploads';
    const SESSION_UPLOAD_MEDIA = '{session_id}';
    const QUERY_RESUMABLE_UPLOAD_STATUS = '{upload_id}';
    const RETRIEVE_MEDIA_URL = '{media_id}';
    const UPLOAD_MEDIA = '{phone_number_id}/media';
    CONST DOWNLOAD_MEDIA = '{media_id}';
    const DELETE_MEDIA = '{media_id}/?phone_number_id={phone_number_id}';

    // Templates Endpoints
    const GET_TEMPLATES = '{waba_id}/message_templates';
    const GET_TEMPLATE = '{template_id}';
    const CREATE_TEMPLATE = '{waba_id}/message_templates';
    const DELETE_TEMPLATE = '{waba_id}/message_templates';
    const UPDATE_TEMPLATE = '{template_id}';
    const UNPAUSE_TEMPLATE = '{template_id}/unpause';

    // Flow Endpoints
    const GET_FLOWS = '{waba_id}/flows';
    const GET_FLOW = '{flow_id}';
    const CREATE_FLOW = '{waba_id}/flows';
    const UPDATE_FLOW_METADATA = '{flow_id}';
    const UPDATE_FLOW_ASSETS = '{flow_id}/assets';
    const PUBLISH_FLOW = '{flow_id}/publish';
    const MIGRATE_FLOWS = '{waba_id}/migrate_flows';
    const LIST_FLOW_ASSETS = '{flow_id}/assets';
    const DEPRECATE_FLOW = '{flow_id}/deprecate';
    const DELETE_FLOW = '{flow_id}';

    // QR Codes Endpoints
    const GET_QR_CODES = '{phone_number_id}/message_qrdls';
    const GET_QR_CODE = '{phone_number_id}/message_qrdls/{qr_code_id}';
    const CREATE_QR_CODE = '{phone_number_id}/message_qrdls';
    const UPDATE_QR_CODE = '{phone_number_id}/message_qrdls';
    const DELETE_QR_CODE = '{phone_number_id}/message_qrdls/{qr_code_id}';

    // Helper method to build URLs with dynamic parameters
    /**
     * Construye una URL a partir de un endpoint y parámetros.
     *
     * @param string $endpoint El endpoint base.
     * @param array $params Parámetros para reemplazar en el endpoint.
     * @return string La URL generada.
     */
    public static function build(string $endpoint, array $params = []): string
    {
        $placeholders = array_map(fn($key) => "{{$key}}", array_keys($params));

        $url = str_replace(
            $placeholders,
            array_values($params),
            $endpoint
        );

        Log::channel('whatsapp')->info('URL generada:', ['url' => $url]);

        return $url;
    }

    // Helper methods for common parameters
    /**
     * Genera un array con el ID de la cuenta empresarial de WhatsApp.
     *
     * @param string $whatsappBusinessId El ID de la cuenta empresarial.
     * @return array El array con el ID de la cuenta empresarial.
     */
    public static function phoneNumber(string $phoneNumberId): array
    {
        return ['phone_number_id' => $phoneNumberId];
    }

    /**
     * Genera un array con el ID de la aplicación.
     *
     * @param string $appId El ID de la aplicación.
     * @return array El array con el ID de la aplicación.
     */
    public static function uploadId(string $uploadId): array
    {
        return ['upload_id' => $uploadId];
    }

    // Helper methods for common parameters
    /**
     * Genera un array con el ID de la cuenta empresarial de WhatsApp.
     *
     * @param string $whatsappBusinessId El ID de la cuenta empresarial.
     * @return array El array con el ID de la cuenta empresarial.
     */
    public static function businessAccount(string $whatsappBusinessId): array
    {
        return ['whatsapp_business_id' => $whatsappBusinessId];
    }
}