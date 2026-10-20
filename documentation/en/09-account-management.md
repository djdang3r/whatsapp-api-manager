# 9. Account & Phone Number Management

This section covers registration and two-step verification PIN management for a phone number on the WhatsApp Business Cloud API.

---

## Register a phone number on the Cloud API

Registers a phone number on the **Cloud API** (`POST /{phone_number_id}/register`). This is the step that leaves the number **connected** so it can send and receive messages.

> ⚠️ Do not confuse this with the legacy `registerPhone()` method, which performs a `GET` on the business account to fetch `primary_funding_id` and does **not** register the number.

```php
use ScriptDevelop\WhatsappManager\Facades\Whatsapp;

$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->registerPhoneForCloudApi(
        phoneNumberId: $phoneNumberId,
        pin: '123456'
    );

// Response: ['success' => true]
```

**Parameters:**

| Field | Type | Description |
|---|---|---|
| `phoneNumberId` | `string` | API phone number ID (`api_phone_number_id`). |
| `pin` | `string` | Two-step verification PIN (6 digits). |
| `dataLocalizationRegion` | `string|null` | Optional data localization region. |

**Meta rules:**
- Requires `whatsapp_business_management` + `whatsapp_business_messaging` permissions.
- The PIN must match the existing two-step verification PIN if already set; otherwise it is set in this step.
- Maximum **10 requests per number in 72 hours** (error `#133016`).
- Verifying a number (SMS/code) is a separate step: a number can be `VERIFIED` while still `PENDING` until it is registered.

---

## Change the two-step verification PIN

Changes or sets the two-step verification PIN of a number (`POST /{phone_number_id}`).

```php
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->updateTwoStepPin(
        phoneNumberId: $phoneNumberId,
        pin: '654321'
    );

// Response: ['success' => true]
```

**Parameters:**

| Field | Type | Description |
|---|---|---|
| `phoneNumberId` | `string` | API phone number ID (`api_phone_number_id`). |
| `pin` | `string` | New two-step verification PIN (6 digits). |

> **Note:** The PIN is never persisted or written to the package logs.

---

## Phone number connection status (model helpers)

The `WhatsappPhoneNumber` model exposes the Meta-reported connection status separately from the internal lifecycle `status`:

- `meta_connection_status`: raw status reported by Meta (`CONNECTED`, `PENDING`, `OFFLINE`, `BANNED`, etc.).
- `meta_status_checked_at`: when the status was last synced.

```php
$phone->isMetaConnected();   // true when meta_connection_status === 'CONNECTED'
$phone->needsRegistration(); // true when meta_connection_status === 'PENDING'
```

## Coexistence (WhatsApp Business App)

When a number is connected through coexistence, Meta sends additional webhooks on top of `messages`:

| Webhook | Package event | When |
|---|---|---|
| `smb_message_echoes` | `CoexistenceSmbMessageEcho` + `MessageReceived` (`is_smb_echo=true`) | Messages sent from the mobile app |
| `smb_app_state_sync` | `CoexistenceContactRecordSynced` | Contacts from the mobile app |
| `history` | `CoexistenceHistoryMessageReceived` (per message) + `CoexistenceHistorySynced` (summary) | Conversation history |

### Bridge events into your application

```php
use ScriptDevelop\WhatsappManager\Events\CoexistenceHistoryMessageReceived;
use ScriptDevelop\WhatsappManager\Events\CoexistenceContactRecordSynced;

// History: fired for each persisted message (contact, message, phase).
// Not broadcast: process it on a queue and filter by date to limit the range.
Event::listen(CoexistenceHistoryMessageReceived::class, function ($event) {
    // $event->contact, $event->message, $event->phase
    // message_method: INPUT | OUTPUT (user/assistant role)
    // json_content: raw payload (original timestamp)
});

// Contacts: contact, action (create/update/remove), phoneNumberId.
Event::listen(CoexistenceContactRecordSynced::class, function ($event) {
    // $event->contact, $event->action, $event->phoneNumberId
});
```

> **Note:** Meta delivers `history` and `smb_app_state_sync` only once per business customer, and only if the business agrees to share them. Otherwise error `2593109` is returned.

<div align="center">
<sub>WhatsApp Manager Webhook Documentation |
<a href="https://github.com/djdang3r/whatsapp-api-manager">View on GitHub</a></sub>
</div>
