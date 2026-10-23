<?php

namespace ScriptDevelop\WhatsappManager\Tests\Unit;

use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use ScriptDevelop\WhatsappManager\Providers\WhatsappServiceProvider;
use ScriptDevelop\WhatsappManager\Services\WebhookProcessors\BaseWebhookProcessor;

class WebhookFallThroughTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WhatsappServiceProvider::class];
    }

    public function test_edit_revoke_and_system_messages_do_not_fall_through_to_incoming_handler(): void
    {
        $processor = $this->recordingProcessor();

        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'contacts' => [['wa_id' => '111']],
                                'metadata' => ['phone_number_id' => 'phone-1'],
                                'messages' => [
                                    [
                                        'id' => 'edit-1',
                                        'type' => 'edit',
                                        'edit' => ['original_message_id' => 'original-1'],
                                    ],
                                    [
                                        'id' => 'revoke-1',
                                        'type' => 'revoke',
                                        'revoke' => ['original_message_id' => 'original-2'],
                                    ],
                                    [
                                        'id' => 'system-1',
                                        'type' => 'system',
                                        'system' => ['type' => 'user_changed_number', 'wa_id' => '222'],
                                    ],
                                    [
                                        'id' => 'text-1',
                                        'type' => 'text',
                                        'text' => ['body' => 'hola'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $processor->processIncomingMessage(
            Request::create('/whatsapp-webhook', 'POST', $payload)
        );

        $this->assertSame(200, $response->getStatusCode());

        // Cada handler especial se ejecuta una vez y sólo para su tipo.
        $this->assertSame(['edit-1'], $processor->editIds);
        $this->assertSame(['revoke-1'], $processor->revokeIds);
        $this->assertSame(['system-1'], $processor->systemIds);

        // El fall-through está corregido: sólo el mensaje normal llega a
        // handleIncomingMessage().
        $this->assertSame(['text-1'], $processor->incomingIds);
    }

    private function recordingProcessor(): BaseWebhookProcessor
    {
        return new class extends BaseWebhookProcessor {
            public array $editIds = [];

            public array $revokeIds = [];

            public array $systemIds = [];

            public array $incomingIds = [];

            protected function handleEditMessage(array $message, ?array $contact, ?array $metadata): void
            {
                $this->editIds[] = $message['id'];
            }

            protected function handleRevokeMessage(array $message, ?array $contact, ?array $metadata): void
            {
                $this->revokeIds[] = $message['id'];
            }

            protected function handleSystemMessage(array $message, ?array $metadata): void
            {
                $this->systemIds[] = $message['id'];
            }

            protected function handleIncomingMessage(array $message, ?array $contact, ?array $metadata): void
            {
                $this->incomingIds[] = $message['id'];
            }
        };
    }
}
