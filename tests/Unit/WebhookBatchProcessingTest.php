<?php

namespace ScriptDevelop\WhatsappManager\Tests\Unit;

use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use ScriptDevelop\WhatsappManager\Providers\WhatsappServiceProvider;
use ScriptDevelop\WhatsappManager\Services\WebhookProcessors\BaseWebhookProcessor;

class WebhookBatchProcessingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WhatsappServiceProvider::class];
    }

    public function test_processes_every_entry_change_message_and_status(): void
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
                                    ['id' => 'm-1', 'type' => 'text'],
                                    ['id' => 'm-2', 'type' => 'text'],
                                ],
                                'statuses' => [
                                    ['id' => 's-1'],
                                ],
                            ],
                        ],
                        [
                            'field' => 'messages',
                            'value' => [
                                'contacts' => [['wa_id' => '222']],
                                'metadata' => ['phone_number_id' => 'phone-1'],
                                'messages' => [
                                    ['id' => 'm-3', 'type' => 'text'],
                                ],
                                'statuses' => [
                                    ['id' => 's-2'],
                                    ['id' => 's-3'],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'contacts' => [['wa_id' => '333']],
                                'metadata' => ['phone_number_id' => 'phone-2'],
                                'messages' => [
                                    ['id' => 'm-4', 'type' => 'text'],
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
        $this->assertSame(['m-1', 'm-2', 'm-3', 'm-4'], $processor->incomingIds);
        $this->assertSame(['s-1', 's-2', 's-3'], $processor->statusIds);
    }

    public function test_rejects_invalid_signature_when_app_secret_is_configured(): void
    {
        config([
            'whatsapp.webhook.app_secret' => 'test-secret',
            'whatsapp.webhook.verify_signature' => true,
        ]);

        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'contacts' => [['wa_id' => '111']],
                                'metadata' => ['phone_number_id' => 'phone-1'],
                                'messages' => [['id' => 'm-1', 'type' => 'text']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $content = json_encode($payload, JSON_THROW_ON_ERROR);
        $request = Request::create(
            '/whatsapp-webhook',
            'POST',
            $payload,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.str_repeat('0', 64),
            ],
            $content
        );

        $response = $this->recordingProcessor()->handle($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_accepts_valid_signature(): void
    {
        config([
            'whatsapp.webhook.app_secret' => 'test-secret',
            'whatsapp.webhook.verify_signature' => true,
        ]);

        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'contacts' => [['wa_id' => '111']],
                                'metadata' => ['phone_number_id' => 'phone-1'],
                                'messages' => [['id' => 'm-1', 'type' => 'text']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $content = json_encode($payload, JSON_THROW_ON_ERROR);
        $request = Request::create(
            '/whatsapp-webhook',
            'POST',
            $payload,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $content, 'test-secret'),
            ],
            $content
        );

        $processor = $this->recordingProcessor();
        $response = $processor->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['m-1'], $processor->incomingIds);
    }

    private function recordingProcessor(): BaseWebhookProcessor
    {
        return new class extends BaseWebhookProcessor {
            public array $incomingIds = [];

            public array $statusIds = [];

            protected function handleIncomingMessage(array $message, ?array $contact, ?array $metadata): void
            {
                $this->incomingIds[] = $message['id'];
            }

            protected function handleStatusUpdate(array $status, array $contacts = []): void
            {
                $this->statusIds[] = $status['id'];
            }
        };
    }
}
