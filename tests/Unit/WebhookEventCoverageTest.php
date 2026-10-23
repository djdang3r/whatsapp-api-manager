<?php

namespace ScriptDevelop\WhatsappManager\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;
use ScriptDevelop\WhatsappManager\Events\FlowStatusUpdated;
use ScriptDevelop\WhatsappManager\Events\MessageDeleted;
use ScriptDevelop\WhatsappManager\Events\MessageSent;
use ScriptDevelop\WhatsappManager\Events\PhoneNumberStatusUpdated;
use ScriptDevelop\WhatsappManager\Events\TemplateApproved;
use ScriptDevelop\WhatsappManager\Events\TemplateComponentsUpdated;
use ScriptDevelop\WhatsappManager\Events\TemplateQualityUpdated;
use ScriptDevelop\WhatsappManager\Events\TemplateRejected;
use ScriptDevelop\WhatsappManager\Models\Message;
use ScriptDevelop\WhatsappManager\Models\WhatsappPhoneNumber;
use ScriptDevelop\WhatsappManager\Providers\WhatsappServiceProvider;
use ScriptDevelop\WhatsappManager\Services\WebhookProcessors\BaseWebhookProcessor;

class WebhookEventCoverageTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WhatsappServiceProvider::class];
    }

    public function test_phone_number_status_update_is_routed(): void
    {
        $processor = new class extends BaseWebhookProcessor {
            public array $phoneStatusPayloads = [];

            protected function handlePhoneNumberStatusUpdate(array $value): void
            {
                $this->phoneStatusPayloads[] = $value;
            }
        };

        $response = $processor->processIncomingMessage($this->requestFor([
            'field' => 'phone_number_status_update',
            'value' => [
                'display_phone_number' => '15550783881',
                'phone_number_id' => '106540352242922',
                'event' => 'CONNECTED',
            ],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('CONNECTED', $processor->phoneStatusPayloads[0]['event']);
    }

    public function test_phone_number_status_update_fires_event(): void
    {
        Event::fake();

        $processor = new class extends BaseWebhookProcessor {
            protected function findPhoneNumberForWebhookStatus(?string $displayPhoneNumber, ?string $apiPhoneNumberId): ?Model
            {
                return new WhatsappPhoneNumber([
                    'display_phone_number' => $displayPhoneNumber,
                    'api_phone_number_id' => $apiPhoneNumberId,
                ]);
            }
        };

        $processor->processIncomingMessage($this->requestFor([
            'field' => 'phone_number_status_update',
            'value' => [
                'display_phone_number' => '15550783881',
                'phone_number_id' => '106540352242922',
                'event' => 'CONNECTED',
            ],
        ]));

        Event::assertDispatched(
            $this->configuredEvent('whatsapp.events.phone_number.updated'),
            function (PhoneNumberStatusUpdated $event) {
                return $event->data['new_status'] === 'CONNECTED'
                    && $event->data['event_type'] === 'CONNECTED'
                    && $event->data['phone_number'] instanceof WhatsappPhoneNumber;
            }
        );
    }

    public function test_sent_status_fires_message_sent_event(): void
    {
        Event::fake();

        $this->statusProcessor()->runStatus([
            'id' => 'wamid.sent-1',
            'status' => 'sent',
            'timestamp' => '1750263773',
        ]);

        Event::assertDispatched(
            $this->configuredEvent('whatsapp.events.messages.message.sent'),
            function (MessageSent $event) {
                return ($event->data['message']->wa_id ?? null) === 'wamid.sent-1';
            }
        );
    }

    public function test_deleted_status_fires_message_deleted_event(): void
    {
        Event::fake();

        $this->statusProcessor()->runStatus([
            'id' => 'wamid.deleted-1',
            'status' => 'deleted',
            'timestamp' => '1750263773',
        ]);

        Event::assertDispatched(
            $this->configuredEvent('whatsapp.events.messages.message.deleted'),
            function (MessageDeleted $event) {
                return ($event->data['message']->wa_id ?? null) === 'wamid.deleted-1';
            }
        );
    }

    public function test_template_status_update_fires_approved_event(): void
    {
        Event::fake();

        $this->templateStatusProcessor()->processIncomingMessage($this->requestFor([
            'field' => 'message_template_status_update',
            'value' => [
                'event' => 'APPROVED',
                'message_template_id' => 806312974732579,
                'reason' => null,
            ],
        ]));

        Event::assertDispatched(
            $this->configuredEvent('whatsapp.events.template.approved'),
            function (TemplateApproved $event) {
                return $event->data['wa_template_id'] === '806312974732579'
                    && $event->data['status'] === 'APPROVED';
            }
        );
    }

    public function test_template_status_update_fires_rejected_event(): void
    {
        Event::fake();

        $this->templateStatusProcessor()->processIncomingMessage($this->requestFor([
            'field' => 'message_template_status_update',
            'value' => [
                'event' => 'REJECTED',
                'message_template_id' => 806312974732579,
                'reason' => 'INVALID_FORMAT',
            ],
        ]));

        Event::assertDispatched(
            $this->configuredEvent('whatsapp.events.template.rejected'),
            function (TemplateRejected $event) {
                return $event->data['reason'] === 'INVALID_FORMAT';
            }
        );
    }

    public function test_template_quality_update_fires_event(): void
    {
        Event::fake();

        $processor = $this->templateStatusProcessor();

        $processor->processIncomingMessage($this->requestFor([
            'field' => 'message_template_quality_update',
            'value' => [
                'previous_quality_score' => 'GREEN',
                'new_quality_score' => 'YELLOW',
                'message_template_id' => 806312974732579,
                'message_template_name' => 'welcome_template',
                'message_template_language' => 'en_US',
            ],
        ]));

        Event::assertDispatched(
            $this->configuredEvent('whatsapp.events.template.quality_updated'),
            function (TemplateQualityUpdated $event) {
                return $event->data['new_quality_score'] === 'YELLOW'
                    && $event->data['previous_quality_score'] === 'GREEN';
            }
        );
    }

    public function test_template_components_update_fires_event(): void
    {
        Event::fake();

        $processor = new class extends BaseWebhookProcessor {
        };

        $processor->processIncomingMessage($this->requestFor([
            'field' => 'message_template_components_update',
            'value' => [
                'message_template_id' => 1315502779341834,
                'message_template_name' => 'order_confirmation',
                'message_template_language' => 'en_US',
                'message_template_title' => 'Your order is confirmed!',
                'message_template_element' => 'Thank you for your order, {{1}}!',
                'message_template_footer' => 'Lucky Shrub',
                'message_template_buttons' => [
                    [
                        'message_template_button_type' => 'URL',
                        'message_template_button_text' => 'Email support',
                        'message_template_button_url' => 'https://example.test/support',
                    ],
                ],
            ],
        ]));

        Event::assertDispatched(
            $this->configuredEvent('whatsapp.events.template.components_updated'),
            function (TemplateComponentsUpdated $event) {
                return $event->data['components']['title'] === 'Your order is confirmed!'
                    && count($event->data['components']['buttons']) === 1;
            }
        );
    }

    public function test_flows_field_is_routed_to_flow_handler(): void
    {
        $processor = new class extends BaseWebhookProcessor {
            public array $flowEvents = [];

            protected function handleFlowStatusAndPerformance(array $value): void
            {
                $this->flowEvents[] = $value;
            }
        };

        $processor->processIncomingMessage($this->requestFor([
            'field' => 'flows',
            'value' => [
                'event' => 'ENDPOINT_LATENCY',
                'flow_id' => '691244242662581',
                'p90_latency' => 8000,
                'p50_latency' => 500,
                'requests_count' => 34,
                'threshold' => 7000,
                'alert_state' => 'ACTIVATED',
            ],
        ]));

        $this->assertSame('ENDPOINT_LATENCY', $processor->flowEvents[0]['event']);
    }

    public function test_flow_status_event_fires_configured_event(): void
    {
        Event::fake();

        $processor = new class extends BaseWebhookProcessor {
            public function runFlowEvent(array $value): void
            {
                $this->fireFlowStatusUpdated($value);
            }
        };

        $processor->runFlowEvent([
            'event' => 'FLOW_STATUS_CHANGE',
            'flow_id' => '691244242662581',
            'old_status' => 'DRAFT',
            'new_status' => 'PUBLISHED',
        ]);

        Event::assertDispatched(
            $this->configuredEvent('whatsapp.events.flows.status_updated'),
            function (FlowStatusUpdated $event) {
                return $event->data['new_status'] === 'PUBLISHED';
            }
        );
    }

    public function test_default_subscription_includes_flows_and_template_components(): void
    {
        $fields = config('whatsapp.webhook.subscribed_fields', []);

        $this->assertContains('flows', $fields);
        $this->assertContains('message_template_components_update', $fields);
        $this->assertContains('message_template_quality_update', $fields);
        $this->assertContains('messages', $fields);
    }

    private function statusProcessor(): BaseWebhookProcessor
    {
        return new class extends BaseWebhookProcessor {
            public function runStatus(array $status): void
            {
                $this->handleStatusUpdate($status);
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

    private function templateStatusProcessor(): BaseWebhookProcessor
    {
        return new class extends BaseWebhookProcessor {
            protected function findTemplateAPI(array $payload): void
            {
                // No-op: the event must be emitted without a live Graph API call.
            }
        };
    }

    /**
     * El namespace declarado de varios eventos usa `Scriptdevelop` (minúscula)
     * mientras composer.json usa `ScriptDevelop`. Normalizamos el nombre que
     * realmente registra el dispatcher para no depender del casing.
     */
    private function configuredEvent(string $key): string
    {
        return ltrim((string) config($key), '\\');
    }

    private function requestFor(array $change): Request
    {
        return Request::create('/whatsapp-webhook', 'POST', [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '102290129340398',
                    'changes' => [$change],
                ],
            ],
        ]);
    }
}
