<?php

namespace ScriptDevelop\WhatsappManager\Services\WebhookProcessors;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ScriptDevelop\WhatsappManager\Helpers\CountryCodes;
use ScriptDevelop\WhatsappManager\Helpers\MessagingLimitHelper;
use ScriptDevelop\WhatsappManager\Contracts\WebhookProcessorInterface;
use ScriptDevelop\WhatsappManager\Support\WhatsappModelResolver;
use ScriptDevelop\WhatsappManager\Services\MessageDispatcherService;
use ScriptDevelop\WhatsappManager\Services\TemplateService;
use ScriptDevelop\WhatsappManager\Services\Flows\FlowCryptoService;
use ScriptDevelop\WhatsappManager\Events\FlowStatusUpdated;
use ScriptDevelop\WhatsappManager\Events\BusinessUsernameUpdated;
use ScriptDevelop\WhatsappManager\Events\UserIdUpdated;
use ScriptDevelop\WhatsappManager\Services\Flows\FlowMediaService;
use ScriptDevelop\WhatsappManager\Services\TemplateMediaCompressionService;
use ScriptDevelop\WhatsappManager\Jobs\CompressTemplateMediaJob;

class BaseWebhookProcessor implements WebhookProcessorInterface
{
    // Implementa todos los métodos del controlador actual aquí
    // Mueve la lógica de WhatsappWebhookController a esta clase

    public function handle(Request $request): Response|JsonResponse
    {
        $verifyToken = config('whatsapp.webhook.verify_token', env('WHATSAPP_VERIFY_TOKEN'));

        if ($request->isMethod('get') && $request->has(['hub_mode', 'hub_challenge', 'hub_verify_token'])) {
            return $this->verifyWebhook($request, $verifyToken);
        }

        if ($request->isMethod('post')) {
            // CASO ESPECIAL: Si es una petición encriptada de Flow Endpoint (Data Channel)
            if ($request->has(['encrypted_aes_key', 'encrypted_flow_data'])) {
                return $this->handleFlowEndpointRequest($request);
            }

            return $this->processIncomingMessage($request);
        }
        Log::channel('whatsapp')->error('Registro webhook invalido', [$request]);
        return response()->json(['error' => 'Invalid request method.'], 400);
    }

    public function verifyWebhook(Request $request, string $verifyToken): Response|JsonResponse
    {
        if (
            $request->input('hub_mode') === 'subscribe' &&
            $request->input('hub_verify_token') === $verifyToken
        ) {
            Log::channel('whatsapp')->info('Configuracion de webhook valido!', [$request]);
            return response()->make($request->input('hub_challenge'), 200);
        }
        Log::channel('whatsapp')->error('Error al registrar webhook!', [$request]);
        return response()->json(['error' => 'Invalid verify token.'], 403);
    }

    public function processIncomingMessage(Request $request): JsonResponse
    {
        $payload = $request->all();
        Log::channel('whatsapp')->info('Received WhatsApp Webhook Payload:', $payload);

        $change = data_get($payload, 'entry.0.changes.0');
        $value = $change['value'] ?? null;
        $field = $change['field'] ?? null;

        Log::channel('whatsapp')->info('Processing webhook field:', ['field' => $field]);

        switch ($field) {
            case 'history':
                $this->handleHistorySync($value);
                return response()->json(['success' => true]);

            case 'smb_app_state_sync':
                $this->handleSmbAppStateSync($value);
                return response()->json(['success' => true]);

            case 'smb_message_echoes':
                $this->handleSmbMessageEchoes($value);
                return response()->json(['success' => true]);

            case 'account_update':
                $this->handleAccountUpdate($value);
                return response()->json(['success' => true]);

            case 'business_capability_update':
                $this->handleBusinessCapabilityUpdate($value, $payload);
                return response()->json(['success' => true]);

            case 'flows':
                $this->handleFlowStatusAndPerformance($value);
                return response()->json(['success' => true]);
        }

        if ($field === 'phone_number_name_update') {
            $this->handlePhoneNumberNameUpdate($value);
            return response()->json(['success' => true]);
        }

        if ($field === 'phone_number_quality_update') {
            $this->handlePhoneNumberQualityUpdate($value);
            return response()->json(['success' => true]);
        }

        if ($field === 'message_template' or $field === 'message_template_status_update') {
            $this->handleTemplateEvent($payload, $field, $value);
            return response()->json(['success' => true]);
        }

        /*if( $field==='message_template_components_update' ){
            $templateId = $value['message_template_id'] ?? null;

            if ( $templateId) {
                $template = WhatsappModelResolver::template()
                    ->where('wa_template_id', $templateId)
                    ->first();
                if (!$template) {
                    Log::channel('whatsapp')->warning("Template not found: {$templateId}");

                    //Usar la API para obtener el template, esto puede suceder por que se creó ó editó la plantilla desde el portal de Whatsapp Business, esto hará que en cuanto se aprueve o rechace se emita un webhook que esta clase procesará.
                    $this->findTemplateAPI($payload);
                }
            }

            return response()->json(['success' => true]);
        }*/

        if ($field === 'user_preferences') {
            $this->handleUserPreferences($value);
            return response()->json(['success' => true]);
        }

        if ($field === 'business_username_update') {
            $this->handleBusinessUsernameUpdate($value);
            return response()->json(['success' => true]);
        }

        if ($field === 'user_id_update') {
            $this->handleUserIdUpdate($value);
            return response()->json(['success' => true]);
        }

        if (!$value) {
            Log::channel('whatsapp')->warning('No value found in webhook payload.', $payload);
            return response()->json(['error' => 'Invalid payload.'], 422);
        }

        // Si es evento de estado de plantilla
        if ($field === 'message_template_status_update' && isset($value['statuses'][0])) {
            $this->handleTemplateStatusUpdate($value['statuses'][0], $payload, $field);
        }

        // Si es mensaje normal
        if (isset($value['messages'][0])) {
            $message = $value['messages'][0];
            $messageType = $message['type'] ?? '';

            if ($messageType === 'edit') {
                $this->handleEditMessage(
                    $message,
                    $value['contacts'][0] ?? null,
                    $value['metadata'] ?? null
                );
            } elseif ($messageType === 'revoke') {
                $this->handleRevokeMessage(
                    $message,
                    $value['contacts'][0] ?? null,
                    $value['metadata'] ?? null
                );
            } elseif ($messageType === 'system') {
                // Los mensajes de sistema NO incluyen contacts, solo metadata
                $this->handleSystemMessage(
                    $message,
                    $value['metadata'] ?? null
                );
            }
            if ($messageType === 'interactive' && data_get($message, 'interactive.type') === 'nfm_reply') {
                $this->handleFlowResponseMessage($message, $value['contacts'][0] ?? null, $value['metadata'] ?? null);
                return response()->json(['success' => true]);
            } else {
                $this->handleIncomingMessage(
                    $message,
                    $value['contacts'][0] ?? null,
                    $value['metadata'] ?? null
                );
            }
        }

        // Si es status de mensaje (entregado, leído, etc.)
        elseif (isset($value['statuses'][0])) {
            $this->handleStatusUpdate($value['statuses'][0], $value['contacts'] ?? []);
        }

        return response()->json(['success' => true]);
    }

    protected function handleIncomingMessage(array $message, ?array $contact, ?array $metadata): void
    {
        $messageType = $message['type'] ?? '';

        $messageRecord = null;

        $textContent = null;

        Log::channel('whatsapp')->warning('Handle Incoming Message: ', [
            'message_type' => $messageType,
            'contact' => $contact,
            'metadata' => $metadata,
        ]);

        $contactRecord = $this->resolveContactFromWebhook($contact ?? [], $message);

        if (!$contactRecord) {
            Log::channel('whatsapp')->warning('No se pudo resolver el contacto: faltan bsuid y wa_id.', [
                'contact'      => $contact,
                'message_from' => $message['from'] ?? null,
            ]);
            return;
        }

        Log::channel('whatsapp')->info('CONTACT resuelto desde webhook.', [
            'contact_id'  => $contactRecord->contact_id,
            'bsuid'       => $contactRecord->bsuid,
            'wa_id'       => $contactRecord->wa_id,
            'username'    => $contactRecord->username,
            'was_created' => $contactRecord->wasRecentlyCreated,
        ]);

        // Actualizar el contacto con los datos más recientes
        /*if ($contactRecord->wa_id !== $contact['wa_id'] || $contactRecord->contact_name !== $contactName) {
            // Intentar actualización con Query Builder (sin Eloquent)
            Log::channel('whatsapp')->info('CONTACT Trying Query Builder update.', [
                'contact_name_value' => $contactName,
            ]);

            $contactRecord->update([
                'wa_id' => $contact['wa_id'],
                'contact_name' => $contactName,
            ]);

            // $updateResult = \DB::table('whatsapp_contacts')
            //     ->where('contact_id', $contactRecord->contact_id)
            //     ->update([
            //         'wa_id' => $contact['wa_id'],
            //         'contact_name' => $contactName,
            //         'updated_at' => now(),
            //     ]);

            // Log::channel('whatsapp')->info('CONTACT Query Builder update result.', [
            //     'rows_affected' => $updateResult,
            // ]);

            // Recargar el modelo
            $contactRecord->refresh();
        }*/

        $contactName = $contact['profile']['name'] ?? null;

        $apiPhoneNumberId = $metadata['phone_number_id'] ?? null;

        $whatsappPhone = null;
        if ($apiPhoneNumberId) {
            $whatsappPhone = WhatsappModelResolver::phone_number()->where('api_phone_number_id', $apiPhoneNumberId)->first();
        }

        // Manejar mensajes de sistema
        if (!$whatsappPhone) {
            Log::channel('whatsapp')->error('No matching WhatsappPhoneNumber found for api_phone_number_id.', [
                'api_phone_number_id' => $apiPhoneNumberId,
            ]);
            return;
        }

        $profile = WhatsappModelResolver::contact_profile()->firstOrCreate(
            [
                'phone_number_id' => $whatsappPhone->phone_number_id,
                'contact_id' => $contactRecord->contact_id,
            ],
            [
                'alias' => $contactName,
            ]
        );

        // Manejar mensajes de sistema
        if ($messageType === 'system') {
            $messageRecord = $this->processSystemMessage($message, $contactRecord, $whatsappPhone);
            $this->fireSystemMessageReceived($contactRecord, $messageRecord);
            return;
        }

        // Manejar mensajes de texto
        if ($messageType === 'text') {
            $messageRecord = $this->processTextMessage($message, $contactRecord, $whatsappPhone);

            $this->fireTextMessageReceived($contactRecord, $messageRecord);
        }

        // Manejar mensajes interactivos (botones, listas)
        if ($messageType === 'interactive') {
            $messageRecord = $this->processInteractiveMessage($message, $contactRecord, $whatsappPhone);

            $this->fireInteractiveMessageReceived($contactRecord, $messageRecord);
        }

        if ($messageType === 'location') {
            $messageRecord = $this->processLocationMessage($message, $contactRecord, $whatsappPhone);

            $this->fireLocationMessageReceived($contactRecord, $messageRecord);
        }

        if ($messageType === 'contacts') {
            $messageRecord = $this->processContactMessage($message, $contactRecord, $whatsappPhone);

            $this->fireContactMessageReceived($contactRecord, $messageRecord);
        }

        if ($messageType === 'reaction') {
            $messageRecord = $this->processReactionMessage($message, $contactRecord, $whatsappPhone);

            $this->fireReactionReceived($contactRecord, $messageRecord);
        }

        //Este sirve por ejemplo cuando se envía un mensaje de tipo template y este tiene un botón de respuesta rápida de tipo QUICK_REPLY, cuando se da click en ese botón se dispara un webhook de tipo "button" que ahora es procesado por el método processButtonMessage
        if ($messageType === 'button') {
            $messageRecord = $this->processButtonMessage($message, $contactRecord, $whatsappPhone);

            $this->fireButtonMessageReceived($contactRecord, $messageRecord);
        }

        // Manejar mensajes de media
        if (in_array($messageType, ['image', 'audio', 'video', 'document', 'sticker'])) {
            $messageRecord = $this->processMediaMessage($message, $contactRecord, $whatsappPhone);

            $this->fireMediaMessageReceived($contactRecord, $messageRecord);
        }

        // Manejar mensajes no soportados
        if ($messageType === 'unsupported') {
            $title_error = $message['errors'][0]['title'] ?? 'Unsupported message type';
            $message_error = $message['errors'][0]['message'] ?? 'This message type is not supported by the current implementation.';
            $error_details = $message['errors'][0]['error_data']['details'] ?? 'No additional details available';
            $messageRecord = $this->processUnsupportedMessage($message, $contactRecord, $whatsappPhone);
            $this->fireUnsupportedMessageReceived($contactRecord, $messageRecord, $title_error, $message_error, $error_details);
        }

        $logMessage = $textContent ?? ($message['text']['body'] ?? $message['type'] . ' content not available');

        $this->fireMessageReceived($contactRecord, $messageRecord);

        Log::channel('whatsapp')->info('Incoming message processed.', [
            'message_id'   => $message['id'],
            'contact_id'   => $contactRecord->contact_id,
            'bsuid'        => $contactRecord->bsuid,
            'wa_id'        => $contactRecord->wa_id,
            'message_type' => $messageType,
            'content'      => $logMessage,
        ]);
    }

    /**
     * Procesa un evento de edición de mensaje.
     *
     * @param array $message  El array del mensaje (contiene 'edit')
     * @param array|null $contact Información del contacto
     * @param array|null $metadata Metadatos del número de teléfono
     */
    protected function handleEditMessage(array $message, ?array $contact, ?array $metadata): void
    {
        // 1. Obtener el modelo del número de teléfono (igual)
        $apiPhoneNumberId = $metadata['phone_number_id'] ?? null;
        $whatsappPhone = null;
        if ($apiPhoneNumberId) {
            $whatsappPhone = WhatsappModelResolver::phone_number()
                ->where('api_phone_number_id', $apiPhoneNumberId)
                ->first();
        }
        if (!$whatsappPhone) {
            Log::channel('whatsapp')->error('No matching WhatsappPhoneNumber found for api_phone_number_id in edit.', [
                'api_phone_number_id' => $apiPhoneNumberId,
            ]);
            return;
        }

        // 2. Obtener o crear el contacto usando la estrategia BSUID-first
        $contactRecord = $this->resolveContactFromWebhook($contact ?? [], $message);
        if (!$contactRecord) {
            Log::channel('whatsapp')->warning('No se pudo resolver el contacto para edit.', [
                'contact' => $contact,
            ]);
            return;
        }

        // 3. Extraer datos de edición
        $editData = $message['edit'] ?? [];
        $originalMessageId = $editData['original_message_id'] ?? null;
        $newMessage = $editData['message'] ?? [];

        if (!$originalMessageId || empty($newMessage)) {
            Log::channel('whatsapp')->warning('Invalid edit payload', $message);
            return;
        }

        // 4. Buscar el mensaje original por su wa_id
        $originalMessage = WhatsappModelResolver::message()->where('wa_id', $originalMessageId)->first();
        if (!$originalMessage) {
            Log::channel('whatsapp')->warning('Original message not found for edit', ['original_wa_id' => $originalMessageId]);
            return;
        }

        // 5. Procesar el nuevo contenido (texto o multimedia) - igual que antes
        $newMessageType = $newMessage['type'] ?? '';
        $content = null;
        $mediaId = null;
        $caption = null;
        $mimeType = null;
        $sha256 = null;

        switch ($newMessageType) {
            case 'text':
                $content = $newMessage['text']['body'] ?? '';
                break;

            case 'image':
            case 'audio':
            case 'video':
            case 'document':
            case 'sticker':
                $mediaId = $newMessage[$newMessageType]['id'] ?? null;
                $caption = $newMessage[$newMessageType]['caption'] ?? strtoupper($newMessageType);
                $mimeType = $newMessage[$newMessageType]['mime_type'] ?? null;
                $sha256 = $newMessage[$newMessageType]['sha256'] ?? null;
                $content = $caption;
                break;

            case 'location':
                $location = $newMessage['location'] ?? [];
                $content = "Ubicación: " . ($location['name'] ?? '') . " - " . ($location['address'] ?? '');
                $content .= " | Lat: {$location['latitude']}, Lon: {$location['longitude']}";
                break;

            case 'contacts':
                $content = "Contactos compartidos: " . count($newMessage['contacts'] ?? []);
                break;

            case 'reaction':
                $content = $newMessage['reaction']['emoji'] ?? '';
                break;

            default:
                $content = 'Mensaje editado de tipo no soportado';
        }

        // 6. Crear el nuevo mensaje de tipo EDIT que apunta al original
        $editMessageData = [
            'wa_id'              => $message['id'],
            'whatsapp_phone_id'  => $whatsappPhone->phone_number_id,
            'contact_id'         => $contactRecord->contact_id,
            'conversation_id'    => $originalMessage->conversation_id,
            'messaging_product'  => $message['messaging_product'] ?? 'whatsapp',
            'message_from'       => isset($message['from']) ? preg_replace('/[\D+]/', '', $message['from']) : null,
            'from_bsuid'         => $message['from_user_id'] ?? null,
            'from_parent_bsuid'  => $message['from_parent_user_id'] ?? null,
            'message_to'         => preg_replace('/[\D+]/', '', $whatsappPhone->display_phone_number),
            'message_type'       => 'EDIT',
            'message_content'    => $content,
            'json_content'       => json_encode($message),
            'status'             => 'received',
            'message_context_id' => $this->getContextMessageId($newMessage),
            'original_message_id' => $originalMessage->message_id,
        ];

        $editMessageRecord = WhatsappModelResolver::message()->create($editMessageData);

        // 7. Si es multimedia, descargar y guardar (igual que antes)
        if ($mediaId && in_array($newMessageType, ['image', 'audio', 'video', 'document', 'sticker'])) {
            try {
                $mediaUrl = $this->getMediaUrl($mediaId, $whatsappPhone);
                if ($mediaUrl) {
                    $mediaContent = $this->downloadMedia($mediaUrl, $whatsappPhone);
                    if ($mediaContent) {
                        $mediaType = $newMessageType . 's';
                        $directory = config("whatsapp.media.storage_path.$mediaType");
                        if (!$directory) {
                            throw new \RuntimeException("No storage path for media type: $mediaType");
                        }
                        if (!file_exists($directory)) {
                            mkdir($directory, 0755, true);
                        }

                        $extension = $this->getFileExtension($mimeType);
                        if ($newMessageType === 'audio' && $extension === 'bin')
                            $extension = 'ogg';
                        if ($newMessageType === 'sticker' && $extension === 'bin')
                            $extension = 'webp';

                        $fileName = "{$mediaId}.{$extension}";
                        $directory = rtrim($directory, '/');
                        $filePath = "{$directory}/{$fileName}";
                        file_put_contents($filePath, $mediaContent);

                        $relativePath = str_replace(storage_path('app/public/'), '', $directory . '/' . $fileName);
                        $publicPath = Storage::url($relativePath);

                        WhatsappModelResolver::media_file()->updateOrCreate(
                            [
                                'message_id' => $editMessageRecord->message_id,
                                'media_id' => $mediaId,
                            ],
                            [
                                'media_type' => $newMessageType,
                                'file_name' => $fileName,
                                'url' => $publicPath,
                                'mime_type' => $mimeType,
                                'sha256' => $sha256,
                            ]
                        );
                    }
                }
            } catch (\Exception $e) {
                Log::channel('whatsapp')->error('Error downloading media for edit', [
                    'error' => $e->getMessage(),
                    'media_id' => $mediaId
                ]);
            }
        }

        // 8. Actualizar el mensaje original con marcas de edición
        $originalMessage->update([
            'is_edited' => true,
            'last_edit_message_id' => $editMessageRecord->message_id,
            'edited_at' => now(), // usamos el campo existente
        ]);

        // 9. Disparar evento de edición
        $this->fireMessageEdited($originalMessage, $editMessageRecord);

        Log::channel('whatsapp')->info('Edit message processed', [
            'original_message_id' => $originalMessage->message_id,
            'edit_message_id' => $editMessageRecord->message_id,
            'new_type' => $newMessageType,
        ]);
    }

    /**
     * Procesa un evento de revocación de mensaje (eliminación).
     *
     * @param array $message  El array del mensaje (contiene 'revoke')
     * @param array|null $contact Información del contacto
     * @param array|null $metadata Metadatos del número de teléfono
     */
    protected function handleRevokeMessage(array $message, ?array $contact, ?array $metadata): void
    {
        // 1. Obtener el modelo del número de teléfono
        $apiPhoneNumberId = $metadata['phone_number_id'] ?? null;
        $whatsappPhone = null;
        if ($apiPhoneNumberId) {
            $whatsappPhone = WhatsappModelResolver::phone_number()
                ->where('api_phone_number_id', $apiPhoneNumberId)
                ->first();
        }
        if (!$whatsappPhone) {
            Log::channel('whatsapp')->error('No matching WhatsappPhoneNumber found for api_phone_number_id in revoke.', [
                'api_phone_number_id' => $apiPhoneNumberId,
            ]);
            return;
        }

        // 2. Obtener o crear el contacto usando la estrategia BSUID-first
        $contactRecord = $this->resolveContactFromWebhook($contact ?? [], $message);
        if (!$contactRecord) {
            Log::channel('whatsapp')->warning('No se pudo resolver el contacto para revoke.', [
                'contact' => $contact,
            ]);
            return;
        }

        // 3. Extraer el ID del mensaje original
        $revokeData = $message['revoke'] ?? [];
        $originalMessageId = $revokeData['original_message_id'] ?? null;

        if (!$originalMessageId) {
            Log::channel('whatsapp')->warning('Invalid revoke payload - missing original_message_id', $message);
            return;
        }

        // 4. Buscar el mensaje original en la base de datos
        $originalMessage = WhatsappModelResolver::message()->where('wa_id', $originalMessageId)->first();
        if (!$originalMessage) {
            Log::channel('whatsapp')->warning('Original message not found for revoke', ['original_wa_id' => $originalMessageId]);
            return;
        }

        // 5. Marcar el mensaje original como revocado
        $originalMessage->update([
            'is_revoked' => true,
            'revoked_at' => now(),
        ]);

        // 6. (Opcional) Crear un mensaje de tipo REVOKE para registrar el evento
        $revokeMessageData = [
            'wa_id'             => $message['id'],
            'whatsapp_phone_id' => $whatsappPhone->phone_number_id,
            'contact_id'        => $contactRecord->contact_id,
            'conversation_id'   => $originalMessage->conversation_id,
            'messaging_product' => $message['messaging_product'] ?? 'whatsapp',
            'message_from'      => isset($message['from']) ? preg_replace('/[\D+]/', '', $message['from']) : null,
            'from_bsuid'        => $message['from_user_id'] ?? null,
            'from_parent_bsuid' => $message['from_parent_user_id'] ?? null,
            'message_to'        => preg_replace('/[\D+]/', '', $whatsappPhone->display_phone_number),
            'message_type'      => 'REVOKE',
            'message_content'   => 'Mensaje revocado',
            'json_content' => json_encode($message),
            'status' => 'received',
            'original_message_id' => $originalMessage->message_id, // referencia al original
        ];

        $revokeMessageRecord = WhatsappModelResolver::message()->create($revokeMessageData);

        // 7. Disparar evento de revocación
        $this->fireMessageRevoked($originalMessage, $revokeMessageRecord);

        Log::channel('whatsapp')->info('Revoke message processed', [
            'original_message_id' => $originalMessage->message_id,
            'revoke_message_id' => $revokeMessageRecord->message_id,
        ]);
    }

    /**
     * Procesa un mensaje de sistema (ej: cambio de número).
     *
     * @param array $message  El array del mensaje (contiene 'system')
     * @param array|null $metadata Metadatos del número de teléfono
     */
    protected function handleSystemMessage(array $message, ?array $metadata): void
    {
        // 1. Obtener el modelo del número de teléfono del negocio
        $apiPhoneNumberId = $metadata['phone_number_id'] ?? null;
        $whatsappPhone = null;
        if ($apiPhoneNumberId) {
            $whatsappPhone = WhatsappModelResolver::phone_number()
                ->where('api_phone_number_id', $apiPhoneNumberId)
                ->first();
        }
        if (!$whatsappPhone) {
            Log::channel('whatsapp')->error('No matching WhatsappPhoneNumber found for api_phone_number_id in system message.', [
                'api_phone_number_id' => $apiPhoneNumberId,
            ]);
            return;
        }

        // 2. Extraer datos del mensaje
        $from       = $message['from'] ?? null; // número antiguo (puede estar ausente con usernames)
        $system     = $message['system'] ?? [];
        $systemType = $system['type'] ?? '';
        $newWaId    = $system['wa_id'] ?? null;        // nuevo wa_id tras cambio de número
        $newBsuid   = $system['user_id'] ?? null;      // nuevo BSUID (user_changed_user_id)
        $newParentBsuid = $system['parent_user_id'] ?? null;
        $body       = $system['body'] ?? '';

        // 3. Intentar extraer el nombre del perfil desde el body
        $contactName = null;
        if (in_array($systemType, ['user_changed_number', 'user_changed_user_id'])) {
            if (preg_match('/User (.*?) changed from/', $body, $matches)) {
                $contactName = $matches[1];
            }
        }

        // 4. Buscar el contacto: primero por BSUID antiguo, luego por wa_id/teléfono
        $contactRecord = null;

        // user_changed_user_id: el BSUID cambió porque el usuario cambió de número
        // El `from` contiene el número antiguo, y system.user_id contiene el NUEVO bsuid
        // Intentamos encontrar el contacto por el número antiguo
        if ($from) {
            $fullPhone = preg_replace('/\D/', '', $from);
            [$countryCode, $phoneNumber] = $this->splitPhoneNumber($fullPhone);

            if ($countryCode && $phoneNumber) {
                $contactRecord = WhatsappModelResolver::contact()
                    ->where('wa_id', $from)
                    ->orWhere(function ($query) use ($countryCode, $phoneNumber) {
                        $query->where('country_code', $countryCode)
                              ->where('phone_number', $phoneNumber);
                    })
                    ->first();
            }
        }

        if (!$contactRecord) {
            // Crear nuevo contacto con los datos disponibles
            $createData = ['contact_name' => $contactName];
            if ($from) {
                $fullPhone = preg_replace('/\D/', '', $from);
                [$cc, $pn] = $this->splitPhoneNumber($fullPhone);
                $createData['wa_id']        = $from;
                $createData['country_code'] = $cc;
                $createData['phone_number'] = $pn;
            }
            $contactRecord = WhatsappModelResolver::contact()->create($createData);
            Log::channel('whatsapp')->info('Contacto creado desde system message', [
                'contact_id' => $contactRecord->contact_id,
            ]);
        }

        // 6. Llamar al procesamiento existente de mensajes de sistema
        //    Este método ya maneja 'user_changed_number' y actualiza el contacto con el nuevo wa_id
        $this->processSystemMessage($message, $contactRecord, $whatsappPhone);
    }

    private function getMessageContentForType(string $messageType, array $message): ?string
    {
        switch ($messageType) {
            case 'text':
                return $message['text']['body'] ?? null;
            case 'interactive':
                return $message['interactive']['button_reply']['title']
                    ?? $message['interactive']['list_reply']['title']
                    ?? null;
            case 'location':
                return "Location: " . ($message['location']['name'] ?? '');
            case 'contacts':
                return "Shared contacts: " . count($message['contacts'] ?? []);
            case 'reaction':
                return $message['reaction']['emoji'] ?? null;
            default:
                return $message[$messageType]['caption'] ?? strtoupper($messageType);
        }
    }

    protected function processTextMessage(array $message, Model $contact, Model $whatsappPhone): ?Model
    {
        $textContent = $message['text']['body'] ?? null;

        Log::channel('whatsapp')->info('Processing text message.', [
            'message' => $message,
            'contact' => $contact,
            'whatsappPhone' => $whatsappPhone,
            'textContent' => $textContent,
        ]);

        if (!$textContent) {
            Log::channel('whatsapp')->warning('No text content found in message.', $message);
            return null;
        }

        $messageRecord = WhatsappModelResolver::message()->firstOrCreate(
            [
                'wa_id' => $message['id'],
            ],
            [
                'whatsapp_phone_id' => $whatsappPhone->phone_number_id,
                'contact_id' => $contact->contact_id,
                'conversation_id' => null, // Esto se puede actualizar más tarde si es necesario
                'messaging_product' => $message['messaging_product'] ?? 'whatsapp',
                'message_from'      => isset($message['from']) ? preg_replace('/[\D+]/', '', $message['from']) : null,
                'from_bsuid'        => $message['from_user_id'] ?? null,
                'from_parent_bsuid' => $message['from_parent_user_id'] ?? null,
                'message_to'        => preg_replace('/[\D+]/', '', $whatsappPhone->display_phone_number),
                'message_type'      => 'TEXT',
                'message_content' => $textContent,
                'json_content' => json_encode($message),
                'status' => 'received',
                'message_context_id' => $this->getContextMessageId($message),
            ]
        );

        Log::channel('whatsapp')->info('Text message processed and saved.', [
            'message_id' => $messageRecord->message_id,
            'wa_id' => $message['id'],
            'content' => $textContent,
        ]);

        return $messageRecord;
    }

    protected function processButtonMessage(array $message, Model $contact, Model $whatsappPhone): ?Model
    {
        $textContent = $message['button']['text'] ?? $message['button']['payload'] ?? null;

        Log::channel('whatsapp')->info('Processing button message.', [
            'message' => $message,
            'contact' => $contact,
            'whatsappPhone' => $whatsappPhone,
            'textContent' => $textContent,
        ]);

        if (!$textContent) {
            Log::channel('whatsapp')->warning('No text content found in message.', $message);
            return null;
        }

        $messageRecord = WhatsappModelResolver::message()->firstOrCreate(
            [
                'wa_id' => $message['id'],
            ],
            [
                'whatsapp_phone_id' => $whatsappPhone->phone_number_id,
                'contact_id' => $contact->contact_id,
                'conversation_id' => null, // Esto se puede actualizar más tarde si es necesario
                'messaging_product' => $message['messaging_product'] ?? 'whatsapp',
                'message_from'      => isset($message['from']) ? preg_replace('/[\D+]/', '', $message['from']) : null,
                'from_bsuid'        => $message['from_user_id'] ?? null,
                'from_parent_bsuid' => $message['from_parent_user_id'] ?? null,
                'message_to'        => preg_replace('/[\D+]/', '', $whatsappPhone->display_phone_number),
                'message_type'      => 'BUTTON',
                'message_content' => $textContent,
                'json_content' => json_encode($message),
                'status' => 'received',
                'message_context_id' => $this->getContextMessageId($message),
            ]
        );

        Log::channel('whatsapp')->info('Text message processed and saved.', [
            'message_id' => $messageRecord->message_id,
            'wa_id' => $message['id'],
            'content' => $textContent,
        ]);

        return $messageRecord;
    }

    protected function processInteractiveMessage(array $message, Model $contact, Model $whatsappPhone): ?Model
    {
        $interactiveType = $message['interactive']['type'] ?? '';
        $textContent = null;

        if ($interactiveType === 'button_reply') {
            $textContent = $message['interactive']['button_reply']['title'] ?? null;
        } else if ($interactiveType === 'list_reply') {
            $textContent = $message['interactive']['list_reply']['title'] ?? null;
        }

        if (!$textContent) {
            Log::channel('whatsapp')->warning('No se pudo extraer contenido del mensaje interactivo.', $message);
            return null;
        }

        // Guardar el mensaje en la base de datos como tipo INTERACTIVE
        $messageRecord = WhatsappModelResolver::message()->firstOrCreate(
            [
                'wa_id' => $message['id'],
            ],
            [
                'whatsapp_phone_id' => $whatsappPhone->phone_number_id,
                'contact_id' => $contact->contact_id,
                'conversation_id' => null,
                'messaging_product' => $message['messaging_product'] ?? 'whatsapp',
                'message_from'      => isset($message['from']) ? preg_replace('/[\D+]/', '', $message['from']) : null,
                'from_bsuid'        => $message['from_user_id'] ?? null,
                'from_parent_bsuid' => $message['from_parent_user_id'] ?? null,
                'message_to' => preg_replace('/[\D+]/', '', $whatsappPhone->display_phone_number),
                'message_type' => strtoupper($message['type']),
                'message_content' => $textContent,
                'json_content' => json_encode($message),
                'status' => 'received',
                'message_context_id' => $this->getContextMessageId($message),
            ]
        );

        Log::channel('whatsapp')->info('Mensaje interactivo procesado y guardado.', [
            'message_id' => $messageRecord->message_id,
            'wa_id' => $message['id'],
            'content' => $textContent,
        ]);

        return $messageRecord;
    }

    protected function processMediaMessage(array $message, Model $contact, Model $whatsappPhone): Model
    {
        $mediaId = $message[$message['type']]['id'] ?? null;
        $caption = $message[$message['type']]['caption'] ?? strtoupper($message['type']);
        $mimeType = $message[$message['type']]['mime_type'] ?? null;

        Log::channel('whatsapp')->info('Processing media message.', [
            'message' => $message,
            'contact' => $contact,
            'whatsappPhone' => $whatsappPhone,
            'mediaId' => $mediaId,
            'caption' => $caption,
            'mimeType' => $mimeType,
        ]);

        if (!$mediaId) {
            Log::channel('whatsapp')->warning('No media ID found in message.', $message);
            throw new \RuntimeException('No media ID found in message.');
        }

        $mediaUrl = $this->getMediaUrl($mediaId, $whatsappPhone);

        if (!$mediaUrl) {
            Log::channel('whatsapp')->error('Failed to retrieve media URL.', ['media_id' => $mediaId]);
            throw new \RuntimeException('Failed to retrieve media URL.');
        }

        $mediaContent = $this->downloadMedia($mediaUrl, $whatsappPhone);

        if (!$mediaContent) {
            Log::channel('whatsapp')->error('Failed to download media content.', ['media_url' => $mediaUrl]);
            throw new \RuntimeException('Failed to download media content.');
        }

        // Obtener el tipo de media pluralizado según la configuración
        $mediaType = $message['type'] . 's'; // Por defecto pluralizar el tipo de media

        // Obtener la ruta de almacenamiento configurada desde la config
        $directory = config("whatsapp.media.storage_path.$mediaType");

        if (!$directory) {
            throw new \RuntimeException("No se ha configurado una ruta de almacenamiento para el tipo de media: $mediaType");
        }

        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        $extension = $this->getFileExtension($mimeType);

        // Si es un archivo de audio y tiene extensión .bin, forzar a .ogg
        if ($message['type'] === 'audio' && $extension === 'bin') {
            $extension = 'ogg';
        }

        if ($message['type'] === 'sticker' && $extension === 'bin') {
            $extension = 'webp';
        }

        $fileName = "{$mediaId}.{$extension}";
        if (Str::endsWith($directory, '/')) {
            $directory = rtrim($directory, '/');
        }
        $filePath = "{$directory}/{$fileName}";
        file_put_contents($filePath, $mediaContent);

        // Convertir el path absoluto a relativo para Storage::url
        $relativePath = str_replace(storage_path('app/public/'), '', $directory . '/' . $fileName);

        // Obtener la URL pública
        $publicPath = Storage::url($relativePath);

        // Crear el registro del mensaje en la base de datos
        $messageRecord = WhatsappModelResolver::message()->firstOrCreate(
            [
                'wa_id' => $message['id'],
            ],
            [
                'whatsapp_phone_id' => $whatsappPhone->phone_number_id,
                'contact_id' => $contact->contact_id,
                'conversation_id' => null, // Esto se puede actualizar más tarde si es necesario
                'messaging_product' => $message['messaging_product'] ?? 'whatsapp',
                'message_from'      => isset($message['from']) ? preg_replace('/[\D+]/', '', $message['from']) : null,
                'from_bsuid'        => $message['from_user_id'] ?? null,
                'from_parent_bsuid' => $message['from_parent_user_id'] ?? null,
                'message_to' => preg_replace('/[\D+]/', '', $whatsappPhone->display_phone_number),
                'message_type' => strtoupper($message['type']),
                'message_content' => $caption,
                'caption' => $caption,
                'json_content' => json_encode($message),
                'status' => 'received',
                'message_context_id' => $this->getContextMessageId($message),
            ]
        );

        // Actualizar ó Crear el registro del archivo multimedia en la base de datos
        WhatsappModelResolver::media_file()->updateOrCreate(
            [
                'message_id' => $messageRecord->message_id,
                'media_id' => $mediaId,
            ],
            [
                'media_type' => $message['type'],
                'file_name' => $fileName,
                'url' => $publicPath,
                'mime_type' => $mimeType,
                'sha256' => $message[$message['type']]['sha256'] ?? null,
            ]
        );

        Log::channel('whatsapp')->info('Media file and message saved.', [
            'message_id' => $messageRecord->message_id,
            'file_path' => $filePath,
            'public_url' => $publicPath,
        ]);

        //Cargar los datos de archivos multimedia, para que también se transmita en los eventos correspondientes
        $messageRecord->loadMissing([
            'mediaFiles',
        ]);

        return $messageRecord;
    }

    protected function processLocationMessage(array $message, Model $contact, Model $whatsappPhone): ?Model
    {
        $location = $message['location'] ?? null;

        if (!$location) {
            Log::channel('whatsapp')->warning('No location data found in message.', $message);
            return null;
        }

        $content = "Ubicación: " . ($location['name'] ?? '') . " - " . ($location['address'] ?? '');
        $coordinates = "Lat: {$location['latitude']}, Lon: {$location['longitude']}";

        $messageRecord = WhatsappModelResolver::message()->firstOrCreate(
            [
                'wa_id' => $message['id'],
            ],
            [
                'whatsapp_phone_id' => $whatsappPhone->phone_number_id,
                'contact_id' => $contact->contact_id,
                'conversation_id' => null,
                'messaging_product' => $message['messaging_product'] ?? 'whatsapp',
                'message_from'      => isset($message['from']) ? preg_replace('/[\D+]/', '', $message['from']) : null,
                'from_bsuid'        => $message['from_user_id'] ?? null,
                'from_parent_bsuid' => $message['from_parent_user_id'] ?? null,
                'message_to' => preg_replace('/[\D+]/', '', $whatsappPhone->display_phone_number),
                'message_type' => 'LOCATION',
                'message_content' => $content . ' | ' . $coordinates,
                'json_content' => json_encode($message),
                'status' => 'received',
                'message_context_id' => $this->getContextMessageId($message),
            ]
        );

        Log::channel('whatsapp')->info('Location message processed.', [
            'message_id' => $messageRecord->message_id,
            'location' => $location
        ]);

        return $messageRecord;
    }

    protected function processContactMessage(array $message, Model $contact, Model $whatsappPhone): ?Model
    {
        $contactData = $message['contacts'][0] ?? null;

        if (!$contactData) {
            Log::channel('whatsapp')->warning('No contact data found in message.', $message);
            return null;
        }

        $name = $contactData['name']['formatted_name'] ?? 'Nombre no disponible';
        $phones = collect($contactData['phones'] ?? [])->pluck('phone')->implode(', ');
        $emails = collect($contactData['emails'] ?? [])->pluck('email')->implode(', ');

        $content = "Nombre: {$name} | Teléfonos: {$phones} | Correos: {$emails}";

        $messageRecord = WhatsappModelResolver::message()->firstOrCreate(
            [
                'wa_id' => $message['id'],
            ],
            [
                'whatsapp_phone_id' => $whatsappPhone->phone_number_id,
                'contact_id' => $contact->contact_id,
                'conversation_id' => null,
                'messaging_product' => $message['messaging_product'] ?? 'whatsapp',
                'message_from'      => isset($message['from']) ? preg_replace('/[\D+]/', '', $message['from']) : null,
                'from_bsuid'        => $message['from_user_id'] ?? null,
                'from_parent_bsuid' => $message['from_parent_user_id'] ?? null,
                'message_to' => preg_replace('/[\D+]/', '', $whatsappPhone->display_phone_number),
                'message_type' => 'CONTACT',
                'message_content' => $content,
                'json_content' => json_encode($message),
                'status' => 'received',
                'message_context_id' => $this->getContextMessageId($message),
            ]
        );

        Log::channel('whatsapp')->info('Contact shared message processed.', [
            'message_id' => $messageRecord->message_id,
            'contact' => $content
        ]);

        return $messageRecord;
    }

    protected function processReactionMessage(array $message, Model $contact, Model $whatsappPhone): ?Model
    {
        $reaction = $message['reaction'] ?? null;

        if (!$reaction || !isset($reaction['emoji'], $reaction['message_id'])) {
            Log::channel('whatsapp')->warning('Reacción inválida o incompleta.', $message);
            return null;
        }

        // Opcional: guardar la reacción asociada al mensaje original
        $originalMessage = WhatsappModelResolver::message()->select('message_id')->where('wa_id', $reaction['message_id'])->first();

        $messageRecord = WhatsappModelResolver::message()->firstOrCreate(
            [
                'wa_id' => $message['id'],
            ],
            [
                'whatsapp_phone_id' => $whatsappPhone->phone_number_id,
                'contact_id' => $contact->contact_id,
                //'conversation_id' => $conversation->conversation_id ?? null, //Nota 2026-03-05: Por lo pronto no se usará este campo para las reacciones, ya que no es claro si se debe asociar a la conversación del mensaje original o no.
                'messaging_product' => $message['messaging_product'] ?? 'whatsapp',
                'message_from'      => isset($message['from']) ? preg_replace('/[\D+]/', '', $message['from']) : null,
                'from_bsuid'        => $message['from_user_id'] ?? null,
                'from_parent_bsuid' => $message['from_parent_user_id'] ?? null,
                'message_to' => preg_replace('/[\D+]/', '', $whatsappPhone->display_phone_number),
                'message_type' => 'REACTION',
                'message_content' => $reaction['emoji'],
                'json_content' => json_encode($message),
                'status' => 'received',
                'message_context_id' => $originalMessage?->message_id ?? null,
            ]
        );

        Log::channel('whatsapp')->info('Reacción procesada.', [
            'message_id' => $messageRecord->message_id,
            'reaction' => $reaction
        ]);

        return $messageRecord;
    }

    /**
     * Si un mensaje es una respuesta a otro mensaje, se busca el message_id del mensaje
     * @param array $message
     * @return string|null
     */
    protected function getContextMessageId(array $message): ?string
    {
        if (isset($message['context']) and isset($message['context']['id'])) {
            // Si el mensaje tiene contexto, buscar ese id en la base de datos
            $context_message = WhatsappModelResolver::message()
                ->select('message_id')
                ->where('wa_id', '=', $message['context']['id'])
                ->first();

            if ($context_message) {
                return $context_message->message_id;
            }
        }
        return null;
    }

    private function getMediaUrl(string $mediaId, Model $whatsappPhone): ?string
    {
        $url = config('whatsapp.api.base_url', env('WHATSAPP_API_URL')) . '/' . config('whatsapp.api.version', env('WHATSAPP_API_VERSION')) . "/$mediaId?phone_number_id=" . $whatsappPhone->api_phone_number_id;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $whatsappPhone->businessAccount->api_token,
        ])->get($url);

        return $response->json()['url'] ?? null;
    }

    private function downloadMedia(string $url, Model $whatsappPhone): ?string
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $whatsappPhone->businessAccount->api_token,
        ])->get($url);

        return $response->successful() ? $response->body() : null;
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
     * @param array $status   El objeto de estado del webhook (statuses[0])
     * @param array $contacts Array opcional de contacts del webhook (presente en delivered/read)
     */
    protected function handleStatusUpdate(array $status, array $contacts = []): void
    {
        $messageId   = $status['id'] ?? null;
        $statusValue = $status['status'] ?? null;

        if (empty($messageId) || empty($statusValue)) {
            Log::channel('whatsapp')->warning('Missing message ID or status in status update.', $status);
            return;
        }

        $messageRecord = WhatsappModelResolver::message()->where('wa_id', $messageId)->first();

        if (!$messageRecord) {
            Log::channel('whatsapp')->warning('Message record not found for status update.', ['wa_id' => $messageId]);
            return;
        }

        // 1. Actualizar estado del mensaje (incluye recipient_bsuid si llega en el status)
        $messageUpdated = $this->updateMessageStatus($messageRecord, $status);

        // 2. Si llegan contacts en el webhook (sent/delivered/read), actualizar BSUID del contacto
        if (!empty($contacts) && in_array($statusValue, ['sent', 'delivered', 'read'])) {
            foreach ($contacts as $contactData) {
                $this->updateContactBsuidFromStatusWebhook($contactData);
            }
        }

        switch ($statusValue) {
            case 'delivered':
                $this->fireMessageDelivered($messageUpdated);
                break;

            case 'read':
                $this->fireMessageRead($messageUpdated);
                break;

            case 'failed':
                $errorCode = $status['errors'][0]['code'] ?? null;

                if ($errorCode == 131050) {
                    $this->fireMarketingOptOut($messageUpdated);
                } else {
                    if ($errorCode == 131042) {
                        $this->markPaymentIssueOnAccount($messageUpdated);
                    }
                    $this->fireMessageFailed($messageUpdated);
                }
                break;
        }

        // 3. Procesar datos de conversación y métricas
        if (isset($status['conversation'])) {
            $this->processConversationData($messageRecord, $status);
        }

        Log::channel('whatsapp')->info('Estado actualizado', [
            'message_id'      => $messageRecord->message_id,
            'wa_id'           => $messageId,
            'status'          => $statusValue,
            'recipient_bsuid' => $status['recipient_user_id'] ?? null,
            'conversation'    => $messageRecord->conversation_id,
        ]);
    }

    /**
     * Actualiza el BSUID de un contacto a partir del array contacts[] del webhook de status.
     * Solo se incluye en webhooks sent/delivered/read — no en failed.
     */
    private function updateContactBsuidFromStatusWebhook(array $contactData): void
    {
        $bsuid       = $contactData['user_id'] ?? null;
        $waId        = $contactData['wa_id'] ?? null;
        $parentBsuid = $contactData['parent_user_id'] ?? null;
        $username    = $contactData['profile']['username'] ?? null;

        if (!$bsuid && !$waId) {
            return;
        }

        $contact = null;
        if ($bsuid) {
            $contact = WhatsappModelResolver::contact()->where('bsuid', $bsuid)->first();
        }
        if (!$contact && $waId) {
            $contact = WhatsappModelResolver::contact()->where('wa_id', $waId)->first();
        }

        if ($contact) {
            $updateData = array_filter([
                'bsuid'        => $bsuid,
                'parent_bsuid' => $parentBsuid,
                'username'     => $username,
            ], fn($v) => $v !== null);

            if (!empty($updateData)) {
                $contact->update($updateData);
            }
        }
    }

    /**
     * Marca la cuenta empresarial asociada al mensaje con la fecha en que se detectó
     * un problema de método de pago (error 131042).
     *
     * Solo actualiza si aún no estaba marcada para evitar escrituras innecesarias.
     */
    private function markPaymentIssueOnAccount(Model $message): void
    {
        try {
            $phoneNumber = $message->phoneNumber;
            if (!$phoneNumber) {
                return;
            }

            $account = $phoneNumber->businessAccount;
            if (!$account) {
                return;
            }

            if ($account->payment_issue_detected_at === null) {
                $account->payment_issue_detected_at = now();
                $account->save();

                Log::channel('whatsapp')->warning('Problema de método de pago detectado en cuenta.', [
                    'whatsapp_business_id' => $account->whatsapp_business_id,
                    'detected_at'          => $account->payment_issue_detected_at,
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('Error al registrar payment_issue en cuenta.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resuelve o crea el contacto a partir del payload de un webhook entrante.
     *
     * Estrategia de búsqueda (BSUID-first para compatibilidad con nombres de usuario):
     *   1. Si hay BSUID → buscar por bsuid (identificador primario desde el 31/03/2026)
     *   2. Si no se encontró → buscar por wa_id (fallback para contactos pre-BSUID)
     *   3. Si no se encontró → crear nuevo contacto con los datos disponibles
     *
     * Retorna null solo si no hay ningún identificador disponible (bsuid, wa_id ni from).
     */
    protected function resolveContactFromWebhook(array $contact, array $message): ?Model
    {
        $bsuid       = $contact['user_id'] ?? null;
        $waId        = $contact['wa_id'] ?? null;
        $parentBsuid = $contact['parent_user_id'] ?? null;
        $username    = $contact['profile']['username'] ?? null;
        $contactName = $contact['profile']['name'] ?? null;
        $from        = $message['from'] ?? null;

        // Resolver número de teléfono si está disponible
        $countryCode = null;
        $phoneNumber = null;
        $fullPhone   = $from ?? $waId;
        if ($fullPhone) {
            $cleaned = preg_replace('/\D/', '', $fullPhone);
            if ($cleaned) {
                [$countryCode, $phoneNumber] = $this->splitPhoneNumber($cleaned);
            }
        }

        // Si no hay ningún identificador, no podemos proceder
        if (!$bsuid && !$waId && !$from) {
            return null;
        }

        // Campos a actualizar/crear
        $fillData = array_filter([
            'contact_name' => $contactName,
            'username'     => $username,
            'parent_bsuid' => $parentBsuid,
            'bsuid'        => $bsuid,
        ], fn($v) => $v !== null);

        if ($waId)        $fillData['wa_id']        = $waId;
        if ($countryCode) $fillData['country_code'] = $countryCode;
        if ($phoneNumber) $fillData['phone_number']  = $phoneNumber;

        // 1. Buscar por BSUID (identificador estable, siempre presente desde el 31/03/2026)
        $contactRecord = null;
        if ($bsuid) {
            $contactRecord = WhatsappModelResolver::contact()
                ->where('bsuid', $bsuid)
                ->first();
        }

        // 2. Fallback: buscar por wa_id (contactos existentes sin BSUID aún)
        if (!$contactRecord && $waId) {
            $contactRecord = WhatsappModelResolver::contact()
                ->where('wa_id', $waId)
                ->first();
        }

        if ($contactRecord) {
            $contactRecord->update($fillData);
            $contactRecord->refresh();
            return $contactRecord;
        }

        // 3. Crear nuevo contacto
        if (!isset($fillData['country_code'])) $fillData['country_code'] = null;
        if (!isset($fillData['phone_number']))  $fillData['phone_number']  = null;

        return WhatsappModelResolver::contact()->create($fillData);
    }

    private function splitPhoneNumber(string $fullPhone): array
    {
        $codes = CountryCodes::codes();

        usort($codes, static fn($a, $b) => strlen($b) <=> strlen($a));

        foreach ($codes as $code) {
            if (str_starts_with($fullPhone, $code)) {
                $phoneNumber = substr($fullPhone, strlen($code));

                $phoneNumber = CountryCodes::normalizeInternationalPhone($code, $phoneNumber)['phoneNumber'];
                return [$code, $phoneNumber];
            }
        }

        return [null, null];
    }

    protected function updateMessageStatus(Model $message, array $status): Model
    {
        $statusValue = $status['status'] ?? null;
        $timestamp   = $status['timestamp'] ?? null;
        $errorCode   = null;

        if( !empty($message->delivered_at) ){
            $statusValue = 'delivered';
        }
        if( !empty($message->read_at) ){
            $statusValue = 'read';
        }
        if( !empty($message->failed_at) ){
            $statusValue = 'failed';
        }

        $updateData  = ['status' => $statusValue];

        // Guardar BSUID del destinatario si llega en el status (presente en delivered/read cuando se envió por BSUID)
        if (!empty($status['recipient_user_id'])) {
            $updateData['recipient_bsuid'] = $status['recipient_user_id'];
        }
        if (!empty($status['parent_recipient_user_id'])) {
            $updateData['parent_recipient_bsuid'] = $status['parent_recipient_user_id'];
        }

        if ($timestamp) {
            $date = \Carbon\Carbon::createFromTimestamp($timestamp);

            match ($statusValue) {
                'delivered' => $updateData['delivered_at'] = $date,
                'read' => $updateData['read_at'] = $date,
                'failed' => $updateData['failed_at'] = $date,
                default => null
            };
        }

        // Procesar errores de forma robusta
        if ($statusValue == 'failed' && isset($status['errors']) && !empty($status['errors'])) {
            $firstError = $status['errors'][0];

            if (is_string($firstError)) {
                // Caso: error en forma de string simple
                $updateData['message_error'] = $firstError;
                $updateData['code_error'] = null;
                $updateData['title_error'] = 'Normalization error';
                $updateData['details_error'] = $firstError;
            } elseif (is_array($firstError)) {
                // Caso: error con estructura (code, title, message, error_data)
                $updateData['code_error'] = (int) ($firstError['code'] ?? 0);
                $updateData['title_error'] = $firstError['title'] ?? 'Unknown error';
                $updateData['message_error'] = $firstError['message'] ?? '';

                if (isset($firstError['error_data'])) {
                    $updateData['details_error'] = is_array($firstError['error_data'])
                        ? ($firstError['error_data']['details'] ?? json_encode($firstError['error_data']))
                        : (string) $firstError['error_data'];
                }

                // Manejar código específico de marketing opt-out
                if (isset($firstError['code']) && $firstError['code'] == 131050) {
                    $updateData['is_marketing_opt_out'] = true;
                    $this->updateContactMarketingPreference($message->contact_id, false);
                }
            }
        }

        $message->update($updateData);
        return $message;
    }

    private function processConversationData(Model $message, array $status): void
    {
        $conversationData = $status['conversation'];
        $pricingData = $status['pricing'] ?? [];

        // Validar expiration_timestamp
        $expirationTimestamp = null;
        if (
            isset($conversationData['expiration_timestamp'])
            && is_numeric($conversationData['expiration_timestamp'])
        ) {
            $expirationTimestamp = \Carbon\Carbon::createFromTimestamp(
                $conversationData['expiration_timestamp']
            );
        }

        // Validar ID de conversación
        if (empty($conversationData['id'])) {
            Log::channel('whatsapp')->warning('Conversation ID inválido', $conversationData);
            return;
        }

        $conversation = WhatsappModelResolver::conversation()->updateOrCreate(
            ['wa_conversation_id' => $conversationData['id']],
            [
                'expiration_timestamp' => $expirationTimestamp,
                'origin' => $conversationData['origin']['type'] ?? 'unknown',
                'pricing_model' => $pricingData['pricing_model'] ?? null,
                'billable' => $pricingData['billable'] ?? false,
                'category' => $pricingData['category'] ?? 'service'
            ]
        );

        // Vincular conversación al mensaje si no está asignada
        if (!$message->conversation_id) {
            $message->conversation()->associate($conversation);
            $message->save();
        }
    }

    private function validateResponse(string $userInput, ?array $validationConfig): \Illuminate\Contracts\Validation\Validator
    {
        // Si no hay configuración de validación, retornar un validador vacío
        if (!$validationConfig || empty($validationConfig['rules'])) {
            return \Illuminate\Support\Facades\Validator::make(
                ['input' => $userInput],
                []
            );
        }

        // Crear el array de datos para validación usando un nombre de campo genérico
        $data = ['input' => $userInput];

        // Obtener las reglas de validación
        $rules = ['input' => $validationConfig['rules']];

        // Crear mensajes personalizados si existen
        $customMessages = [];
        if (isset($validationConfig['retryMessage'])) {
            $customMessages = [
                'input.required' => $validationConfig['retryMessage'],
                'input.in' => $validationConfig['retryMessage'],
                'input.*' => $validationConfig['retryMessage']  // Mensaje genérico para todas las reglas
            ];
        }

        // Crear y retornar el validador
        return \Illuminate\Support\Facades\Validator::make($data, $rules, $customMessages);
    }

    private function getMessageContent($message): string
    {
        if ($message->message_type === 'text') {
            return $message->content;
        }

        // Para mensajes interactivos, usa el cuerpo principal
        $data = json_decode($message->content, true);
        return $data['body'] ?? 'Mensaje no disponible';
    }

    private function sendInteractiveResponse($message, Model $contact, Model $phoneNumber): void
    {
        $service = app(MessageDispatcherService::class);
        $data = json_decode($message->content, true);

        if ($message->message_type === 'interactive_buttons') {
            // $service->sendButtonMessage(
            @$service->sendInteractiveButtonsMessage(
                $phoneNumber->phone_number_id,
                $contact->country_code,
                $contact->phone_number,
                $data['body'],
                $data['buttons'],
                $data['footer'] ?? null
            );
        } elseif ($message->message_type === 'interactive_list') {
            $service->sendListMessage(
                $phoneNumber->phone_number_id,
                $contact->country_code,
                $contact->phone_number,
                $data['body'],
                $data['sections'],
                $data['button'] ?? 'Ver opciones',
                $data['footer'] ?? null
            );
        }
    }

    protected function processUnsupportedMessage(array $message, Model $contact, Model $whatsappPhone): ?Model
    {
        $firstError = $message['errors'][0] ?? null;

        if (is_string($firstError)) {
            $errorCode = null;
            $errorTitle = 'Unsupported content';
            $errorDetails = $firstError;
            $content = "Unsupported message. Error: $errorDetails";
        } elseif (is_array($firstError)) {
            $errorCode = $firstError['code'] ?? null;
            $errorTitle = $firstError['title'] ?? 'Unsupported content';
            $errorDetails = $firstError['error_data']['details'] ?? 'Unknown error';
            $content = "Unsupported message. Error: $errorCode - $errorTitle: $errorDetails";
        } else {
            $errorCode = null;
            $errorTitle = 'Unsupported content';
            $errorDetails = 'No error details available';
            $content = "Unsupported message.";
        }

        $messageRecord = WhatsappModelResolver::message()->firstOrCreate(
            ['wa_id' => $message['id']],
            [
                'whatsapp_phone_id' => $whatsappPhone->phone_number_id,
                'contact_id' => $contact->contact_id,
                'conversation_id' => null,
                'messaging_product' => $message['messaging_product'] ?? 'whatsapp',
                'message_from'      => isset($message['from']) ? preg_replace('/[\D+]/', '', $message['from']) : null,
                'from_bsuid'        => $message['from_user_id'] ?? null,
                'from_parent_bsuid' => $message['from_parent_user_id'] ?? null,
                'message_to' => preg_replace('/[\D+]/', '', $whatsappPhone->display_phone_number),
                'message_type' => 'UNSUPPORTED',
                'message_content' => $content,
                'json_content' => json_encode($message),
                'status' => 'received',
                'code_error' => $errorCode,
                'title_error' => $errorTitle,
                'details_error' => $errorDetails,
                'message_context_id' => $this->getContextMessageId($message),
            ]
        );

        Log::channel('whatsapp')->warning('Unsupported message processed', [
            'message_id' => $messageRecord->message_id,
            'error_code' => $errorCode,
            'error_details' => $errorDetails
        ]);

        return $messageRecord;
    }

    protected function handleTemplateEvent(array $payload, string $field, array $templateData): void
    {
        $event = $templateData['event'] ?? null;
        $templateId = $templateData['id'] ?? null;
        if (empty($templateId)) {
            $templateId = $templateData['message_template_id'] ?? null;
        }

        if (!$event || !$templateId) {
            Log::channel('whatsapp')->warning('Invalid template event payload.', $templateData);
            return;
        }

        switch ($event) {
            case 'APPROVED':
            case 'REJECTED':
            case 'PENDING':
                $this->handleTemplateStatusUpdate($templateData, $payload, $field);
                break;
            case 'CREATE':
                $this->handleTemplateCreation($templateData);
                break;
            case 'UPDATE':
                $this->handleTemplateUpdate($templateData);
                break;
            case 'PENDING_DELETION':
            case 'DELETE':
                $this->handleTemplateDeletion($templateData);
                break;
            case 'DISABLE':
                $this->handleTemplateDisable($templateData);
                break;
            default:
                Log::channel('whatsapp')->warning("Unhandled template event: {$event}", $templateData);
        }
    }

    protected function handleTemplateStatusUpdate(array $templateData, array $payload, string $field): void
    {
        $templateId = $templateData['id'] ?? null;

        $message_template_id = $templateData['message_template_id'] ?? null;
        if (!empty($message_template_id)) {
            $templateId = $message_template_id;
        }
        $newStatus = $templateData['event'] ?? null; // APPROVED, REJECTED, PENDING
        $reason = $templateData['reason'] ?? null;
        $components = $templateData['components'] ?? [];

        if (!$templateId || !$newStatus) {
            Log::channel('whatsapp')->warning('Invalid template status update payload.', $templateData);
            return;
        }

        if ($field === 'message_template_status_update') {
            Log::channel('whatsapp')->info("Get template ID: {$templateId}", $templateData);
            //Usar la API para obtener el template, esto puede suceder por que se creó ó editó la plantilla desde el portal de Whatsapp Business, esto hará que en cuanto se aprueve o rechace se emita un webhook que esta clase procesará.
            $this->findTemplateAPI($payload);

            return;
        }

        $template = WhatsappModelResolver::template()
            ->where('wa_template_id', $templateId)
            ->first();

        if (!$template) {
            Log::channel('whatsapp')->warning("Template not found: {$templateId}");

            //Usar la API para obtener el template, esto puede suceder por que se creó ó editó la plantilla desde el portal de Whatsapp Business, esto hará que en cuanto se aprueve o rechace se emita un webhook que esta clase procesará.
            $this->findTemplateAPI($payload);

            return;
        }

        // Actualizar plantilla principal
        $template->update([
            'status' => $newStatus,
            //'rejection_reason' => $reason //No existe el cmapo rejection_reason en este modelo, solo en las versiones
        ]);

        // Crear nueva versión si hay cambios
        if (!empty($components)) {
            // Hay cambios en la estructura -> nueva versión
            $this->createTemplateVersion($template, $templateData);
        } else {
            // No hay cambios -> actualizar última versión existente
            $lastVersion = $template->versions()->latest()->first();
            if ($lastVersion) {
                $lastVersion->update([
                    'status' => $newStatus,
                    'rejection_reason' => $reason,
                    'is_active' => ($templateData['event'] === 'APPROVED'),
                ]);

                $this->createOrUpdateDefaultTemplateVersion($templateData['event'] ?? 'PENDING', $template, $lastVersion);
            } else {
                // Caso excepcional: no hay versiones pero debemos crear una
                $this->createTemplateVersion($template, array_merge($templateData, [
                    'components' => $template->components->toArray() ?? []
                ]));
            }
        }

        Log::channel('whatsapp')->info("Template status updated: {$newStatus}", [
            'template_id' => $template->template_id,
            'reason' => $reason
        ]);
    }

    /**
     * Se buscará un template mediante la api, al obtenerse se creará o actualizará según corresponda
     * @return void
     */
    protected function findTemplateAPI(array $payload): void
    {
        $entry = data_get($payload, 'entry.0');
        $businessAccountId = $entry['id'] ?? null;
        $templateId = $entry['changes'][0]['value']['message_template_id'] ?? null;

        if (empty($businessAccountId) || empty($templateId)) {
            Log::channel('whatsapp')->warning('Missing business account ID or template ID for API lookup.', $payload);
            return;
        }

        $businessAccount = WhatsappModelResolver::business_account()
            ->where('whatsapp_business_id', $businessAccountId)
            ->first();

        if (!$businessAccount) {
            Log::channel('whatsapp')->warning("Business account not found: {$businessAccountId}");
            return;
        }

        if (!$templateId) {
            Log::channel('whatsapp')->warning('No template ID provided for API lookup.', $payload);
            return;
        }

        $service = app(TemplateService::class);
        $service->getTemplateById($businessAccount, $templateId);
    }

    protected function createTemplateVersion(Model $template, array $templateData): void
    {
        $components = $templateData['components'] ?? $template->components->toArray();
        $event = $templateData['event'] ?? null;
        $reason = $templateData['reason'] ?? null;

        // Calcular hash del contenido
        $contentHash = md5(json_encode($components));

        // Verificar si ya existe una versión con este hash
        $existingVersion = $template->versions()
            ->where('version_hash', $contentHash)
            ->first();

        if ($existingVersion) {
            // Actualizar versión existente
            $existingVersion->update([
                'status' => $event,
                'rejection_reason' => $reason
            ]);

            $this->createOrUpdateDefaultTemplateVersion($event, $template, $existingVersion);

            return;
        }

        // Crear nueva versión
        $version = WhatsappModelResolver::template_version()->create([
            'template_id' => $template->template_id,
            'version_hash' => $contentHash,
            'template_structure' => json_encode($components),
            'status' => $event,
            'rejection_reason' => $reason
        ]);

        $this->createOrUpdateDefaultTemplateVersion($event, $template, $version);

        //Guardar el archivo del header si es que tiene
        $headerFormat = null;
        $headerUrlMultimedia = null;
        foreach ($components as $component) {
            if (Str::upper($component['type']) === 'HEADER') {
                $headerFormat = Str::upper($component['format']) ?? null;
                $headerUrlMultimedia = $component['example']['header_handle'][0] ?? null;
                break;
            }
        }
        if ($headerFormat && $headerUrlMultimedia && in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
            $this->saveTemplateVersionMedia($version, $headerUrlMultimedia, $headerFormat);
        }

        Log::channel('whatsapp')->info('New template version created', [
            'template_id' => $template->template_id,
            'version_id' => $version->version_id
        ]);
    }

    protected function saveTemplateVersionMedia(Model $version, string $mediaUrl, string $mediaType): void
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
        if ($status === 'APPROVED' && $template && $version) {
            $templateVersionDefaultModel = config('whatsapp.models.template_version_default');
            $templateVersionDefaultModel::upsertDefault($template->template_id, $version->version_id);
        }
    }

    protected function handleTemplateCreation(array $templateData): void
    {
        $templateId = $templateData['id'] ?? null;
        if (empty($templateId)) {
            $templateId = $templateData['message_template_id'] ?? null;
        }
        $businessAccountId = $templateData['business_account_id'] ?? null;
        $name = $templateData['name'] ?? null;
        $language = $templateData['language'] ?? null;
        $components = $templateData['components'] ?? [];
        $category = $templateData['category'] ?? null;
        $status = $templateData['status'] ?? 'PENDING';

        if (!$templateId || !$businessAccountId || !$name || !$language) {
            Log::channel('whatsapp')->warning('Missing required fields for template creation.', $templateData);
            return;
        }

        $businessAccount = WhatsappModelResolver::business_account()
            ->where('whatsapp_business_id', $businessAccountId)
            ->first();

        if (!$businessAccount) {
            Log::channel('whatsapp')->warning("Business account not found: {$businessAccountId}");
            return;
        }

        // Crear plantilla principal
        $template = WhatsappModelResolver::template()->create([
            'wa_template_id' => $templateId,
            'whatsapp_business_id' => $businessAccount->whatsapp_business_id,
            'name' => $name,
            'language' => $language,
            'status' => $status,
            'category_id' => $this->resolveCategoryId($category),
            'json' => json_encode($templateData)
        ]);

        // Crear versión inicial
        $this->createTemplateVersion($template, array_merge($templateData, ['event' => 'CREATE']));

        Log::channel('whatsapp')->info("Template created: {$name}", [
            'template_id' => $template->template_id,
            'status' => $status
        ]);
    }

    protected function handleTemplateUpdate(array $templateData): void
    {
        $templateId = $templateData['id'] ?? null;
        if (empty($templateId)) {
            $templateId = $templateData['message_template_id'] ?? null;
        }
        $components = $templateData['components'] ?? [];

        if (!$templateId) {
            Log::channel('whatsapp')->warning('Missing template ID for update', $templateData);
            return;
        }

        $template = WhatsappModelResolver::template()
            ->where('wa_template_id', $templateId)
            ->first();

        if (!$template) {
            Log::channel('whatsapp')->warning("Template not found for update: {$templateId}");
            return;
        }

        // Actualizar plantilla principal
        $template->update([
            'name' => $templateData['name'] ?? $template->name,
            'language' => $templateData['language'] ?? $template->language,
            'category_id' => $this->resolveCategoryId($templateData['category'] ?? null),
            'json' => json_encode($templateData)
        ]);

        // Crear nueva versión
        $this->createTemplateVersion($template, $templateData);

        Log::channel('whatsapp')->info("Template updated: {$template->name}", [
            'template_id' => $template->template_id
        ]);
    }

    /**
     * Procesa la actualización de calidad/límite de mensajes de un número de teléfono.
     *
     * @param array $value Datos del webhook phone_number_quality_update
     */
    protected function handlePhoneNumberQualityUpdate(array $value): void
    {
        Log::channel('whatsapp')->info('📊 [PHONE_NUMBER_QUALITY_UPDATE] Processing quality update', $value);

        // 1. Extraer datos del payload
        $displayPhoneNumber = $value['display_phone_number'] ?? null;
        $event = $value['event'] ?? null;
        $oldLimit = $value['old_limit'] ?? null; // deprecated, pero lo guardamos en log si se desea
        $currentLimit = $value['current_limit'] ?? null; // deprecated, priorizar el nuevo campo
        $maxDailyConversations = $value['max_daily_conversations_per_business'] ?? null;

        if (!$displayPhoneNumber) {
            Log::channel('whatsapp')->warning('⚠️ [PHONE_NUMBER_QUALITY_UPDATE] Missing display_phone_number', $value);
            return;
        }

        // 2. Determinar el nuevo nivel de límite (usar max_daily_conversations_per_business si existe, sino current_limit)
        $newLimitTier = $maxDailyConversations ?? $currentLimit;
        if (!$newLimitTier) {
            Log::channel('whatsapp')->warning('⚠️ [PHONE_NUMBER_QUALITY_UPDATE] No limit tier provided', $value);
            return;
        }

        // 3. Buscar el número de teléfono en la base de datos por display_phone_number
        $phoneNumber = WhatsappModelResolver::phone_number()
            ->where('display_phone_number', $displayPhoneNumber)
            ->first();

        if (!$phoneNumber) {
            Log::channel('whatsapp')->warning('⚠️ [PHONE_NUMBER_QUALITY_UPDATE] Phone number not found', [
                'display_phone_number' => $displayPhoneNumber
            ]);
            return;
        }

        // 4. Preparar datos para actualizar
        $updateData = [
            'messaging_limit_tier' => $newLimitTier,
            'messaging_limit_updated_at' => now(),
        ];

        // 5. Actualizar el registro
        $phoneNumber->update($updateData);

        Log::channel('whatsapp')->info('✅ [PHONE_NUMBER_QUALITY_UPDATE] Phone number messaging limit updated', [
            'phone_number_id' => $phoneNumber->phone_number_id,
            'display_phone_number' => $displayPhoneNumber,
            'new_limit_tier' => $newLimitTier,
            'event' => $event,
        ]);

        // 6. Disparar evento (opcional pero recomendado)
        $this->firePhoneNumberQualityUpdated($phoneNumber, $value);
    }

    protected function handleTemplateDeletion(array $templateData): void
    {
        $templateId = $templateData['id'] ?? null;
        if (empty($templateId)) {
            $templateId = $templateData['message_template_id'] ?? null;
        }

        if (!$templateId) {
            Log::channel('whatsapp')->warning('Missing template ID for deletion', $templateData);
            return;
        }

        $template = WhatsappModelResolver::template()
            ->where('wa_template_id', $templateId)
            ->first();

        if (!$template) {
            Log::channel('whatsapp')->warning("Template not found for deletion: {$templateId}");
            return;
        }

        // Soft delete de la plantilla y sus versiones
        $template->delete();
        $template->versions()->delete();

        Log::channel('whatsapp')->info("Template deleted: {$template->name}", [
            'template_id' => $template->template_id
        ]);
    }

    protected function handleTemplateDisable(array $templateData): void
    {
        $templateId = $templateData['id'] ?? null;
        if (empty($templateId)) {
            $templateId = $templateData['message_template_id'] ?? null;
        }

        if (!$templateId) {
            Log::channel('whatsapp')->warning('Missing template ID for disable', $templateData);
            return;
        }

        $template = WhatsappModelResolver::template()
            ->where('wa_template_id', $templateId)
            ->first();

        if (!$template) {
            Log::channel('whatsapp')->warning("Template not found for disable: {$templateId}");
            return;
        }

        // Desactivar plantilla y versiones
        $template->update(['status' => 'DISABLED']);
        $template->versions()->update(['status' => 'DISABLED']);

        Log::channel('whatsapp')->info("Template disabled: {$template->name}", [
            'template_id' => $template->template_id
        ]);
    }

    protected function resolveCategoryId(?string $categoryName): ?string
    {
        if (!$categoryName)
            return null;

        $category = WhatsappModelResolver::template_category()
            ->where('name', $categoryName)
            ->first();

        return $category ? $category->category_id : null;
    }


    /**
     * Ahora el disparo de los eventos estáran en métodos, y se usarán las clases de los eventos configuradas en el archivo de configuración whatsapp.events!
     */

    protected function fireTextMessageReceived($contactRecord, $messageRecord)
    {
        $event = config('whatsapp.events.messages.text.received');
        event(new $event([
            'contact' => $contactRecord,
            'message' => $messageRecord,
        ]));
    }

    /**
     * Ahora el disparo de los eventos estáran en métodos, y se usarán las clases de los eventos configuradas en el archivo de configuración whatsapp.events!
     */

    protected function fireButtonMessageReceived($contactRecord, $messageRecord)
    {
        $event = config('whatsapp.events.messages.button.received');
        event(new $event([
            'contact' => $contactRecord,
            'message' => $messageRecord,
        ]));
    }

    protected function fireInteractiveMessageReceived($contactRecord, $messageRecord)
    {
        $event = config('whatsapp.events.messages.interactive.received');
        event(new $event([
            'contact' => $contactRecord,
            'message' => $messageRecord,
        ]));
    }

    protected function fireUnsupportedMessageReceived($contactRecord, $messageRecord, $titleError, $messageError, $detailsError)
    {
        $event = config('whatsapp.events.messages.unsupported.received');
        event(new $event([
            'contact' => $contactRecord,
            'message' => $messageRecord,
            'title_error' => $titleError,
            'message_error' => $messageError,
            'details_error' => $detailsError
        ]));
    }

    protected function fireLocationMessageReceived($contactRecord, $messageRecord)
    {
        $event = config('whatsapp.events.messages.location.received');
        event(new $event([
            'contact' => $contactRecord,
            'message' => $messageRecord,
        ]));
    }

    protected function fireContactMessageReceived($contactRecord, $messageRecord)
    {
        $event = config('whatsapp.events.messages.contact.received');
        event(new $event([
            'contact' => $contactRecord,
            'message' => $messageRecord,
        ]));
    }

    protected function fireReactionReceived($contactRecord, $messageRecord)
    {
        $event = config('whatsapp.events.messages.reaction.received');
        event(new $event([
            'contact' => $contactRecord,
            'message' => $messageRecord,
        ]));
    }

    protected function fireMediaMessageReceived($contactRecord, $messageRecord)
    {
        $event = config('whatsapp.events.messages.media.received');
        event(new $event([
            'contact' => $contactRecord,
            'message' => $messageRecord,
        ]));
    }

    protected function fireMessageReceived($contactRecord, $messageRecord)
    {
        $event = config('whatsapp.events.messages.message.received');
        event(new $event([
            'contact' => $contactRecord,
            'message' => $messageRecord,
        ]));
    }

    protected function fireMessageDelivered($messageUpdated)
    {
        $event = config('whatsapp.events.messages.message.delivered');
        event(new $event([
            'message' => $messageUpdated,
        ]));
    }

    protected function fireMessageRead($messageUpdated)
    {
        $event = config('whatsapp.events.messages.message.read');
        event(new $event([
            'message' => $messageUpdated,
        ]));
    }

    protected function fireMessageFailed($messageUpdated)
    {
        $event = config('whatsapp.events.messages.message.failed');
        event(new $event([
            'message' => $messageUpdated,
        ]));
    }

    protected function fireSystemMessageReceived($contactRecord, $messageRecord)
    {
        $event = config('whatsapp.events.messages.system.received');
        event(new $event([
            'contact' => $contactRecord,
            'message' => $messageRecord,
        ]));
    }

    protected function fireMarketingOptOut($messageUpdated)
    {
        $event = config('whatsapp.events.messages.message.marketing_opt_out');
        event(new $event([
            'message' => $messageUpdated,
        ]));
    }

    protected function processSystemMessage(array $message, Model $contact, Model $whatsappPhone): ?Model
    {
        $systemType    = $message['system']['type'] ?? '';
        $body          = $message['system']['body'] ?? '';
        $newWaId       = $message['system']['new_wa_id'] ?? $message['system']['wa_id'] ?? null;
        $newBsuid      = $message['system']['user_id'] ?? null;
        $newParentBsuid = $message['system']['parent_user_id'] ?? null;

        // Caso especial: cambio de número de teléfono
        if ($systemType === 'user_changed_number') {
            return $this->processUserChangedNumber($message, $contact, $whatsappPhone, $body, $newWaId);
        }

        // Caso especial: cambio de BSUID (el usuario cambió de número, su BSUID se regeneró)
        if ($systemType === 'user_changed_user_id') {
            return $this->processUserChangedUserId($message, $contact, $whatsappPhone, $body, $newBsuid, $newParentBsuid);
        }

        if ($systemType === 'user_preference_changed') {
            $marketingPreference = $message['system']['marketing'] ?? null;
            if ($marketingPreference) {
                $this->updateContactMarketingPreference(
                    $contact->contact_id,
                    $marketingPreference === 'resume'
                );
            }
        }

        // Otros tipos de mensajes de sistema
        $messageRecord = WhatsappModelResolver::message()->firstOrCreate(
            ['wa_id' => $message['id']],
            [
                'whatsapp_phone_id'  => $whatsappPhone->phone_number_id,
                'contact_id'         => $contact->contact_id,
                'conversation_id'    => null,
                'messaging_product'  => $message['messaging_product'] ?? 'whatsapp',
                'message_from'       => isset($message['from']) ? preg_replace('/[\D+]/', '', $message['from']) : null,
                'from_bsuid'         => $message['system']['user_id'] ?? null,
                'from_parent_bsuid'  => $message['system']['parent_user_id'] ?? null,
                'message_to'         => preg_replace('/[\D+]/', '', $whatsappPhone->display_phone_number),
                'message_type'       => 'SYSTEM',
                'message_content'    => $body,
                'json_content'       => json_encode($message),
                'status'             => 'received',
                'message_context_id' => $this->getContextMessageId($message),
            ]
        );

        Log::channel('whatsapp')->info('System message processed', [
            'message_id' => $messageRecord->message_id,
            'system_type' => $systemType
        ]);

        return $messageRecord;
    }

    protected function processUserChangedNumber(
        array $message,
        Model $contact,
        Model $whatsappPhone,
        string $body,
        ?string $newWaId
    ): ?Model {
        // Actualizar el contacto con el nuevo wa_id
        if ($newWaId) {
            $contact->update(['wa_id' => $newWaId]);

            Log::channel('whatsapp')->info('Contact phone number updated', [
                'contact_id' => $contact->contact_id,
                'old_wa_id' => $contact->getOriginal('wa_id'),
                'new_wa_id' => $newWaId
            ]);
        }

        // Crear registro del mensaje
        $messageRecord = WhatsappModelResolver::message()->create([
            'wa_id' => $message['id'],
            'whatsapp_phone_id' => $whatsappPhone->phone_number_id,
            'contact_id' => $contact->contact_id,
            'conversation_id' => null,
            'messaging_product' => $message['messaging_product'] ?? 'whatsapp',
            'message_from'      => isset($message['from']) ? preg_replace('/[\D+]/', '', $message['from']) : null,
            'from_bsuid'        => $message['from_user_id'] ?? null,
            'from_parent_bsuid' => $message['from_parent_user_id'] ?? null,
            'message_to' => preg_replace('/[\D+]/', '', $whatsappPhone->display_phone_number),
            'message_type' => 'SYSTEM',
            'message_content' => $body,
            'json_content' => json_encode($message),
            'status' => 'received',
            'message_context_id' => $this->getContextMessageId($message),
        ]);

        return $messageRecord;
    }

    protected function updateContactMarketingPreference($contactId, $acceptsMarketing): void
    {
        $contact = WhatsappModelResolver::contact()->find($contactId);

        if ($contact) {
            $updateData = ['accepts_marketing' => $acceptsMarketing];

            // Cuando se establece en false (opt-out), registrar la marca de tiempo
            if ($acceptsMarketing === false) {
                $updateData['marketing_opt_out_at'] = now();
            } else {
                // Si se establece en true, limpiar la marca de tiempo
                $updateData['marketing_opt_out_at'] = null;
            }

            $contact->update($updateData);

            Log::channel('whatsapp')->info('Contact marketing preference updated', [
                'contact_id' => $contactId,
                'accepts_marketing' => $acceptsMarketing
            ]);
        }
    }

    protected function handleUserPreferences(array $data): void
    {
        $userPreferences = $data['user_preferences'] ?? [];

        foreach ($userPreferences as $preference) {
            if ($preference['category'] === 'marketing_messages') {
                $waId  = $preference['wa_id'] ?? null;   // puede estar ausente con usernames
                $bsuid = $preference['user_id'] ?? null; // nuevo campo BSUID
                $value = $preference['value'];

                $this->updateContactMarketingPreferenceByIdentifier($waId, $bsuid, $value);
            }
        }
    }

    /**
     * Actualiza la preferencia de marketing del contacto.
     * Busca primero por BSUID (siempre presente desde 31/03/2026),
     * con fallback a wa_id para contactos pre-BSUID.
     */
    protected function updateContactMarketingPreferenceByIdentifier(?string $waId, ?string $bsuid, string $preference): void
    {
        $contact = null;

        if ($bsuid) {
            $contact = WhatsappModelResolver::contact()->where('bsuid', $bsuid)->first();
        }
        if (!$contact && $waId) {
            $contact = WhatsappModelResolver::contact()->where('wa_id', $waId)->first();
        }

        if ($contact) {
            $acceptsMarketing = ($preference === 'resume');
            $this->updateContactMarketingPreference($contact->contact_id, $acceptsMarketing);
        }
    }


    /**
     * Procesa el webhook `business_username_update`.
     *
     * Se activa cuando el estado del nombre de usuario de empresa cambia:
     * - approved: el nombre está aprobado y visible para usuarios
     * - reserved: el nombre está reservado pero no visible aún
     * - deleted:  el nombre fue eliminado (username puede estar ausente en el payload)
     */
    protected function handleBusinessUsernameUpdate(array $value): void
    {
        $displayPhone = $value['display_phone_number'] ?? null;
        $username     = $value['username'] ?? null;
        $status       = $value['status'] ?? null;

        Log::channel('whatsapp')->info('Business username update recibido.', [
            'display_phone_number' => $displayPhone,
            'username'             => $username,
            'status'               => $status,
        ]);

        event(new BusinessUsernameUpdated([
            'display_phone_number' => $displayPhone,
            'username'             => $username,
            'status'               => $status,
        ]));
    }

    /**
     * Procesa el webhook `user_id_update`.
     *
     * Se activa cuando el BSUID de un usuario cambia (normalmente porque cambió su
     * número de teléfono). Actualiza el contacto en BD y dispara el evento UserIdUpdated.
     */
    protected function handleUserIdUpdate(array $value): void
    {
        $metadata  = $value['metadata'] ?? [];
        $updates   = $value['user_id_update'] ?? [];

        if (empty($updates)) {
            return;
        }

        foreach ($updates as $update) {
            $waId            = $update['wa_id'] ?? null;
            $previousBsuid   = $update['user_id']['previous'] ?? null;
            $currentBsuid    = $update['user_id']['current'] ?? null;
            $previousParent  = $update['parent_user_id']['previous'] ?? null;
            $currentParent   = $update['parent_user_id']['current'] ?? null;
            $timestamp       = $update['timestamp'] ?? null;

            Log::channel('whatsapp')->info('user_id_update recibido.', [
                'wa_id'          => $waId,
                'previous_bsuid' => $previousBsuid,
                'current_bsuid'  => $currentBsuid,
            ]);

            // Actualizar el BSUID del contacto en la base de datos si tenemos el anterior
            if ($previousBsuid && $currentBsuid) {
                $contact = WhatsappModelResolver::contact()
                    ->where('bsuid', $previousBsuid)
                    ->orWhere('wa_id', $waId)
                    ->first();

                if ($contact) {
                    $contact->update(array_filter([
                        'bsuid'        => $currentBsuid,
                        'parent_bsuid' => $currentParent,
                    ], fn($v) => $v !== null));
                }
            }

            event(new UserIdUpdated([
                'wa_id'                  => $waId,
                'previous_bsuid'         => $previousBsuid,
                'current_bsuid'          => $currentBsuid,
                'previous_parent_bsuid'  => $previousParent,
                'current_parent_bsuid'   => $currentParent,
                'timestamp'              => $timestamp,
                'display_phone_number'   => $metadata['display_phone_number'] ?? null,
                'phone_number_id'        => $metadata['phone_number_id'] ?? null,
            ]));
        }
    }

    /**
     * Procesa el cambio de BSUID de un usuario.
     *
     * Se activa cuando el tipo de sistema es `user_changed_user_id`, lo que ocurre
     * cuando el usuario cambia su número de teléfono en WhatsApp: su BSUID se regenera.
     *
     * El `from` contiene el número antiguo (si disponible). El nuevo BSUID llega en
     * `system.user_id`. Se actualiza el contacto existente con el nuevo BSUID.
     */
    protected function processUserChangedUserId(
        array $message,
        Model $contact,
        Model $whatsappPhone,
        string $body,
        ?string $newBsuid,
        ?string $newParentBsuid = null
    ): ?Model {
        // Actualizar el BSUID del contacto con el nuevo identificador
        if ($newBsuid) {
            $contact->update(array_filter([
                'bsuid'        => $newBsuid,
                'parent_bsuid' => $newParentBsuid,
            ], fn($v) => $v !== null));

            Log::channel('whatsapp')->info('BSUID de contacto actualizado', [
                'contact_id'   => $contact->contact_id,
                'old_bsuid'    => $contact->getOriginal('bsuid'),
                'new_bsuid'    => $newBsuid,
                'parent_bsuid' => $newParentBsuid,
            ]);
        }

        // Crear registro del mensaje de sistema
        $messageRecord = WhatsappModelResolver::message()->create([
            'wa_id'             => $message['id'],
            'whatsapp_phone_id' => $whatsappPhone->phone_number_id,
            'contact_id'        => $contact->contact_id,
            'messaging_product' => $message['messaging_product'] ?? 'whatsapp',
            'message_from'      => isset($message['from']) ? preg_replace('/\D/', '', $message['from']) : null,
            'from_bsuid'        => $newBsuid,
            'from_parent_bsuid' => $newParentBsuid,
            'message_to'        => preg_replace('/[\D+]/', '', $whatsappPhone->display_phone_number),
            'message_type'      => 'SYSTEM',
            'message_content'   => $body,
            'json_content'      => json_encode($message),
            'status'            => 'received',
        ]);

        Log::channel('whatsapp')->info('System message user_changed_user_id procesado', [
            'message_id' => $messageRecord->message_id,
            'new_bsuid'  => $newBsuid,
        ]);

        return $messageRecord;
    }

    // =========================================================================
    // COEXISTENCE WEBHOOKS IMPLEMENTATION
    // =========================================================================





    /**
     * Maneja la sincronización del historial de mensajes de coexistencia
     */
    /**
     * Webhook para sincronización de historial de mensajes
     * Se dispara cuando un negocio completa el onboarding con coexistencia
     * y comparte su historial de mensajes
     */
    protected function handleHistorySync(array $data): void
    {
        Log::channel('whatsapp')->info('🔄 [COEXISTENCE] Iniciando sincronización de historial', [
            'phone_number_id' => $data['metadata']['phone_number_id'] ?? null,
            'display_phone_number' => $data['metadata']['display_phone_number'] ?? null
        ]);

        $messagingProduct = $data['messaging_product'] ?? 'whatsapp';
        $metadata = $data['metadata'] ?? [];
        $historyData = $data['history'] ?? [];

        $phoneNumberId = $metadata['phone_number_id'] ?? null;
        $displayPhoneNumber = $metadata['display_phone_number'] ?? null;

        if (!$phoneNumberId) {
            Log::channel('whatsapp')->warning('No phone_number_id found in history sync', $data);
            return;
        }

        $whatsappPhone = WhatsappModelResolver::phone_number()
            ->where('api_phone_number_id', $phoneNumberId)
            ->first();

        if (!$whatsappPhone) {
            Log::channel('whatsapp')->warning('No matching phone number found for history sync', [
                'phone_number_id' => $phoneNumberId
            ]);
            return;
        }

        // Verificar si hay errores (cuando el negocio no comparte historial)
        if (isset($historyData['errors'])) {
            $this->handleHistorySyncError($historyData['errors'], $whatsappPhone);
            return;
        }

        // Procesar threads de historial
        foreach ($historyData as $historyItem) {
            $this->processHistoryThreads($historyItem, $whatsappPhone);
        }

        // Post-procesamiento personalizado
        $this->afterHistorySync($data);

        Log::channel('whatsapp')->info('✅ [COEXISTENCE] Historial sincronizado exitosamente', [
            'phone_number_id' => $phoneNumberId,
            'threads_count' => count($historyData)
        ]);
    }

    /**
     * Procesa los threads de historial de mensajes
     */
    protected function processHistoryThreads(array $historyItem, Model $whatsappPhone): void
    {
        $metadata = $historyItem['metadata'] ?? [];
        $threads = $historyItem['threads'] ?? [];

        $phase = $metadata['phase'] ?? 0;
        $chunkOrder = $metadata['chunk_order'] ?? 1;
        $progress = $metadata['progress'] ?? 0;

        Log::channel('whatsapp')->info('Processing history chunk', [
            'phase' => $phase,
            'chunk_order' => $chunkOrder,
            'progress' => $progress,
            'threads_count' => count($threads)
        ]);

        foreach ($threads as $thread) {
            $this->processHistoryThread($thread, $whatsappPhone, $phase);
        }
    }

    /**
     * Procesa un thread individual del historial
     */
    protected function processHistoryThread(array $thread, Model $whatsappPhone, int $phase): void
    {
        $threadId = $thread['id'] ?? null; // WhatsApp user phone number
        $messages = $thread['messages'] ?? [];

        if (!$threadId || empty($messages)) {
            return;
        }

        // Obtener o crear el contacto
        $fullPhone = preg_replace('/\D/', '', $threadId);
        [$countryCode, $phoneNumber] = $this->splitPhoneNumber($fullPhone);

        if (empty($countryCode) || empty($phoneNumber)) {
            Log::channel('whatsapp')->warning('Unable to split phone number in history thread', [
                'thread_id' => $threadId
            ]);
            return;
        }

        $contactRecord = WhatsappModelResolver::contact()->firstOrCreate(
            [
                'country_code' => $countryCode,
                'phone_number' => $phoneNumber,
            ],
            [
                'wa_id' => $threadId,
                'contact_name' => null, // No hay nombre en el historial
            ]
        );

        // Procesar mensajes del thread
        foreach ($messages as $message) {
            $this->processHistoryMessage($message, $contactRecord, $whatsappPhone, $phase);
        }
    }

    /**
     * Procesa un mensaje individual del historial
     */
    protected function processHistoryMessage(array $message, Model $contact, Model $whatsappPhone, int $phase): void
    {
        // Lógica personalizada pre-procesamiento
        $this->beforeProcessHistoryMessage($message, $contact, $whatsappPhone, $phase);

        $messageType = $message['type'] ?? '';
        $from = $message['from'] ?? '';
        $timestamp = $message['timestamp'] ?? null;
        $historyContext = $message['history_context'] ?? [];

        // Normalizar números para comparación
        $fromNormalized = preg_replace('/[\D+]/', '', $from);
        $displayPhoneNormalized = preg_replace('/[\D+]/', '', $whatsappPhone->display_phone_number);

        // Determinar si el mensaje fue enviado por el negocio (OUTPUT) o recibido (INPUT)
        $isFromBusiness = ($fromNormalized === $displayPhoneNormalized);

        // Preparar datos base del mensaje
        $messageData = [
            'wa_id' => $message['id'],
            'whatsapp_phone_id' => $whatsappPhone->phone_number_id,
            'contact_id' => $contact->contact_id,
            'conversation_id' => null,
            'messaging_product' => 'whatsapp',
            'message_method' => $isFromBusiness ? 'OUTPUT' : 'INPUT', // Determinar dirección del mensaje
            'message_from' => $fromNormalized,
            'message_to' => $isFromBusiness ?
                preg_replace('/[\D+]/', '', $contact->country_code . $contact->phone_number) :
                $displayPhoneNormalized,
            'message_type' => strtoupper($messageType),
            'json_content' => json_encode($message),
            'status' => $historyContext['status'] ?? 'delivered', // Asumir entregado para historial
            'message_context_id' => $this->getContextMessageId($message),
            'is_historical' => true, // Marcar como mensaje histórico
            'historical_phase' => $phase,
        ];

        // Procesar contenido según el tipo de mensaje
        switch ($messageType) {
            case 'text':
                $messageData['message_content'] = $message['text']['body'] ?? '';
                break;

            case 'image':
            case 'audio':
            case 'video':
            case 'document':
            case 'sticker':
                $messageData['message_content'] = $message[$messageType]['caption'] ?? strtoupper($messageType);
                break;

            case 'interactive':
                $messageData['message_content'] = $message['interactive']['button_reply']['title']
                    ?? $message['interactive']['list_reply']['title']
                    ?? 'Interactive message';
                break;

            case 'location':
                $messageData['message_content'] = "Location: " . ($message['location']['name'] ?? '');
                break;

            default:
                $messageData['message_content'] = $this->getMessageContentForType($messageType, $message)
                    ?? 'Historical message';
        }

        Log::channel('whatsapp')->info('💾 [COEXISTENCE] Guardando mensaje histórico', [
            'wa_id' => $messageData['wa_id'],
            'type' => $messageData['message_type'],
            'method' => $messageData['message_method'],
            'is_from_business' => $isFromBusiness,
            'phase' => $phase
        ]);

        try {
            // Crear registro del mensaje histórico
            $messageRecord = WhatsappModelResolver::message()->firstOrCreate(
                ['wa_id' => $messageData['wa_id']],
                $messageData
            );

            Log::channel('whatsapp')->debug('✅ [COEXISTENCE] Mensaje histórico guardado', [
                'message_id' => $messageRecord->message_id,
                'type' => $messageType,
                'method' => $messageRecord->message_method,
                'phase' => $phase
            ]);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('❌ [COEXISTENCE] Error al guardar mensaje histórico', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'message_data' => $messageData
            ]);
            throw $e;
        }

        // Lógica personalizada post-procesamiento
        $this->afterProcessHistoryMessage($message, $contact, $whatsappPhone, $phase);
    }

    /**
     * Maneja errores en la sincronización del historial
     */
    protected function handleHistorySyncError(array $errors, Model $whatsappPhone): void
    {
        foreach ($errors as $error) {
            $errorCode = $error['code'] ?? null;
            $errorMessage = $error['message'] ?? 'Unknown error';

            if ($errorCode === 2593109) {
                Log::channel('whatsapp')->warning('Business chose not to share messaging history', [
                    'phone_number_id' => $whatsappPhone->api_phone_number_id,
                    'error' => $errorMessage
                ]);
            } else {
                Log::channel('whatsapp')->error('History sync error', [
                    'phone_number_id' => $whatsappPhone->api_phone_number_id,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage
                ]);
            }
        }
    }

    /**
     * Webhook para sincronización de contactos desde WhatsApp Business App
     * Se dispara cuando hay cambios en los contactos de la app móvil
     */
    protected function handleSmbAppStateSync(array $data): void
    {
        Log::channel('whatsapp')->info('📇 [COEXISTENCE] Sincronizando contactos desde WhatsApp Business App', [
            'phone_number_id' => $data['metadata']['phone_number_id'] ?? null,
            'contacts_count' => count($data['state_sync'] ?? [])
        ]);

        $messagingProduct = $data['messaging_product'] ?? 'whatsapp';
        $metadata = $data['metadata'] ?? [];
        $stateSync = $data['state_sync'] ?? [];

        $phoneNumberId = $metadata['phone_number_id'] ?? null;

        if (!$phoneNumberId) {
            Log::channel('whatsapp')->warning('No phone_number_id found in SMB app state sync', $data);
            return;
        }

        $whatsappPhone = WhatsappModelResolver::phone_number()
            ->where('api_phone_number_id', $phoneNumberId)
            ->first();

        if (!$whatsappPhone) {
            Log::channel('whatsapp')->warning('No matching phone number found for SMB app state sync', [
                'phone_number_id' => $phoneNumberId
            ]);
            return;
        }

        foreach ($stateSync as $syncItem) {
            $this->processContactSync($syncItem, $whatsappPhone);
        }

        // Post-procesamiento personalizado
        $this->afterContactsSync($data);

        Log::channel('whatsapp')->info('✅ [COEXISTENCE] Contactos sincronizados exitosamente', [
            'phone_number_id' => $phoneNumberId,
            'contacts_count' => count($stateSync)
        ]);
    }

    /**
     * Procesa la sincronización de un contacto individual
     */
    protected function processContactSync(array $syncItem, Model $whatsappPhone): void
    {
        // Validación personalizada antes de sincronizar
        if (!$this->shouldSyncContact($syncItem)) {
            Log::channel('whatsapp')->debug('[COEXISTENCE] Contacto omitido por validación personalizada', $syncItem);
            return;
        }

        $type = $syncItem['type'] ?? '';
        $action = $syncItem['action'] ?? '';
        $contactData = $syncItem['contact'] ?? [];
        $metadata = $syncItem['metadata'] ?? [];

        if ($type !== 'contact' || empty($contactData)) {
            return;
        }

        $fullName = $contactData['full_name'] ?? '';
        $firstName = $contactData['first_name'] ?? '';
        $phoneNumberRaw = $contactData['phone_number'] ?? '';

        if (!$phoneNumberRaw) {
            Log::channel('whatsapp')->warning('No phone number in contact sync', $syncItem);
            return;
        }

        // Normalizar número de teléfono
        $fullPhone = preg_replace('/\D/', '', $phoneNumberRaw);
        [$countryCode, $phoneNumber] = $this->splitPhoneNumber($fullPhone);

        if (empty($countryCode) || empty($phoneNumber)) {
            Log::channel('whatsapp')->warning('Unable to split phone number in contact sync', [
                'phone_number_raw' => $phoneNumberRaw
            ]);
            return;
        }

        $waId = $fullPhone; // Usar el número normalizado como WA ID

        if ($action === 'remove') {
            // Marcar contacto como eliminado (soft delete)
            $contact = WhatsappModelResolver::contact()
                ->where('country_code', $countryCode)
                ->where('phone_number', $phoneNumber)
                ->first();

            if ($contact) {
                $contact->delete();
                Log::channel('whatsapp')->info('Contact removed via SMB sync', [
                    'contact_id' => $contact->contact_id
                ]);
            }
        } else {
            // Agregar o actualizar contacto
            $contact = WhatsappModelResolver::contact()->updateOrCreate(
                [
                    'country_code' => $countryCode,
                    'phone_number' => $phoneNumber,
                ],
                [
                    'wa_id' => $waId,
                    'contact_name' => $fullName ?: $firstName,
                    'first_name' => $firstName,
                    'full_name' => $fullName,
                ]
            );

            Log::channel('whatsapp')->debug('Contact synced via SMB app', [
                'contact_id' => $contact->contact_id,
                'action' => $action
            ]);
        }

        // Post-procesamiento personalizado
        $this->afterProcessContactSync($syncItem, $whatsappPhone);
    }

    /**
     * Webhook para ecos de mensajes enviados desde WhatsApp Business App
     * Se dispara en tiempo real cuando el negocio envía mensajes desde la app móvil
     * Esto permite mantener tu aplicación sincronizada con los mensajes enviados desde el móvil
     */
    protected function handleSmbMessageEchoes(array $data): void
    {
        Log::channel('whatsapp')->info('💬 [COEXISTENCE] Eco de mensaje desde WhatsApp Business App recibido', [
            'phone_number_id' => $data['metadata']['phone_number_id'] ?? null,
            'display_phone_number' => $data['metadata']['display_phone_number'] ?? null,
            'messages_count' => count($data['message_echoes'] ?? [])
        ]);

        $messagingProduct = $data['messaging_product'] ?? 'whatsapp';
        $metadata = $data['metadata'] ?? [];
        $messageEchoes = $data['message_echoes'] ?? [];

        $phoneNumberId = $metadata['phone_number_id'] ?? null;
        $displayPhoneNumber = $metadata['display_phone_number'] ?? null;

        if (!$phoneNumberId) {
            Log::channel('whatsapp')->warning('No phone_number_id found in SMB message echoes', $data);
            return;
        }

        $whatsappPhone = WhatsappModelResolver::phone_number()
            ->where('api_phone_number_id', $phoneNumberId)
            ->first();

        if (!$whatsappPhone) {
            Log::channel('whatsapp')->warning('No matching phone number found for SMB message echoes', [
                'phone_number_id' => $phoneNumberId
            ]);
            return;
        }

        // Verificar que existan message_echoes
        if (empty($messageEchoes)) {
            Log::channel('whatsapp')->warning('⚠️ [COEXISTENCE] No hay message_echoes en el payload', [
                'data_keys' => array_keys($data)
            ]);
            return;
        }

        // Log detallado de cada mensaje
        foreach ($messageEchoes as $index => $echo) {
            $messageType = $echo['type'] ?? 'unknown';
            $hasErrors = !empty($echo['errors']);

            Log::channel('whatsapp')->debug("📨 [COEXISTENCE] Mensaje echo {$index}", [
                'type' => $messageType,
                'has_errors' => $hasErrors,
                'error_count' => $hasErrors ? count($echo['errors']) : 0,
                'from' => $echo['from'] ?? null,
                'to' => $echo['to'] ?? null
            ]);

            if ($hasErrors) {
                Log::channel('whatsapp')->warning("⚠️ [COEXISTENCE] Mensaje echo con errores", [
                    'type' => $messageType,
                    'errors' => $echo['errors']
                ]);
            }
        }

        foreach ($messageEchoes as $echo) {
            $this->processSmbMessageEcho($echo, $whatsappPhone);
        }

        // Post-procesamiento personalizado
        $this->afterSmbMessageEchoes($data);

        Log::channel('whatsapp')->info('✅ [COEXISTENCE] Ecos de mensajes procesados exitosamente', [
            'phone_number_id' => $phoneNumberId,
            'echoes_count' => count($messageEchoes)
        ]);
    }



    /**
     * Procesa un eco de mensaje SMB individual
     */
    protected function processSmbMessageEcho(array $echo, Model $whatsappPhone): void
    {
        // Validar que el echo tenga la estructura básica requerida
        if (empty($echo['id']) || empty($echo['from']) || empty($echo['to'])) {
            Log::channel('whatsapp')->warning('⚠️ [COEXISTENCE] Echo de mensaje con estructura incompleta', [
                'echo_keys' => array_keys($echo),
                'has_id' => !empty($echo['id']),
                'has_from' => !empty($echo['from']),
                'has_to' => !empty($echo['to'])
            ]);
            return;
        }

        $from = $echo['from'] ?? '';
        $to = $echo['to'] ?? '';
        $messageType = $echo['type'] ?? 'unknown';
        $timestamp = $echo['timestamp'] ?? null;
        $hasErrors = !empty($echo['errors']);

        Log::channel('whatsapp')->info('🔄 [COEXISTENCE] Procesando eco de mensaje individual', [
            'echo_id' => $echo['id'],
            'from' => $from,
            'to' => $to,
            'type' => $messageType,
            'has_errors' => $hasErrors,
            'whatsapp_phone_display' => $whatsappPhone->display_phone_number ?? null,
            'whatsapp_phone_id' => $whatsappPhone->phone_number_id ?? null
        ]);

        if ($hasErrors) {
            Log::channel('whatsapp')->warning('⚠️ [COEXISTENCE] Echo de mensaje con errores', [
                'echo_id' => $echo['id'],
                'errors' => $echo['errors']
            ]);
        }

        // Pre-procesamiento personalizado
        $this->beforeProcessSmbMessageEcho($echo, $whatsappPhone);

        // CORREGIR PROBLEMA: Normalizar números antes de comparar
        $fromNormalized = preg_replace('/[\D+]/', '', $from);
        $displayPhoneNormalized = preg_replace('/[\D+]/', '', $whatsappPhone->display_phone_number);

        Log::channel('whatsapp')->info('📞 [COEXISTENCE] Comparando números normalizados', [
            'from_original' => $from,
            'from_normalized' => $fromNormalized,
            'display_original' => $whatsappPhone->display_phone_number,
            'display_normalized' => $displayPhoneNormalized,
            'match' => $fromNormalized === $displayPhoneNormalized
        ]);

        // Verificar que el mensaje viene del negocio correcto (usando números normalizados)
        if ($fromNormalized !== $displayPhoneNormalized) {
            Log::channel('whatsapp')->warning('⚠️ [COEXISTENCE] SMB message echo from unexpected number', [
                'expected' => $whatsappPhone->display_phone_number,
                'expected_normalized' => $displayPhoneNormalized,
                'actual' => $from,
                'actual_normalized' => $fromNormalized
            ]);
            return;
        }

        // Obtener o crear el contacto destino
        $fullPhone = preg_replace('/\D/', '', $to);
        [$countryCode, $phoneNumber] = $this->splitPhoneNumber($fullPhone);

        if (empty($countryCode) || empty($phoneNumber)) {
            Log::channel('whatsapp')->warning('⚠️ [COEXISTENCE] Unable to split phone number in SMB message echo', [
                'to' => $to
            ]);
            return;
        }

        Log::channel('whatsapp')->info('👤 [COEXISTENCE] Creando/obteniendo contacto', [
            'country_code' => $countryCode,
            'phone_number' => $phoneNumber,
            'full_phone' => $fullPhone
        ]);

        $contactRecord = WhatsappModelResolver::contact()->firstOrCreate(
            [
                'country_code' => $countryCode,
                'phone_number' => $phoneNumber,
            ],
            [
                'wa_id' => $to,
                'contact_name' => null,
            ]
        );

        // DETERMINAR SI ES MENSAJE MULTIMEDIA
        $isMediaMessage = in_array($messageType, ['image', 'audio', 'video', 'document', 'sticker']);

        // Si es mensaje multimedia, usar el método especializado
        if ($isMediaMessage) {
            $this->processSmbMediaMessageEcho($echo, $contactRecord, $whatsappPhone, $messageType);
        } else {
            // Para mensajes no multimedia, usar el procesamiento normal
            $this->processSmbNonMediaMessageEcho($echo, $contactRecord, $whatsappPhone, $messageType);
        }

        // Post-procesamiento personalizado
        $this->afterProcessSmbMessageEcho($echo, $whatsappPhone);
    }

    /**
     * Maneja actualizaciones de cuenta (útil para detectar desconexiones)
     */
    protected function handleAccountUpdate(array $data): void
    {
        Log::channel('whatsapp')->info('🔄 [ACCOUNT] Processing account update', $data);

        $event = $data['event'] ?? '';
        $wabaInfo = $data['waba_info'] ?? [];

        Log::channel('whatsapp')->info('📋 [ACCOUNT] Account update details', [
            'event' => $event,
            'waba_info' => $wabaInfo
        ]);

        switch ($event) {
            case 'PARTNER_APP_INSTALLED':
                $this->handlePartnerAppInstalled($wabaInfo);
                break;

            case 'PARTNER_ADDED':
                $this->handlePartnerAdded($wabaInfo);
                break;

            case 'PARTNER_APP_UNINSTALLED':
                $this->handlePartnerAppUninstalled($wabaInfo);
                break;

            case 'PARTNER_REMOVED':
                $this->handlePartnerRemoved($wabaInfo);
                break;

            default:
                Log::channel('whatsapp')->warning('⚠️ [ACCOUNT] Unhandled account update event', [
                    'event' => $event,
                    'waba_info' => $wabaInfo
                ]);
        }

        // Disparar evento de actualización de cuenta
        $this->fireAccountUpdate($data);
    }

    /**
     * Maneja la instalación de la aplicación partner
     */
    protected function handlePartnerAppInstalled(array $wabaInfo): void
    {
        $wabaId = $wabaInfo['waba_id'] ?? null;
        $ownerBusinessId = $wabaInfo['owner_business_id'] ?? null;
        $partnerAppId = $wabaInfo['partner_app_id'] ?? null;

        Log::channel('whatsapp')->info('✅ [ACCOUNT] Partner app installed', [
            'waba_id' => $wabaId,
            'owner_business_id' => $ownerBusinessId,
            'partner_app_id' => $partnerAppId
        ]);

        // Buscar la cuenta de WhatsApp Business afectada
        $businessAccount = WhatsappModelResolver::business_account()
            ->where('whatsapp_business_id', $wabaId)
            ->first();

        if (!$businessAccount) {
            Log::channel('whatsapp')->error('❌ [ACCOUNT] Business account not found for app install', [
                'waba_id' => $wabaId
            ]);
            return;
        }

        // Actualizar el estado de la cuenta de negocio
        $businessAccount->update([
            'status' => 'active',
            'partner_app_id' => $partnerAppId,
            'disconnected_at' => null,
            'fully_removed_at' => null,
            'disconnection_reason' => null,
        ]);

        // Actualizar todos los números de teléfono asociados
        $phoneNumbers = $businessAccount->phoneNumbers ?? collect();
        $phoneNumbers->each(function ($phone) {
            $phone->update([
                'status' => 'active',
                'disconnected_at' => null,
                'fully_removed_at' => null,
                'disconnection_reason' => null,
            ]);
        });

        Log::channel('whatsapp')->info('✅ [ACCOUNT] Business account marked as active', [
            'business_account_id' => $businessAccount->whatsapp_business_id,
            'waba_id' => $wabaId,
            'phone_numbers_affected' => $phoneNumbers->count()
        ]);

        // Disparar eventos
        $this->firePartnerAppInstalled($businessAccount, $wabaInfo);
        $this->fireAccountStatusUpdated($businessAccount, 'active');
    }

    /**
     * Maneja la adición de un partner
     */
    protected function handlePartnerAdded(array $wabaInfo): void
    {
        $wabaId = $wabaInfo['waba_id'] ?? null;
        $ownerBusinessId = $wabaInfo['owner_business_id'] ?? null;

        Log::channel('whatsapp')->info('✅ [ACCOUNT] Partner added', [
            'waba_id' => $wabaId,
            'owner_business_id' => $ownerBusinessId
        ]);

        // Buscar la cuenta de WhatsApp Business afectada
        $businessAccount = WhatsappModelResolver::business_account()
            ->where('whatsapp_business_id', $wabaId)
            ->first();

        if (!$businessAccount) {
            Log::channel('whatsapp')->error('❌ [ACCOUNT] Business account not found for partner added', [
                'waba_id' => $wabaId
            ]);
            return;
        }

        // Actualizar el estado de la cuenta de negocio
        $businessAccount->update([
            'status' => 'active',
            'disconnected_at' => null,
            'fully_removed_at' => null,
            'disconnection_reason' => null,
        ]);

        // Actualizar todos los números de teléfono asociados
        $phoneNumbers = $businessAccount->phoneNumbers ?? collect();
        $phoneNumbers->each(function ($phone) {
            $phone->update([
                'status' => 'active',
                'disconnected_at' => null,
                'fully_removed_at' => null,
                'disconnection_reason' => null,
            ]);
        });

        Log::channel('whatsapp')->info('✅ [ACCOUNT] Business account marked as active after partner added', [
            'business_account_id' => $businessAccount->whatsapp_business_id,
            'waba_id' => $wabaId,
            'phone_numbers_affected' => $phoneNumbers->count()
        ]);

        // Disparar eventos
        $this->firePartnerAdded($businessAccount, $wabaInfo);
        $this->fireAccountStatusUpdated($businessAccount, 'active');
    }

    /**
     * Procesa la actualización del nombre visible de un número de teléfono.
     *
     * @param array $value Datos del webhook phone_number_name_update
     */
    protected function handlePhoneNumberNameUpdate(array $value): void
    {
        Log::channel('whatsapp')->info('📞 [PHONE_NUMBER_NAME_UPDATE] Processing name verification update', $value);

        // 1. Extraer datos del payload
        $displayPhoneNumber = $value['display_phone_number'] ?? null;
        $decision = $value['decision'] ?? null;
        $requestedName = $value['requested_verified_name'] ?? null;
        $rejectionReason = $value['rejection_reason'] ?? null;

        if (!$displayPhoneNumber || !$decision) {
            Log::channel('whatsapp')->warning('⚠️ [PHONE_NUMBER_NAME_UPDATE] Missing required fields', $value);
            return;
        }

        // 2. Buscar el número de teléfono en la base de datos por display_phone_number
        $phoneNumber = WhatsappModelResolver::phone_number()
            ->where('display_phone_number', $displayPhoneNumber)
            ->first();

        if (!$phoneNumber) {
            Log::channel('whatsapp')->warning('⚠️ [PHONE_NUMBER_NAME_UPDATE] Phone number not found', [
                'display_phone_number' => $displayPhoneNumber
            ]);
            return;
        }

        // 3. Preparar datos para actualizar
        $updateData = [
            'requested_verified_name' => $requestedName,
            'name_decision' => $decision,
            'name_rejection_reason' => $rejectionReason,
            'name_verified_at' => now(),
        ];

        // Si la decisión es APPROVED, también actualizamos el verified_name
        if ($decision === 'APPROVED' && $requestedName) {
            $updateData['verified_name'] = $requestedName;
            // Opcionalmente, actualizar name_status si lo usas
            // $updateData['name_status'] = 'APPROVED';
        }

        // 4. Actualizar el registro
        $phoneNumber->update($updateData);

        Log::channel('whatsapp')->info('✅ [PHONE_NUMBER_NAME_UPDATE] Phone number name verification updated', [
            'phone_number_id' => $phoneNumber->phone_number_id,
            'display_phone_number' => $displayPhoneNumber,
            'decision' => $decision,
        ]);

        // 5. Disparar evento (opcional pero recomendado)
        $this->firePhoneNumberNameUpdated($phoneNumber, $value);
    }

    /**
     * Maneja la desinstalación de la aplicación partner
     */
    protected function handlePartnerAppUninstalled(array $wabaInfo): void
    {
        $wabaId = $wabaInfo['waba_id'] ?? null;
        $ownerBusinessId = $wabaInfo['owner_business_id'] ?? null;
        $partnerAppId = $wabaInfo['partner_app_id'] ?? null;

        Log::channel('whatsapp')->warning('🚫 [ACCOUNT] Partner app uninstalled', [
            'waba_id' => $wabaId,
            'owner_business_id' => $ownerBusinessId,
            'partner_app_id' => $partnerAppId
        ]);

        // Buscar la cuenta de WhatsApp Business afectada
        $businessAccount = WhatsappModelResolver::business_account()
            ->where('whatsapp_business_id', $wabaId)
            ->first();

        if (!$businessAccount) {
            Log::channel('whatsapp')->error('❌ [ACCOUNT] Business account not found for uninstall', [
                'waba_id' => $wabaId
            ]);
            return;
        }

        // Actualizar el estado de la cuenta de negocio
        $businessAccount->update([
            'status' => 'disconnected',
            'partner_app_id' => null,
            'disconnected_at' => now(),
            'disconnection_reason' => 'PARTNER_APP_UNINSTALLED',
        ]);

        // Actualizar todos los números de teléfono asociados
        $phoneNumbers = $businessAccount->phoneNumbers ?? collect();
        $phoneNumbers->each(function ($phone) {
            $phone->update([
                'status' => 'disconnected',
                'disconnected_at' => now(),
                'disconnection_reason' => 'PARTNER_APP_UNINSTALLED',
            ]);
        });

        Log::channel('whatsapp')->info('✅ [ACCOUNT] Business account marked as disconnected', [
            'business_account_id' => $businessAccount->whatsapp_business_id,
            'waba_id' => $wabaId,
            'phone_numbers_affected' => $phoneNumbers->count()
        ]);

        // Disparar eventos
        $this->firePartnerAppUninstalled($businessAccount, $wabaInfo);
        $this->fireAccountStatusUpdated($businessAccount, 'disconnected');
    }


    /**
     * Maneja cuando un negocio desconecta la cuenta
     */
    protected function handlePartnerRemoved(array $wabaInfo): void
    {
        $wabaId = $wabaInfo['waba_id'] ?? null;
        $ownerBusinessId = $wabaInfo['owner_business_id'] ?? null;

        Log::channel('whatsapp')->warning('🗑️ [ACCOUNT] Partner completely removed', [
            'waba_id' => $wabaId,
            'owner_business_id' => $ownerBusinessId
        ]);

        // Buscar la cuenta de WhatsApp Business afectada
        $businessAccount = WhatsappModelResolver::business_account()
            ->where('whatsapp_business_id', $wabaId)
            ->first();

        if (!$businessAccount) {
            Log::channel('whatsapp')->error('❌ [ACCOUNT] Business account not found for removal', [
                'waba_id' => $wabaId
            ]);
            return;
        }

        // Marcar la cuenta como completamente removida
        $businessAccount->update([
            'status' => 'removed',
            'partner_app_id' => null,
            'disconnected_at' => now(),
            'fully_removed_at' => now(),
            'disconnection_reason' => 'PARTNER_REMOVED',
        ]);

        // Actualizar todos los números de teléfono asociados
        $phoneNumbers = $businessAccount->phoneNumbers ?? collect();
        $phoneNumbers->each(function ($phone) {
            $phone->update([
                'status' => 'removed',
                'disconnected_at' => now(),
                'fully_removed_at' => now(),
                'disconnection_reason' => 'PARTNER_REMOVED',
            ]);
        });

        Log::channel('whatsapp')->info('✅ [ACCOUNT] Business account marked as removed', [
            'business_account_id' => $businessAccount->whatsapp_business_id,
            'waba_id' => $wabaId,
            'phone_numbers_affected' => $phoneNumbers->count()
        ]);

        // Disparar eventos
        $this->firePartnerRemoved($businessAccount, $wabaInfo);
        $this->fireAccountStatusUpdated($businessAccount, 'removed');
    }

    /**
     * Procesar mensajes multimedia de ecos SMB (similar a processMediaMessage del padre)
     */
    protected function processSmbMediaMessageEcho(array $echo, Model $contactRecord, Model $whatsappPhone, string $messageType): void
    {
        try {
            $mediaId = $echo[$messageType]['id'] ?? null;
            $caption = $echo[$messageType]['caption'] ?? strtoupper($messageType);
            $mimeType = $echo[$messageType]['mime_type'] ?? null;

            Log::channel('whatsapp')->info('📎 [COEXISTENCE] Procesando archivo multimedia SMB echo', [
                'media_id' => $mediaId,
                'type' => $messageType,
                'mime_type' => $mimeType
            ]);

            if (!$mediaId) {
                Log::channel('whatsapp')->warning('⚠️ [COEXISTENCE] No media ID found in SMB echo', [
                    'message_type' => $messageType
                ]);
                return;
            }

            // Obtener URL del archivo multimedia
            $mediaUrl = $this->getMediaUrl($mediaId, $whatsappPhone);

            if (!$mediaUrl) {
                Log::channel('whatsapp')->error('❌ [COEXISTENCE] Failed to retrieve media URL', [
                    'media_id' => $mediaId
                ]);
                return;
            }

            // Descargar contenido del archivo
            $mediaContent = $this->downloadMedia($mediaUrl, $whatsappPhone);

            if (!$mediaContent) {
                Log::channel('whatsapp')->error('❌ [COEXISTENCE] Failed to download media content', [
                    'media_url' => $mediaUrl
                ]);
                return;
            }

            // Obtener el tipo de media pluralizado según la configuración
            $mediaType = $messageType . 's'; // Por defecto pluralizar el tipo de media

            // Obtener la ruta de almacenamiento configurada desde la config
            $directory = config("whatsapp.media.storage_path.$mediaType");

            if (!$directory) {
                throw new \RuntimeException("No se ha configurado una ruta de almacenamiento para el tipo de media: $mediaType");
            }

            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            $extension = $this->getFileExtension($mimeType);

            // Si es un archivo de audio y tiene extensión .bin, forzar a .ogg
            if ($messageType === 'audio' && $extension === 'bin') {
                $extension = 'ogg';
            }

            if ($messageType === 'sticker' && $extension === 'bin') {
                $extension = 'webp';
            }

            $fileName = "{$mediaId}.{$extension}";
            if (Str::endsWith($directory, '/')) {
                $directory = rtrim($directory, '/');
            }
            $filePath = "{$directory}/{$fileName}";
            file_put_contents($filePath, $mediaContent);

            // Convertir el path absoluto a relativo para Storage::url
            $relativePath = str_replace(storage_path('app/public/'), '', $directory . '/' . $fileName);

            // Obtener la URL pública
            $publicPath = Storage::url($relativePath);

            Log::channel('whatsapp')->info('💾 [COEXISTENCE] Archivo multimedia guardado', [
                'file_path' => $filePath,
                'public_path' => $publicPath,
                'file_size' => strlen($mediaContent)
            ]);

            // Preparar datos del mensaje
            $messageData = [
                'wa_id' => $echo['id'],
                'whatsapp_phone_id' => $whatsappPhone->phone_number_id,
                'contact_id' => $contactRecord->contact_id,
                'conversation_id' => null,
                'messaging_product' => 'whatsapp',
                'message_method' => 'OUTPUT', // Los ecos son mensajes SALIENTES desde el móvil
                'message_from' => preg_replace('/[\D+]/', '', $echo['from']),
                'message_to' => preg_replace('/[\D+]/', '', $echo['to']),
                'message_type' => strtoupper($messageType),
                'message_content' => $caption,
                'json_content' => json_encode($echo),
                'status' => 'sent', // Asumir enviado para ecos
                'message_context_id' => $this->getContextMessageId($echo),
                'is_smb_echo' => true, // Marcar como eco de SMB
            ];

            // Crear registro del mensaje
            $messageRecord = WhatsappModelResolver::message()->firstOrCreate(
                ['wa_id' => $messageData['wa_id']],
                $messageData
            );

            Log::channel('whatsapp')->info('✅ [COEXISTENCE] Mensaje multimedia SMB echo guardado', [
                'message_id' => $messageRecord->message_id,
                'type' => $messageType
            ]);

            // Crear o actualizar el registro del archivo multimedia en la base de datos
            $mediaFileRecord = WhatsappModelResolver::media_file()->updateOrCreate(
                [
                    'message_id' => $messageRecord->message_id,
                    'media_id' => $mediaId,
                ],
                [
                    'media_type' => $messageType,
                    'file_name' => $fileName,
                    'url' => $publicPath,
                    'mime_type' => $mimeType,
                    'sha256' => $echo[$messageType]['sha256'] ?? null,
                    'file_size' => strlen($mediaContent),
                ]
            );

            Log::channel('whatsapp')->info('✅ [COEXISTENCE] Registro de archivo multimedia creado', [
                'media_file_id' => $mediaFileRecord->media_file_id ?? $mediaFileRecord->id,
                'message_id' => $messageRecord->message_id,
                'url' => $publicPath
            ]);

            // Cargar relación para el evento
            $messageRecord->loadMissing(['mediaFiles']);

            // Disparar evento
            $this->fireSmbMessageEcho($contactRecord, $messageRecord);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('❌ [COEXISTENCE] Error al procesar archivo multimedia SMB echo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'message_type' => $messageType
            ]);
            // No lanzar excepción para no romper el proceso completo
        }
    }

    /**
     * Procesar mensajes no multimedia de ecos SMB
     */
    protected function processSmbNonMediaMessageEcho(array $echo, Model $contactRecord, Model $whatsappPhone, string $messageType): void
    {
        try {
            // Preparar datos del mensaje
            $messageData = [
                'wa_id' => $echo['id'],
                'whatsapp_phone_id' => $whatsappPhone->phone_number_id,
                'contact_id' => $contactRecord->contact_id,
                'conversation_id' => null,
                'messaging_product' => 'whatsapp',
                'message_method' => 'OUTPUT', // Los ecos son mensajes SALIENTES desde el móvil
                'message_from' => preg_replace('/[\D+]/', '', $echo['from']),
                'message_to' => preg_replace('/[\D+]/', '', $echo['to']),
                'message_type' => strtoupper($messageType),
                'json_content' => json_encode($echo),
                'status' => 'sent', // Asumir enviado para ecos
                'message_context_id' => $this->getContextMessageId($echo),
                'is_smb_echo' => true, // Marcar como eco de SMB
            ];

            // Procesar contenido según el tipo
            switch ($messageType) {
                case 'text':
                    $messageData['message_content'] = $echo['text']['body'] ?? '';
                    break;

                case 'interactive':
                    $messageData['message_content'] = $echo['interactive']['button_reply']['title']
                        ?? $echo['interactive']['list_reply']['title']
                        ?? 'Interactive message';
                    break;

                case 'location':
                    $messageData['message_content'] = "Location: " . ($echo['location']['name'] ?? '');
                    break;

                case 'contacts':
                    $messageData['message_content'] = "Shared contacts: " . count($echo['contacts'] ?? []);
                    break;

                case 'reaction':
                    $messageData['message_content'] = $echo['reaction']['emoji'] ?? null;
                    break;

                case 'unsupported':
                    $messageData = $this->processUnsupportedSmbEcho($echo, $messageData);
                    break;

                default:
                    $messageData['message_content'] = $this->getMessageContentForType($messageType, $echo)
                        ?? 'SMB echo message';
            }

            Log::channel('whatsapp')->info('💾 [COEXISTENCE] Guardando mensaje SMB echo no multimedia', [
                'wa_id' => $messageData['wa_id'],
                'type' => $messageData['message_type'],
                'content_preview' => substr($messageData['message_content'], 0, 50)
            ]);

            $messageRecord = WhatsappModelResolver::message()->firstOrCreate(
                ['wa_id' => $messageData['wa_id']],
                $messageData
            );

            Log::channel('whatsapp')->info('✅ [COEXISTENCE] SMB message echo guardado exitosamente', [
                'message_id' => $messageRecord->message_id,
                'wa_id' => $messageRecord->wa_id,
                'type' => $messageType,
                'is_smb_echo' => $messageRecord->is_smb_echo
            ]);

            // Disparar evento
            if ($messageType === 'unsupported') {
                $this->fireUnsupportedSmbMessageEcho($contactRecord, $messageRecord, $echo);
            } else {
                $this->fireSmbMessageEcho($contactRecord, $messageRecord);
            }

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('❌ [COEXISTENCE] Error al guardar mensaje SMB echo no multimedia', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'message_data' => $messageData ?? []
            ]);
        }
    }

    /**
     * Procesar mensajes no soportados en ecos SMB
     */
    protected function processUnsupportedSmbEcho(array $echo, array $messageData): array
    {
        $errorCode = $echo['errors'][0]['code'] ?? null;
        $errorTitle = $echo['errors'][0]['title'] ?? 'Unsupported content';
        $errorMessage = $echo['errors'][0]['message'] ?? 'Unknown error';
        $errorDetails = $echo['errors'][0]['error_data']['details'] ?? 'No additional details available';

        $content = "Unsupported message. Error: $errorCode - $errorTitle: $errorDetails";

        // Actualizar los datos del mensaje con información del error
        $messageData['message_content'] = $content;
        $messageData['code_error'] = $errorCode;
        $messageData['title_error'] = $errorTitle;
        $messageData['message_error'] = $errorMessage;
        $messageData['details_error'] = $errorDetails;

        Log::channel('whatsapp')->warning('⚠️ [COEXISTENCE] Mensaje no soportado en eco SMB', [
            'wa_id' => $echo['id'],
            'error_code' => $errorCode,
            'error_title' => $errorTitle,
            'error_details' => $errorDetails
        ]);

        return $messageData;
    }

    // =========================================================================
    // CUSTOM HOOKS - Personaliza estos métodos según tus necesidades
    // =========================================================================

    /**
     * Hook: Después de sincronizar el historial completo
     */
    protected function afterHistorySync(array $data): void
    {
        // Ejemplo: Notificar al administrador que el historial ha sido sincronizado
        // Ejemplo: Actualizar estadísticas de mensajes históricos
        // Ejemplo: Indexar mensajes históricos para búsqueda

        $phoneNumberId = $data['metadata']['phone_number_id'] ?? null;

        if ($phoneNumberId) {
            // Puedes disparar un evento personalizado
            // event(new HistorySyncCompletedEvent($phoneNumberId));

            Log::channel('whatsapp')->info('📊 [COEXISTENCE] Post-procesamiento de historial completado', [
                'phone_number_id' => $phoneNumberId
            ]);
        }
    }

    /**
     * Hook: Después de sincronizar contactos
     */
    protected function afterContactsSync(array $data): void
    {
        // Ejemplo: Sincronizar con CRM externo
        // Ejemplo: Actualizar segmentación de contactos
        // Ejemplo: Enviar notificaciones de nuevos contactos

        $contactsCount = count($data['state_sync'] ?? []);

        Log::channel('whatsapp')->info('📊 [COEXISTENCE] Post-procesamiento de contactos completado', [
            'contacts_synced' => $contactsCount
        ]);
    }

    /**
     * Hook: Después de procesar ecos de mensajes
     */
    protected function afterSmbMessageEchoes(array $data): void
    {
        // Ejemplo: Actualizar UI en tiempo real vía WebSockets
        // Ejemplo: Enviar notificaciones push
        // Ejemplo: Actualizar métricas de mensajes enviados

        $messagesCount = count($data['message_echoes'] ?? []);

        // Ejemplo de broadcast en tiempo real
        foreach ($data['message_echoes'] ?? [] as $echo) {
            // broadcast(new MessageSentFromMobileEvent($echo));
        }

        Log::channel('whatsapp')->info('📊 [COEXISTENCE] Post-procesamiento de ecos completado', [
            'messages_echoed' => $messagesCount
        ]);
    }

    /**
     * Hook: Antes de procesar mensaje histórico individual
     */
    protected function beforeProcessHistoryMessage(array $message, Model $contact, Model $whatsappPhone, int $phase): void
    {
        // Lógica personalizada antes de guardar mensaje histórico
        // Ejemplo: Validar duplicados, filtrar por tipo, etc.
    }

    /**
     * Hook: Después de procesar mensaje histórico individual
     */
    protected function afterProcessHistoryMessage(array $message, Model $contact, Model $whatsappPhone, int $phase): void
    {
        // Lógica personalizada después de guardar mensaje histórico
        // Ejemplo: Indexar mensaje, actualizar estadísticas, etc.
    }

    /**
     * Hook: Validar si se debe sincronizar un contacto
     */
    protected function shouldSyncContact(array $syncItem): bool
    {
        // Lógica de validación personalizada
        // Ejemplo: Ignorar contactos de prueba, números bloqueados, etc.

        $phoneNumber = $syncItem['contact']['phone_number'] ?? '';

        // Ejemplo: No sincronizar números de prueba
        if (str_starts_with($phoneNumber, '1234567890')) {
            return false;
        }

        return true;
    }

    /**
     * Hook: Después de sincronizar un contacto individual
     */
    protected function afterProcessContactSync(array $syncItem, Model $whatsappPhone): void
    {
        // Lógica personalizada después de sincronizar contacto
        // Ejemplo: Actualizar tags, categorías, asignar agentes, etc.

        $action = $syncItem['action'] ?? '';
        $contactData = $syncItem['contact'] ?? [];

        if ($action === 'create') {
            // Nuevo contacto creado desde la app móvil
            Log::channel('whatsapp')->debug('📱 Nuevo contacto desde móvil', $contactData);
        }
    }

    /**
     * Hook: Antes de procesar eco de mensaje
     */
    protected function beforeProcessSmbMessageEcho(array $echo, Model $whatsappPhone): void
    {
        // Lógica personalizada antes de guardar eco de mensaje
        // Ejemplo: Validar que el mensaje no sea duplicado, filtrar spam, etc.
    }

    /**
     * Hook: Después de procesar eco de mensaje
     */
    protected function afterProcessSmbMessageEcho(array $echo, Model $whatsappPhone): void
    {
        // Lógica personalizada después de guardar eco de mensaje
        // Ejemplo: Actualizar interfaz en tiempo real, notificaciones, etc.

        $messageType = $echo['type'] ?? '';
        $to = $echo['to'] ?? '';

        Log::channel('whatsapp')->debug('📤 Mensaje enviado desde móvil', [
            'type' => $messageType,
            'to' => $to
        ]);

        // Ejemplo: Broadcast en tiempo real para actualizar UI
        // broadcast(new SmbMessageSentEvent([
        //     'message' => $echo,
        //     'phone_number_id' => $whatsappPhone->phone_number_id
        // ]));
    }

    /**
     * Hook para resolver la ruta de la clave privada RSA de los Flows.
     * El proyecto puede sobreescribir este método para multi-tenancy.
     * Retornar null usa el comportamiento legacy/default.
     */
    protected function resolvePrivateKeyPath(Request $request): ?string
    {
        return null;
    }

    /**
     * Maneja el Data Channel de los Flows (Endpoint encriptado)
     */
    protected function handleFlowEndpointRequest(Request $request): Response
    {
        try {
            $cryptoService = app(FlowCryptoService::class);

            $privateKeyPath = $this->resolvePrivateKeyPath($request);
            if ($privateKeyPath) {
                $cryptoService->loadFromPath($privateKeyPath);
            }

            // Desencriptar
            $decryptedBody = $cryptoService->decryptRequest(
                $request->input('encrypted_aes_key'),
                $request->input('encrypted_flow_data'),
                $request->input('initial_vector')
            );

            Log::channel('whatsapp')->info('Flow Endpoint Decrypted Request:', $decryptedBody);

            // 1. Manejar Health Check (Ping)
            if (($decryptedBody['action'] ?? '') === 'ping') {
                $responseData = ['data' => ['status' => 'active']];
            } else {
                // 2. Ejecutar lógica del desarrollador (Hook personalizable)
                $responseData = $this->processFlowDataExchange($decryptedBody);
            }

            // Encriptar respuesta
            $encryptedResponse = $cryptoService->encryptResponse(
                $responseData,
                $request->input('encrypted_aes_key'),
                $request->input('initial_vector')
            );

            return response($encryptedResponse);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Flow Crypto Error: ' . $e->getMessage());
            return response('Decryption failed', 421);
        }
    }

    /**
     * Maneja cambios de estado (PUBLISHED, BLOCKED) y errores de latencia
     */
    protected function handleFlowStatusAndPerformance(array $value): void
    {
        $flowId = $value['flow_id'] ?? null;
        $event = $value['event'] ?? null;

        Log::channel('whatsapp')->info("Flow Webhook Event: {$event} for Flow: {$flowId}", $value);

        // Sincronización con base de datos
        $flowRecord = WhatsappModelResolver::flow()->where('wa_flow_id', $flowId)->first();
        if ($flowRecord && isset($value['new_status'])) {
            $flowRecord->update(['status' => $value['new_status']]);
        }

        // Disparar el evento usando la configuración
        $this->fireFlowStatusUpdated($value);
    }


    /**
     * Procesa el JSON final que envía el Flow al terminar (nfm_reply).
     *
     * El nfm_reply contiene response_json con los campos del formulario.
     * Para photo_picker / document_picker, cada ítem tiene:
     *   { file_name, mime_type, sha256, id }
     * El "id" es el media_id — se llama a Meta Graph API para obtener
     * cdn_url y encryption_metadata, luego se descarga y desencripta.
     */
    protected function handleFlowResponseMessage(array $message, ?array $contact, ?array $metadata): void
    {
        // 1. Extraer y decodificar el response_json del Flow
        $responseJson    = data_get($message, 'interactive.nfm_reply.response_json');
        $decodedResponse = is_string($responseJson) ? json_decode($responseJson, true) : ($responseJson ?? []);

        if (! is_array($decodedResponse)) {
            $decodedResponse = [];
        }

        Log::channel('whatsapp')->info('Flow Response Received', [
            'flow_token' => $decodedResponse['flow_token'] ?? 'N/A',
            'data'       => $decodedResponse,
        ]);

        // 2. Resolver el número de teléfono de WhatsApp (necesario para llamar a la API de Meta
        //    al momento de descargar media). Meta identifica al REMITENTE mediante BSUID o wa_id,
        //    pero el número de teléfono del negocio siempre viene en metadata.phone_number_id.
        $apiPhoneNumberId = $metadata['phone_number_id'] ?? null;
        $whatsappPhone    = null;

        if ($apiPhoneNumberId) {
            $whatsappPhone = WhatsappModelResolver::phone_number()
                ->where('api_phone_number_id', $apiPhoneNumberId)
                ->first();
        }

        if (! $whatsappPhone) {
            Log::channel('whatsapp')->warning('Flow Response: no se pudo resolver WhatsappPhoneNumber; el procesamiento de media será omitido.', [
                'api_phone_number_id' => $apiPhoneNumberId,
                'flow_token'          => $decodedResponse['flow_token'] ?? 'N/A',
            ]);
        }

        // 3. Procesar archivos multimedia
        // Meta envía dos estructuras posibles según el modo del Flow:
        //
        //   a) nfm_reply (este caso): photo_picker/document_picker son arrays de
        //      { file_name, mime_type, sha256, id } — requiere llamar a Meta API con el id
        //
        //   b) data_exchange endpoint: cada ítem ya trae cdn_url + encryption_metadata inline
        //
        $mediaService   = app(FlowMediaService::class);
        $processedFiles = [];

        // Campos estándar de media pickers — también detectamos dinámicamente
        $mediaPickerKeys = ['photo_picker', 'document_picker'];

        foreach ($decodedResponse as $fieldKey => $fieldValue) {
            if (! is_array($fieldValue) || empty($fieldValue)) {
                continue;
            }

            // Caso (a): nfm_reply — items con { id, file_name, mime_type, sha256 }
            $isNfmReplyMedia = in_array($fieldKey, $mediaPickerKeys, true)
                || (isset($fieldValue[0]) && is_array($fieldValue[0]) && isset($fieldValue[0]['id'], $fieldValue[0]['file_name']));

            if ($isNfmReplyMedia && $whatsappPhone) {
                $processedItems = [];
                foreach ($fieldValue as $mediaItem) {
                    if (! isset($mediaItem['id'])) {
                        continue;
                    }
                    try {
                        $fileInfo = $mediaService->processFlowMedia($mediaItem, $whatsappPhone, 'flows');
                        $processedItems[]                       = $fileInfo;
                        $processedFiles[]                       = array_merge($fileInfo, ['field' => $fieldKey]);
                        Log::channel('whatsapp')->info("Flow Media Procesado [{$fieldKey}]", $fileInfo);
                    } catch (\Exception $e) {
                        Log::channel('whatsapp')->error(
                            "Error procesando media de Flow [{$fieldKey}][id={$mediaItem['id']}]: " . $e->getMessage()
                        );
                    }
                }
                // Inyectamos los archivos procesados bajo la clave "{field}_files"
                if (! empty($processedItems)) {
                    $decodedResponse[$fieldKey . '_files'] = $processedItems;
                }
                continue;
            }

            // Caso (b): data_exchange — ítem único con cdn_url + encryption_metadata
            if (isset($fieldValue['cdn_url'], $fieldValue['encryption_metadata'])) {
                try {
                    $fileInfo = $mediaService->processInlineMedia($fieldValue, 'flows');
                    $decodedResponse[$fieldKey . '_file']   = $fileInfo;
                    $processedFiles[]                       = array_merge($fileInfo, ['field' => $fieldKey]);
                    Log::channel('whatsapp')->info("Flow Media Inline Procesado [{$fieldKey}]", $fileInfo);
                } catch (\Exception $e) {
                    Log::channel('whatsapp')->error(
                        "Error procesando media inline de Flow [{$fieldKey}]: " . $e->getMessage()
                    );
                }
                continue;
            }
        }

        // 4. Registrar como mensaje recibido en la base de datos
        $this->handleIncomingMessage($message, $contact, $metadata);

        // 4.5 — Recolección de datos del Flow (persistencia de sesión y respuestas)
        // Este bloque NO lanza excepciones — cualquier fallo solo se loguea.
        if (config('whatsapp.flows.collect_responses', true)) {
            try {
                $flowToken = $decodedResponse['flow_token'] ?? null;

                if ($flowToken) {
                    /** @var \ScriptDevelop\WhatsappManager\Services\Flows\FlowSessionService $flowSessionService */
                    $flowSessionService = app(\ScriptDevelop\WhatsappManager\Services\Flows\FlowSessionService::class);

                    /** @var \ScriptDevelop\WhatsappManager\Services\Flows\FlowResponseService $flowResponseService */
                    $flowResponseService = app(\ScriptDevelop\WhatsappManager\Services\Flows\FlowResponseService::class);

                    /** @var \ScriptDevelop\WhatsappManager\Services\Flows\FlowActionDispatcher $flowActionDispatcher */
                    $flowActionDispatcher = app(\ScriptDevelop\WhatsappManager\Services\Flows\FlowActionDispatcher::class);

                    // Resolver contacto BD si tenemos wa_id
                    $resolvedContact = null;
                    $waId = $contact['wa_id'] ?? null;
                    if ($waId) {
                        $resolvedContact = WhatsappModelResolver::contact()
                            ->where('wa_id', $waId)
                            ->first();
                    }

                    // Crear o recuperar sesión
                    $session = $flowSessionService->findOrCreateSession(
                        flowToken:   $flowToken,
                        waFlowId:    null,  // nfm_reply no incluye wa_flow_id directamente
                        phoneNumber: $whatsappPhone,
                        contact:     $resolvedContact,
                        sendMethod:  'organic',
                        isOrganic:   true
                    );

                    // Persistir campos del formulario
                    $flowResponseService->saveFromNfmReply(
                        $session,
                        $decodedResponse,
                        $whatsappPhone,
                        $resolvedContact
                    );

                    // Marcar sesión como completada
                    $flowSessionService->completeSession($session, $decodedResponse);

                    // Ejecutar acciones configuradas post-completado
                    $flowActionDispatcher->dispatch($session, 'on_complete', $decodedResponse);
                }
            } catch (\Throwable $e) {
                Log::channel('whatsapp')->error('FlowDataCollection: error al persistir datos del flow', [
                    'flow_token' => $decodedResponse['flow_token'] ?? 'N/A',
                    'error'      => $e->getMessage(),
                    'file'       => $e->getFile(),
                    'line'       => $e->getLine(),
                ]);
            }
        }

        // 5. Disparar evento de finalización de Flow
        $eventClass = config('whatsapp.events.messages.interactive.received');

        if ($eventClass && class_exists($eventClass)) {
            event(new $eventClass([
                'contact'            => $contact,
                'message_id'         => $message['id'] ?? null,
                'flow_data'          => $decodedResponse,  // Incluye claves _files / _file con info local
                'files'              => $processedFiles,   // Lista plana de todos los archivos procesados
                'is_flow_completion' => true,
            ]));
        }
    }

    /**
     * Enruta las peticiones del Data Exchange al handler configurado para el flow.
     * Delega a FlowEndpointRouter que resuelve el handler según config (auto/webhook/class).
     * Si el flow no tiene config o no tiene endpoint habilitado, retorna una respuesta genérica.
     */
    protected function processFlowDataExchange(array $decryptedData): array
    {
        try {
            return app(\ScriptDevelop\WhatsappManager\Services\Flows\FlowEndpointRouter::class)
                ->route($decryptedData);
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('FlowEndpointRouter: error en data exchange', [
                'action' => $decryptedData['action'] ?? 'unknown',
                'error'  => $e->getMessage(),
                'file'   => $e->getFile(),
                'line'   => $e->getLine(),
            ]);
            return [
                'version' => config('whatsapp.flows.data_api_version', '3.0'),
                'data'    => ['error' => 'internal_error'],
            ];
        }
    }

    /**
     * Dispara evento para mensajes eco de SMB
     */
    protected function fireSmbMessageEcho(Model $contactRecord, Model $messageRecord): void
    {
        // Disparar el evento de mensaje recibido estándar
        $this->fireMessageReceived($contactRecord, $messageRecord);

        Log::channel('whatsapp')->debug('🎉 [COEXISTENCE] Evento SMB message echo disparado', [
            'message_id' => $messageRecord->message_id,
            'contact_id' => $contactRecord->contact_id
        ]);
    }

    /**
     * Dispara evento para sincronización de historial
     */
    protected function fireCoexistenceHistorySynced(array $data): void
    {
        $event = config('whatsapp.events.coexistence.history_synced');
        event(new $event($data));
    }

    /**
     * Disparar evento para mensajes no soportados en ecos SMB
     */
    protected function fireUnsupportedSmbMessageEcho(Model $contactRecord, Model $messageRecord, array $echo): void
    {
        $firstError = $echo['errors'][0] ?? null;

        if (is_string($firstError)) {
            $errorCode = null;
            $errorTitle = 'Unsupported content';
            $errorMessage = $firstError;
            $errorDetails = $firstError;
        } elseif (is_array($firstError)) {
            $errorCode = $firstError['code'] ?? null;
            $errorTitle = $firstError['title'] ?? 'Unsupported content';
            $errorMessage = $firstError['message'] ?? 'Unknown error';
            $errorDetails = $firstError['error_data']['details'] ?? 'No additional details available';
        } else {
            $errorCode = null;
            $errorTitle = 'Unsupported content';
            $errorMessage = 'Unknown error';
            $errorDetails = 'No additional details available';
        }

        // Primero disparar el evento estándar de SMB echo
        $this->fireSmbMessageEcho($contactRecord, $messageRecord);

        // Luego disparar evento específico para mensajes no soportados
        $event = config('whatsapp.events.messages.unsupported.received');
        if ($event) {
            event(new $event([
                'contact' => $contactRecord,
                'message' => $messageRecord,
                'title_error' => $errorTitle,
                'message_error' => $errorMessage,
                'details_error' => $errorDetails,
                'is_smb_echo' => true,
            ]));
        }

        Log::channel('whatsapp')->debug('🎉 [COEXISTENCE] Evento unsupported SMB message echo disparado', [
            'message_id' => $messageRecord->message_id,
            'contact_id' => $contactRecord->contact_id,
            'error_code' => $errorCode
        ]);
    }

    /**
     * Dispara evento para sincronización de contactos
     */
    protected function fireCoexistenceContactSynced(array $data): void
    {
        $event = config('whatsapp.events.coexistence.contact_synced');
        event(new $event($data));
    }

    /**
     * Dispara evento para ecos de mensajes SMB
     */
    protected function fireCoexistenceSmbMessageEcho(array $data): void
    {
        $event = config('whatsapp.events.coexistence.smb_message_echo');
        event(new $event($data));
    }

    /**
     * Dispara evento para actualizaciones de cuenta
     */
    protected function fireCoexistenceAccountUpdated(array $data): void
    {
        $event = config('whatsapp.events.coexistence.account_updated');
        event(new $event($data));
    }

    /**
     * Dispara evento para actualizaciones de cuenta genéricas
     */
    protected function fireAccountUpdate(array $data): void
    {
        $event = config('whatsapp.events.account.update');
        if ($event) {
            event(new $event($data));

            Log::channel('whatsapp')->debug('🎉 [ACCOUNT] Account update event fired', [
                'event' => $data['event'] ?? 'unknown'
            ]);
        }
    }

    /**
     * Dispara evento para desinstalación de app partner
     */
    protected function firePartnerAppUninstalled(Model $businessAccount, array $wabaInfo): void
    {
        $event = config('whatsapp.events.partner.app_uninstalled');
        if ($event) {
            $eventData = [
                'business_account' => $businessAccount,
                'waba_info' => $wabaInfo,
                'timestamp' => now(),
                'event_type' => 'PARTNER_APP_UNINSTALLED'
            ];

            event(new $event($eventData));

            Log::channel('whatsapp')->info('🎉 [ACCOUNT] Partner app uninstalled event fired', [
                'business_account_id' => $businessAccount->whatsapp_business_id,
                'waba_id' => $wabaInfo['waba_id'] ?? null
            ]);
        }
    }

    /**
     * Dispara evento para remoción completa del partner
     */
    protected function firePartnerRemoved(Model $businessAccount, array $wabaInfo): void
    {
        $event = config('whatsapp.events.partner.partner_removed');
        if ($event) {
            $eventData = [
                'business_account' => $businessAccount,
                'waba_info' => $wabaInfo,
                'timestamp' => now(),
                'event_type' => 'PARTNER_REMOVED'
            ];

            event(new $event($eventData));

            Log::channel('whatsapp')->info('🎉 [ACCOUNT] Partner removed event fired', [
                'business_account_id' => $businessAccount->whatsapp_business_id,
                'waba_id' => $wabaInfo['waba_id'] ?? null
            ]);
        }
    }

    /**
     * Dispara evento para actualización de estado de cuenta
     */
    protected function fireAccountStatusUpdated(Model $businessAccount, string $status): void
    {
        $event = config('whatsapp.events.account.status_updated');
        if ($event) {
            $eventData = [
                'business_account' => $businessAccount,
                'new_status' => $status,
                'timestamp' => now(),
                'event_type' => 'ACCOUNT_STATUS_UPDATED'
            ];

            event(new $event($eventData));

            Log::channel('whatsapp')->debug('🔔 [ACCOUNT] Account status updated event fired', [
                'business_account_id' => $businessAccount->whatsapp_business_id,
                'status' => $status
            ]);
        }
    }

    /**
     * Dispara evento para instalación de app partner
     */
    protected function firePartnerAppInstalled(Model $businessAccount, array $wabaInfo): void
    {
        $event = config('whatsapp.events.partner.app_installed');
        if ($event) {
            $eventData = [
                'business_account' => $businessAccount,
                'waba_info' => $wabaInfo,
                'timestamp' => now(),
                'event_type' => 'PARTNER_APP_INSTALLED'
            ];

            event(new $event($eventData));

            Log::channel('whatsapp')->info('🎉 [ACCOUNT] Partner app installed event fired', [
                'business_account_id' => $businessAccount->whatsapp_business_id,
                'waba_id' => $wabaInfo['waba_id'] ?? null
            ]);
        }
    }

    /**
     * Dispara evento para adición de partner
     */
    protected function firePartnerAdded(Model $businessAccount, array $wabaInfo): void
    {
        $event = config('whatsapp.events.partner.partner_added');
        if ($event) {
            $eventData = [
                'business_account' => $businessAccount,
                'waba_info' => $wabaInfo,
                'timestamp' => now(),
                'event_type' => 'PARTNER_ADDED'
            ];

            event(new $event($eventData));

            Log::channel('whatsapp')->info('🎉 [ACCOUNT] Partner added event fired', [
                'business_account_id' => $businessAccount->whatsapp_business_id,
                'waba_id' => $wabaInfo['waba_id'] ?? null
            ]);
        }
    }

    protected function fireMessageEdited(Model $originalMessage, Model $editMessage): void
    {
        $event = config('whatsapp.events.messages.message.edited');
        if ($event) {
            event(new $event([
                'original_message' => $originalMessage,
                'edit_message' => $editMessage,
            ]));
        }
    }

    protected function fireMessageRevoked(Model $originalMessage, Model $revokeMessage): void
    {
        $event = config('whatsapp.events.messages.message.revoked');
        if ($event) {
            event(new $event([
                'original_message' => $originalMessage,
                'revoke_message' => $revokeMessage,
            ]));
        }
    }

    protected function firePhoneNumberNameUpdated(Model $phoneNumber, array $payload): void
    {
        $event = config('whatsapp.events.phone_number.name_updated');
        if ($event) {
            event(new $event([
                'phone_number' => $phoneNumber,
                'payload' => $payload,
            ]));
        }
    }

    protected function firePhoneNumberQualityUpdated(Model $phoneNumber, array $payload): void
    {
        $event = config('whatsapp.events.phone_number.quality_updated');
        if ($event) {
            event(new $event([
                'phone_number' => $phoneNumber,
                'payload' => $payload,
            ]));
        }
    }

    /**
     * Maneja las actualizaciones de capacidades de negocio (límites de mensajes, etc.)
     *
     * @param array $value Datos del webhook business_capability_update
     * @param array $payload Payload completo del webhook
     */
    protected function handleBusinessCapabilityUpdate(array $value, array $payload): void
    {
        Log::channel('whatsapp')->info('🔄 [BUSINESS_CAPABILITY] Processing business capability update', $value);

        // Obtener el ID de la cuenta empresarial desde el payload
        $wabaId = data_get($payload, 'entry.0.id');

        if (!$wabaId) {
            Log::channel('whatsapp')->warning('⚠️ [BUSINESS_CAPABILITY] No WABA ID found in payload', $payload);
            return;
        }

        // Buscar la cuenta empresarial
        $businessAccount = WhatsappModelResolver::business_account()->find($wabaId);

        if (!$businessAccount) {
            Log::channel('whatsapp')->warning('⚠️ [BUSINESS_CAPABILITY] Business account not found', [
                'waba_id' => $wabaId
            ]);
            return;
        }

        // Obtener el límite de mensajes (puede venir en diferentes formatos según la versión)
        $messagingLimitTier = null;
        $messagingLimitValue = null;

        // Versión 24.0 y posteriores: max_daily_conversations_per_business viene como string (TIER_2K, etc.)
        if (isset($value['max_daily_conversations_per_business'])) {
            $messagingLimitTier = $value['max_daily_conversations_per_business'];
            $messagingLimitValue = MessagingLimitHelper::convertTierToLimitValue($messagingLimitTier);
        }
        // Versión 23.0 y anteriores: max_daily_conversation_per_phone viene como número
        elseif (isset($value['max_daily_conversation_per_phone'])) {
            $limitValue = $value['max_daily_conversation_per_phone'];

            // Convertir el número a tier (el helper maneja -1 como ilimitado)
            $messagingLimitTier = MessagingLimitHelper::convertLimitValueToTier($limitValue);
            $messagingLimitValue = ($limitValue == -1) ? null : $limitValue;
        }

        // Actualizar la cuenta empresarial
        if ($messagingLimitTier !== null) {
            $businessAccount->messaging_limit_tier = $messagingLimitTier;
            $businessAccount->messaging_limit_value = $messagingLimitValue;
            $businessAccount->save();

            Log::channel('whatsapp')->info('✅ [BUSINESS_CAPABILITY] Messaging limit updated', [
                'waba_id' => $wabaId,
                'messaging_limit_tier' => $messagingLimitTier,
                'messaging_limit_value' => $messagingLimitValue,
            ]);
        } else {
            Log::channel('whatsapp')->warning('⚠️ [BUSINESS_CAPABILITY] No messaging limit found in webhook', $value);
        }
    }

    protected function fireFlowStatusUpdated(array $payload)
    {
        $event = config('whatsapp.events.flows.status_updated');

        if ($event && class_exists($event)) {
            event(new $event($payload));
        }
    }

}