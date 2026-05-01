<?php

namespace ScriptDevelop\WhatsappManager\Listeners;

use Illuminate\Support\Facades\Log;
use ScriptDevelop\WhatsappManager\Events\TemplateMessageSent;
use ScriptDevelop\WhatsappManager\Models\WhatsappFlow;
use ScriptDevelop\WhatsappManager\Services\Flows\FlowSessionService;

class CreateFlowSessionOnTemplateSent
{
    /**
     * Fallback: crea sesión proactiva SOLO si addFlowActionData no fue
     * llamado para ese botón (buttonParameters no tiene el índice).
     *
     * El caso principal lo maneja addFlowActionData() →
     * maybeCreateProactiveFlowSession(), que genera el token único
     * y crea la sesión en el momento justo (token en template == token en sesión).
     */
    public function handle(TemplateMessageSent $event): void
    {
        if (!isset($event->builder)) {
            return;
        }

        $builder = $event->builder;
        $flowButtons = $builder->getFlowButtons();

        if (empty($flowButtons)) {
            return;
        }

        $phone   = $builder->getPhone();
        $contact = $builder->getContact();

        foreach ($flowButtons as $btn) {
            $waFlowId = $btn['flow_id'] ?? null;
            if (!$waFlowId) {
                continue;
            }

            // Si addFlowActionData ya fue llamado para este botón, la sesión
            // ya fue creada por maybeCreateProactiveFlowSession().
            $existingToken = $builder->getButtonToken($btn['index']);
            if ($existingToken && $existingToken !== 'unused') {
                continue;
            }

            // Solo llegamos aquí si addFlowActionData NO fue llamado
            // o fue llamado con placeholder. Creamos sesión de todas formas.
            $flow = WhatsappFlow::where('wa_flow_id', $waFlowId)->first();
            if (!$flow) {
                continue;
            }

            $flowToken = hash('sha256', $flow->flow_id . $builder->getRecipientPhone() . now()->timestamp);

            try {
                app(FlowSessionService::class)->createProactive(
                    flow:        $flow,
                    phoneNumber: $phone,
                    contact:     $contact,
                    sendMethod:  'template',
                    flowToken:   $flowToken
                );
            } catch (\Throwable $e) {
                Log::error('[CreateFlowSessionOnTemplateSent] Error: ' . $e->getMessage());
            }
        }
    }
}
