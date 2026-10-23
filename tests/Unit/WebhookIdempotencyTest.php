<?php

namespace ScriptDevelop\WhatsappManager\Tests\Unit;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use ScriptDevelop\WhatsappManager\Models\Message;
use ScriptDevelop\WhatsappManager\Providers\WhatsappServiceProvider;
use ScriptDevelop\WhatsappManager\Services\WebhookProcessors\BaseWebhookProcessor;

/**
 * Contract tests de idempotencia/replay para webhooks de status.
 *
 * La deduplicación vive en `processStatusEvent()`: message id + status
 * (+ error code en `failed`), TTL configurable y marca solo tras éxito.
 */
class WebhookIdempotencyTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WhatsappServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();
    }

    public function test_duplicate_delivered_status_fires_the_event_once(): void
    {
        Event::fake();

        $processor = $this->statusEventProcessor();
        $status = [
            'id' => 'wamid.delivered-1',
            'status' => 'delivered',
            'timestamp' => '1750263773',
            'recipient_user_id' => 'recipient-bsuid-1',
        ];

        $processor->runStatus($status);
        $processor->runStatus($status);

        Event::assertDispatchedTimes($this->eventClass('whatsapp.events.messages.message.delivered'), 1);
    }

    public function test_sent_then_delivered_are_distinct_transitions(): void
    {
        Event::fake();

        $processor = $this->statusEventProcessor();

        $processor->runStatus(['id' => 'wamid.flow-1', 'status' => 'sent', 'timestamp' => '1750263773']);
        $processor->runStatus(['id' => 'wamid.flow-1', 'status' => 'delivered', 'timestamp' => '1750263780']);

        Event::assertDispatchedTimes($this->eventClass('whatsapp.events.messages.message.sent'), 1);
        Event::assertDispatchedTimes($this->eventClass('whatsapp.events.messages.message.delivered'), 1);
    }

    public function test_malformed_status_without_id_or_status_is_safely_ignored(): void
    {
        Event::fake();

        $processor = new class extends BaseWebhookProcessor {
            public function runStatus(array $status): void
            {
                $this->processStatusEvent($status);
            }

            protected function findMessageForStatus(string $waId): ?Model
            {
                throw new \RuntimeException('No debe buscarse un mensaje para un status malformado.');
            }
        };

        $processor->runStatus(['status' => 'delivered']);
        $processor->runStatus(['id' => 'wamid.sin-status']);
        $processor->runStatus(['id' => '', 'status' => '']);

        Event::assertNotDispatched($this->eventClass('whatsapp.events.messages.message.delivered'));
        Event::assertNotDispatched($this->eventClass('whatsapp.events.messages.message.sent'));
        Event::assertNotDispatched($this->eventClass('whatsapp.events.messages.message.failed'));
    }

    public function test_multi_entry_multi_message_payload_keeps_all_events_and_dedupes_replays(): void
    {
        $processor = new class extends BaseWebhookProcessor {
            public array $incomingIds = [];

            public array $statusKeys = [];

            protected function handleIncomingMessage(array $message, ?array $contact, ?array $metadata): void
            {
                $this->incomingIds[] = $message['id'];
            }

            protected function handleStatusUpdate(array $status, array $contacts = []): void
            {
                $this->statusKeys[] = $status['id'] . ':' . $status['status'];
            }
        };

        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'metadata' => ['phone_number_id' => 'phone-1'],
                                'messages' => [
                                    ['id' => 'm-1', 'type' => 'text'],
                                    ['id' => 'm-2', 'type' => 'text'],
                                ],
                                'statuses' => [
                                    ['id' => 's-1', 'status' => 'delivered'],
                                    ['id' => 's-1', 'status' => 'delivered'],
                                ],
                            ],
                        ],
                        [
                            'field' => 'messages',
                            'value' => [
                                'metadata' => ['phone_number_id' => 'phone-1'],
                                'messages' => [
                                    ['id' => 'm-3', 'type' => 'text'],
                                ],
                                'statuses' => [
                                    ['id' => 's-2', 'status' => 'delivered'],
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
                                'metadata' => ['phone_number_id' => 'phone-2'],
                                'messages' => [
                                    ['id' => 'm-4', 'type' => 'text'],
                                ],
                                'statuses' => [
                                    // Replay del primer delivered: no debe repetirse.
                                    ['id' => 's-1', 'status' => 'delivered'],
                                    // Transición distinta del mismo mensaje: sí debe procesarse.
                                    ['id' => 's-1', 'status' => 'sent'],
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
        $this->assertSame(
            ['s-1:delivered', 's-2:delivered', 's-1:sent'],
            $processor->statusKeys
        );
    }

    public function test_dedupe_path_does_not_log_body_signature_or_pii(): void
    {
        $logger = new class {
            public array $entries = [];

            public function __call(string $method, array $arguments): void
            {
                $this->entries[] = ['method' => $method, 'arguments' => $arguments];
            }
        };

        Log::shouldReceive('channel')->andReturn($logger);

        $processor = new class extends BaseWebhookProcessor {
            public function runStatus(array $status): void
            {
                $this->processStatusEvent($status);
            }

            protected function handleStatusUpdate(array $status, array $contacts = []): void
            {
                // No-op: el test aísla el logging del path de dedupe.
            }
        };

        $status = [
            'id' => 'wamid.573001234567',
            'status' => 'delivered',
            'body' => 'mensaje privado del cliente',
            'signature' => 'sha256=super-secret-signature',
        ];

        $processor->runStatus($status);
        $processor->runStatus($status);

        $this->assertNotEmpty($logger->entries);

        $serialized = json_encode($logger->entries, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('whatsapp:webhook:status:', $serialized);
        $this->assertStringNotContainsString('mensaje privado del cliente', $serialized);
        $this->assertStringNotContainsString('super-secret-signature', $serialized);
        $this->assertStringNotContainsString('573001234567', $serialized);
    }

    public function test_transient_failure_is_not_cached_as_success_and_can_retry(): void
    {
        $processor = new class extends BaseWebhookProcessor {
            public array $handled = [];

            public bool $failNext = true;

            public function runStatus(array $status): void
            {
                $this->processStatusEvent($status);
            }

            protected function handleStatusUpdate(array $status, array $contacts = []): void
            {
                if ($this->failNext) {
                    $this->failNext = false;
                    throw new \RuntimeException('Fallo transitorio simulado.');
                }

                $this->handled[] = $status['id'] . ':' . $status['status'];
            }
        };

        $status = ['id' => 'wamid.retry-1', 'status' => 'delivered'];

        try {
            $processor->runStatus($status);
            $this->fail('El primer intento debía fallar.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo transitorio simulado.', $e->getMessage());
        }

        // El reintento debe procesarse: el fallo no se marcó como duplicado.
        $processor->runStatus($status);
        // El tercer intento idéntico ya es un replay y se descarta.
        $processor->runStatus($status);

        $this->assertSame(['wamid.retry-1:delivered'], $processor->handled);
    }

    public function test_cache_outage_fails_open_and_processes_the_event(): void
    {
        $repository = \Mockery::mock(CacheRepository::class);
        $repository->shouldReceive('has')->andThrow(new \RuntimeException('cache caída'));
        $repository->shouldReceive('put')->andThrow(new \RuntimeException('cache caída'));
        Cache::shouldReceive('store')->andReturn($repository);

        $processor = new class extends BaseWebhookProcessor {
            public array $handled = [];

            public function runStatus(array $status): void
            {
                $this->processStatusEvent($status);
            }

            protected function handleStatusUpdate(array $status, array $contacts = []): void
            {
                $this->handled[] = $status['id'] . ':' . $status['status'];
            }
        };

        $status = ['id' => 'wamid.cache-down', 'status' => 'read'];

        $processor->runStatus($status);
        $processor->runStatus($status);

        // Sin caché no hay dedupe posible, pero jamás se pierde el evento.
        $this->assertSame(
            ['wamid.cache-down:read', 'wamid.cache-down:read'],
            $processor->handled
        );
    }

    public function test_failed_status_dedupes_same_error_code_but_not_different_codes(): void
    {
        $processor = new class extends BaseWebhookProcessor {
            public array $handled = [];

            public function runStatus(array $status): void
            {
                $this->processStatusEvent($status);
            }

            protected function handleStatusUpdate(array $status, array $contacts = []): void
            {
                $this->handled[] = $status['errors'][0]['code'];
            }
        };

        $processor->runStatus($this->failedStatus(131042));
        $processor->runStatus($this->failedStatus(131042));
        $processor->runStatus($this->failedStatus(131050));

        $this->assertSame([131042, 131050], $processor->handled);
    }

    private function failedStatus(int $errorCode): array
    {
        return [
            'id' => 'wamid.failed-1',
            'status' => 'failed',
            'errors' => [
                ['code' => $errorCode, 'title' => 'Error', 'message' => 'Fallo'],
            ],
        ];
    }

    /**
     * Procesador que ejercita `handleStatusUpdate` real sin DB ni API.
     */
    private function statusEventProcessor(): BaseWebhookProcessor
    {
        return new class extends BaseWebhookProcessor {
            public function runStatus(array $status): void
            {
                $this->processStatusEvent($status);
            }

            protected function findMessageForStatus(string $waId): ?Model
            {
                return new Message([
                    'wa_id' => $waId,
                    'status' => 'pending',
                ]);
            }

            protected function updateMessageStatus(Model $message, array $status): Model
            {
                $message->status = $status['status'] ?? $message->status;

                return $message;
            }
        };
    }

    private function eventClass(string $key): string
    {
        return ltrim((string) config($key), '\\');
    }
}
