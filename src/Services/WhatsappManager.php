<?php

namespace ScriptDevelop\WhatsappManager\Services;

use ScriptDevelop\WhatsappManager\Models\Flow;
use ScriptDevelop\WhatsappManager\Services\InteractiveButtonBuilder;
use ScriptDevelop\WhatsappManager\Services\InteractiveListBuilder;

/**
 * Clase principal para gestionar los servicios de WhatsApp.
 * Proporciona acceso a los servicios relacionados con números de teléfono, mensajes y cuentas empresariales.
 */
class WhatsappManager
{
    protected MessageDispatcherService $dispatcher;

    public function __construct(MessageDispatcherService $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }
    
    /**
     * Obtiene el servicio relacionado con los números de teléfono de WhatsApp.
     *
     * @return mixed El servicio de números de teléfono.
     */
    public function phone()
    {
        return app('whatsapp.phone');
    }

    /**
     * Obtiene el servicio relacionado con los mensajes de WhatsApp.
     *
     * @return mixed El servicio de mensajes.
     */
    public function message()
    {
        return app('whatsapp.message');
    }

    public function sendButtonMessage(string $phoneNumberId): InteractiveButtonBuilder
    {
        return new InteractiveButtonBuilder($this->dispatcher, $phoneNumberId);
    }

    public function sendListMessage(string $phoneNumberId): InteractiveListBuilder
    {
        return new InteractiveListBuilder($this->dispatcher, $phoneNumberId);
    }

    public function sendCtaUrlMessage(string $phoneNumberId): InteractiveCtaUrlBuilder
    {
        return new InteractiveCtaUrlBuilder($this->dispatcher, $phoneNumberId);
    }

    public function sendLocationRequestMessage(string $phoneNumberId): InteractiveLocationRequestBuilder
    {
        return new InteractiveLocationRequestBuilder($this->dispatcher, $phoneNumberId);
    }

    /**
     * Obtiene el servicio relacionado con las cuentas empresariales de WhatsApp.
     *
     * @return mixed El servicio de cuentas empresariales.
     */
    public function account()
    {
        return app('whatsapp.account');
    }

    public function webhook()
    {
        return app('whatsapp.service');
    }

    public function deletePhoneNumber(string $phoneNumberId): bool
    {
        return $this->phone()->deletePhoneNumber($phoneNumberId);
    }

    public function block(): BlockService
    {
        return app('whatsapp.block');
    }

    /**
     * Suscribe una aplicación a la cuenta empresarial de WhatsApp actual
     *
     * @param array $subscribedFields
     * @return array
     */
    public function subscribeApp(array $subscribedFields = null): array
    {
        return $this->account()->subscribeApp($subscribedFields);
    }

    /**
     * Obtiene las aplicaciones suscritas a la cuenta empresarial actual
     *
     * @return array
     */
    public function subscribedApps(): array
    {
        return $this->account()->subscribedApps();
    }

    /**
     * Cancela la suscripción de una aplicación de la cuenta empresarial actual
     *
     * @return array
     */
    public function unsubscribeApp(): array
    {
        return $this->account()->unsubscribeApp();
    }

    /**
     * Registra un número telefónico en la API de WhatsApp
     *
     * @param string $phoneNumberId
     * @param array $data
     * @return array
     */
    public function registerPhone(string $phoneNumberId, array $data = []): array
    {
        return $this->account()->registerPhone($phoneNumberId, $data);
    }
}