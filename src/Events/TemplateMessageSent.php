<?php

namespace Scriptdevelop\WhatsappManager\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class TemplateMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public array $data;

    /**
     * Builder que envió el template — expone phone, contact,
     * templateStructure y buttonParameters al listener.
     */
    public /* readonly */ $builder;

    /**
     * @param array|object $data       Array con datos (modo legacy) o TemplateMessageBuilder (nuevo).
     * @param array|null   $payload    Payload enviado a Meta.
     * @param array|null   $response   Respuesta de la API de Meta.
     */
    public function __construct(
        array|object $data,
        ?array $payload = null,
        ?array $response = null
    ) {
        // Modo legacy: array simple sin builder
        if (is_array($data)) {
            $this->data = $data;
            return;
        }

        // Nuevo contrato: $data es el TemplateMessageBuilder
        $this->builder = $data;

        $this->data = [
            'template'      => $data->template?->name ?? null,
            'phone_number'  => $data->phoneNumber ?? null,
            'payload'       => $payload ?? [],
            'response'      => $response ?? [],
            'sent_at'       => now()->toIso8601String(),
        ];
    }

    public function broadcastOn(): Channel
    {
        $channelName = 'whatsapp-outgoing';

        return config('whatsapp.broadcast_channel_type') === 'private'
            ? new PrivateChannel($channelName)
            : new Channel($channelName);
    }

    public function broadcastAs(): string
    {
        return 'TemplateMessageSent';
    }
}
