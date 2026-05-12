# 9. Gestión de Cuenta y Número de Teléfono

Esta sección cubre toda la funcionalidad de administración de tu cuenta de WhatsApp Business (WABA), números de teléfono, perfil de empresa, webhooks y usuarios bloqueados.

---

## Tabla de contenidos

- [Acceso a los servicios](#acceso-a-los-servicios)
- [1. Cuenta de WhatsApp Business (WABA)](#1-cuenta-de-whatsapp-business-waba)
  - [1.1 Obtener datos de la cuenta](#11-obtener-datos-de-la-cuenta)
  - [1.2 Actualizar cuenta en base de datos](#12-actualizar-cuenta-en-base-de-datos)
  - [1.3 Registro inicial de cuenta](#13-registro-inicial-de-cuenta)
- [2. Suscripciones y Webhooks](#2-suscripciones-y-webhooks)
  - [2.1 Suscribir app a eventos](#21-suscribir-app-a-eventos)
  - [2.2 Consultar campos suscritos](#22-consultar-campos-suscritos)
  - [2.3 Actualizar campos suscritos](#23-actualizar-campos-suscritos)
  - [2.4 Desuscribir app](#24-desuscribir-app)
  - [2.5 Configurar webhook de número](#25-configurar-webhook-de-número)
  - [2.6 Override de webhook a nivel WABA](#26-override-de-webhook-a-nivel-waba)
  - [2.7 Override de webhook a nivel número](#27-override-de-webhook-a-nivel-número)
- [3. Números de teléfono](#3-números-de-teléfono)
  - [3.1 Listar números de teléfono](#31-listar-números-de-teléfono)
  - [3.2 Detalle de un número](#32-detalle-de-un-número)
  - [3.3 Estado del nombre](#33-estado-del-nombre)
  - [3.4 Registrar número en la API de WhatsApp](#34-registrar-número-en-la-api-de-whatsapp)
  - [3.5 Eliminar número](#35-eliminar-número)
- [4. Perfil de empresa](#4-perfil-de-empresa)
  - [4.1 Obtener perfil](#41-obtener-perfil)
  - [4.2 Actualizar perfil](#42-actualizar-perfil)
  - [4.3 Actualizar foto de perfil](#43-actualizar-foto-de-perfil)
- [5. Nombre visible (Display Name)](#5-nombre-visible-display-name)
  - [5.1 Solicitar cambio de nombre](#51-solicitar-cambio-de-nombre)
  - [5.2 Consultar estado del cambio](#52-consultar-estado-del-cambio)
- [6. Cuenta Oficial de Empresa (OBA)](#6-cuenta-oficial-de-empresa-oba)
  - [6.1 Solicitar OBA](#61-solicitar-oba)
  - [6.2 Consultar estado OBA](#62-consultar-estado-oba)
- [7. Usuarios bloqueados](#7-usuarios-bloqueados)
  - [7.1 Bloquear usuarios](#71-bloquear-usuarios)
  - [7.2 Desbloquear usuarios](#72-desbloquear-usuarios)
  - [7.3 Listar usuarios bloqueados](#73-listar-usuarios-bloqueados)
- [8. Nombre de usuario del negocio (Username)](#8-nombre-de-usuario-del-negocio-username)
  - [8.1 Establecer username](#81-establecer-username)
  - [8.2 Consultar username actual](#82-consultar-username-actual)
  - [8.3 Eliminar username](#83-eliminar-username)
  - [8.4 Obtener sugerencias de username](#84-obtener-sugerencias-de-username)

- [9. Configuración de Comercio (Catálogo en Chat)](#9-configuración-de-comercio-catálogo-en-chat)
  - [9.1 Activar/desactivar visibilidad del catálogo](#91-activardesactivar-visibilidad-del-catálogo)
  - [9.2 Consultar configuración actual](#92-consultar-configuración-actual)

---

## Acceso a los servicios

El paquete expone sus servicios de gestión de cuenta a través de dos puntos de entrada principales:

```php
use ScriptDevelop\WhatsappManager\Facades\Whatsapp;

// Servicio de gestión de teléfonos, perfiles y WABA
$phone   = Whatsapp::phone();

// Servicio de registro de cuenta (initial setup)
$account = Whatsapp::account();
```

Para `BlockService` y `UsernameService`, que no están expuestos en el Facade, se accede via el contenedor de servicios de Laravel:

```php
use ScriptDevelop\WhatsappManager\Services\BlockService;
use ScriptDevelop\WhatsappManager\Services\UsernameService;

$blockService    = app('whatsapp.block');
$usernameService = app(UsernameService::class);
```

> **Nota:** Todos los métodos de `Whatsapp::phone()` requieren que se encadene `forAccount()` para indicar sobre qué cuenta se opera.

```php
$service = Whatsapp::phone()->forAccount($whatsappBusinessAccountId);
```

---

## 1. Cuenta de WhatsApp Business (WABA)

### 1.1 Obtener datos de la cuenta

Recupera los datos de la WABA desde la API de Meta. Incluye campos como nombre, zona horaria, moneda, país, estado y límite de mensajería.

```php
$account = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->getBusinessAccount($whatsappBusinessAccountId);

// Estructura de respuesta:
// [
//   'id'                                       => '123456789',
//   'name'                                     => 'Mi Empresa',
//   'timezone_id'                              => '1',
//   'currency'                                 => 'USD',
//   'country'                                  => 'US',
//   'status'                                   => 'ACTIVE',
//   'whatsapp_business_manager_messaging_limit' => '1000',
//   'message_template_namespace'               => 'abc123...',
// ]
```

### 1.2 Actualizar cuenta en base de datos

Actualiza los datos locales de una cuenta en la base de datos. No realiza llamadas a la API de Meta — solo modifica el registro local.

```php
$updatedAccount = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->updateBusinessAccount($whatsappBusinessAccountId, [
        'name'        => 'Nuevo nombre',
        'timezone_id' => '3',
    ]);
```

### 1.3 Registro inicial de cuenta

Registra una WABA completa: obtiene los datos de Meta, crea el registro en la base de datos local, suscribe los webhooks y registra los números de teléfono asociados.

```php
use ScriptDevelop\WhatsappManager\Facades\Whatsapp;

$account = Whatsapp::account()->register([
    'whatsapp_business_id' => '123456789',
    'api_token'            => 'EAABwzLixnjY...',
    'app_id'               => '987654321',
], $subscribedFields = null);

// Retorna el Model de la cuenta con relaciones phoneNumbers.businessProfile cargadas
$account->phoneNumbers->each(function ($phone) {
    echo $phone->display_phone_number;
    echo $phone->businessProfile->about ?? '';
});
```

---

## 2. Suscripciones y Webhooks

### 2.1 Suscribir app a eventos

Suscribe la aplicación a los eventos de webhook de la WABA. Opcionalmente permite especificar qué campos recibir.

```php
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->subscribeApp();

// Con campos específicos:
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->subscribeApp(['messages', 'message_template_status_update']);
```

### 2.2 Consultar campos suscritos

Devuelve los campos a los que la aplicación está actualmente suscrita.

```php
$fields = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->getSubscribedFields($whatsappBusinessAccountId);

// Ejemplo de respuesta:
// ['messages', 'message_template_status_update', 'phone_number_name_update']
```

### 2.3 Actualizar campos suscritos

Modifica los campos suscritos para la WABA.

```php
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->updateSubscribedFields($whatsappBusinessAccountId, [
        'messages',
        'message_template_status_update',
        'phone_number_name_update',
        'account_update',
    ]);
```

### 2.4 Desuscribir app

Desuscribe la aplicación de todos los eventos de la WABA.

```php
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->unsubscribeApp();
```

### 2.5 Configurar webhook de número

Configura la URL y token de verificación del webhook para un número de teléfono específico.

```php
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->configureWebhook(
        phoneNumberId: $phoneNumberId,
        url:          'https://mi-dominio.com/webhook',
        verifyToken:  'mi-token-secreto'
    );
```

### 2.6 Override de webhook a nivel WABA

Reescribe la URL de callback del webhook para toda la WABA, sin necesidad de modificar la configuración del panel de Meta.

```php
// Aplicar override
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->overrideWabaWebhook(
        url:         'https://nuevo-endpoint.com/webhook',
        verifyToken: 'nuevo-token'
    );

// Restaurar configuración original del panel
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->removeWabaWebhookOverride();
```

### 2.7 Override de webhook a nivel número

Reescribe el webhook para un número de teléfono específico, sobreescribiendo el de la WABA.

```php
// Aplicar override por número
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->overridePhoneWebhook(
        phoneNumberId: $phoneNumberId,
        url:          'https://endpoint-especifico.com/webhook',
        verifyToken:  'token-especifico'
    );

// Eliminar override del número (vuelve al de la WABA)
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->removePhoneWebhookOverride($phoneNumberId);
```

---

## 3. Números de teléfono

### 3.1 Listar números de teléfono

Obtiene todos los números de teléfono asociados a la WABA.

```php
$phones = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->getPhoneNumbers($whatsappBusinessAccountId);

foreach ($phones as $phone) {
    echo $phone['display_phone_number'] . "\n";
    echo $phone['verified_name'] . "\n";
    echo $phone['quality_rating'] . "\n";
}
```

### 3.2 Detalle de un número

Obtiene información detallada de un número específico, incluyendo estado de verificación, calidad, throughput y configuración de webhook.

```php
$details = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->getPhoneNumberDetails($phoneNumberId);

// Campos devueltos:
// verified_name, code_verification_status, display_phone_number,
// quality_rating, platform_type, throughput, webhook_configuration,
// is_official_business_account, is_pin_enabled, status
```

### 3.3 Estado del nombre

Consulta específicamente el campo `name_status` de un número de teléfono.

```php
$status = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->getPhoneNumberNameStatus($phoneNumberId);

// ['name_status' => 'APPROVED']
// Posibles valores: APPROVED, PENDING_REVIEW, REJECTED, etc.
```

### 3.4 Registrar número en la API de WhatsApp

Registra un número de teléfono existente en la API de WhatsApp Business. Meta permite un máximo de 10 solicitudes por cuenta en 72 horas.

```php
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->registerPhone($phoneNumberId);

// Con datos adicionales (por ejemplo, PIN de dos pasos):
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->registerPhone($phoneNumberId, [
        'pin' => '123456',
    ]);
```

### 3.5 Eliminar número

Elimina un número de teléfono y su perfil de empresa asociado de la base de datos local.

```php
$deleted = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->deletePhoneNumber($phoneNumberId);

if ($deleted) {
    // Número eliminado correctamente
}
```

> **Nota:** Esta operación solo elimina el registro local. Para desconectar el número de la WABA en Meta, utiliza el panel de Meta Business Suite.

---

## 4. Perfil de empresa

### 4.1 Obtener perfil

Recupera el perfil de empresa asociado a un número de teléfono desde la API de Meta.

```php
$profile = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->getBusinessProfile($phoneNumberId);

// Estructura de respuesta:
// [
//   'about'               => 'Descripción corta del negocio',
//   'address'             => 'Av. Corrientes 1234, CABA',
//   'description'         => 'Descripción larga del negocio',
//   'email'               => 'contacto@miempresa.com',
//   'profile_picture_url' => 'https://...',
//   'websites'            => ['https://miempresa.com'],
//   'vertical'            => 'RETAIL',
// ]
```

### 4.2 Actualizar perfil

Actualiza uno o más campos del perfil de empresa en Meta y sincroniza los campos escalares en la base de datos local.

```php
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->updateBusinessProfile($phoneNumberId, [
        'about'       => 'Atención al cliente 24/7',
        'address'     => 'Av. Corrientes 1234, Buenos Aires',
        'description' => 'Soluciones tecnológicas para empresas.',
        'email'       => 'soporte@miempresa.com',
        'websites'    => ['https://miempresa.com', 'https://blog.miempresa.com'],
        'vertical'    => 'TECHNOLOGY',
    ]);

// Respuesta: ['success' => true]
```

**Valores posibles para `vertical`:**

| Valor | Significado |
|---|---|
| `UNDEFINED` | No definido |
| `OTHER` | Otro |
| `AUTO` | Automotriz |
| `BEAUTY` | Belleza / Spa |
| `APPAREL` | Ropa |
| `EDU` | Educación |
| `ENTERTAIN` | Entretenimiento |
| `EVENT_PLAN` | Planificación de eventos |
| `FINANCE` | Finanzas |
| `GROCERY` | Alimentos / Supermercado |
| `GOVT` | Gobierno |
| `HOTEL` | Hotelería |
| `HEALTH` | Salud |
| `NONPROFIT` | Sin fines de lucro |
| `PROF_SERVICES` | Servicios profesionales |
| `RETAIL` | Retail |
| `TRAVEL` | Viajes |
| `RESTAURANT` | Restaurante |
| `TECHNOLOGY` | Tecnología |

### 4.3 Actualizar foto de perfil

Sube una imagen local y la establece como foto de perfil de empresa en un solo paso. El paquete gestiona internamente la sesión de carga y el upload a Meta.

```php
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->updateBusinessProfilePicture(
        phoneNumberId: $phoneNumberId,
        filePath:      storage_path('app/logo.jpg'),
        mimeType:      'image/jpeg'  // opcional, 'image/jpeg' por defecto
    );

// Respuesta: ['success' => true]
```

**Formatos soportados:** `image/jpeg`, `image/png`

**Ejemplo con PNG:**

```php
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->updateBusinessProfilePicture(
        phoneNumberId: $phoneNumberId,
        filePath:      storage_path('app/logo.png'),
        mimeType:      'image/png'
    );
```

---

## 5. Nombre visible (Display Name)

El nombre visible es el nombre que los usuarios de WhatsApp ven cuando reciben mensajes del negocio. Cambiar este nombre requiere revisión por parte de Meta.

### 5.1 Solicitar cambio de nombre

Envía una solicitud de cambio de nombre visible. El número queda en estado `PENDING_REVIEW` hasta que Meta apruebe o rechace la solicitud. El paquete persiste el nuevo nombre y estado en la base de datos.

```php
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->updateDisplayName($phoneNumberId, 'Mi Empresa Actualizada');

// Respuesta: ['success' => true]
```

Una vez que Meta procesa la solicitud, llega un webhook `phone_number_name_update` que el paquete maneja automáticamente para actualizar el estado en la base de datos.

**Estados posibles de `new_name_status`:**

| Estado | Significado |
|---|---|
| `PENDING_REVIEW` | Solicitud enviada, esperando revisión |
| `APPROVED` | Nombre aprobado |
| `REJECTED` | Nombre rechazado |
| `EXPIRED` | La solicitud expiró sin respuesta |

### 5.2 Consultar estado del cambio

Consulta el estado actual de la solicitud de cambio de nombre directamente desde la API de Meta y sincroniza en la base de datos.

```php
$status = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->getDisplayNamePendingStatus($phoneNumberId);

// Estructura de respuesta:
// [
//   'new_display_name' => 'Mi Empresa Actualizada',
//   'new_name_status'  => 'PENDING_REVIEW',
//   'id'               => '123456789',
// ]
```

---

## 6. Cuenta Oficial de Empresa (OBA)

La Cuenta Oficial de Empresa (OBA) es el nivel de verificación más alto en WhatsApp Business, identificado con una insignia azul verificada. Solo está disponible para marcas reconocidas que cumplan los requisitos de Meta.

### 6.1 Solicitar OBA

Envía la solicitud de verificación de Cuenta Oficial. Meta evalúa la solicitud considerando los enlaces de soporte, el país de operación y la marca.

```php
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->requestOfficialBusinessAccount($phoneNumberId, [
        'supporting_links' => [
            'https://miempresa.com',
            'https://instagram.com/miempresa',
        ],
        'country'       => 'AR',
        'primary_brand' => 'Mi Empresa',
        'language'      => 'es',
    ]);

// Respuesta: ['success' => true]
```

**Parámetros del payload:**

| Campo | Tipo | Descripción |
|---|---|---|
| `supporting_links` | `array` | URLs que demuestran la presencia pública de la marca |
| `country` | `string` | Código ISO del país de operación principal |
| `primary_brand` | `string` | Nombre comercial principal de la marca |
| `language` | `string` | Código de idioma (ej: `es`, `en`, `pt`) |

### 6.2 Consultar estado OBA

Consulta el estado actual de la solicitud OBA desde la API y sincroniza en la base de datos local.

```php
$status = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->getOfficialBusinessAccountStatus($phoneNumberId);

// Estructura de respuesta:
// [
//   'official_business_account'    => ['status' => 'PENDING'],
//   'is_official_business_account' => false,
// ]
```

**Valores posibles para `oba_status`:**

| Estado | Significado |
|---|---|
| `NOT_STARTED` | No se ha iniciado el proceso |
| `PENDING` | Solicitud enviada, en revisión |
| `APPROVED` | Cuenta oficial aprobada (insignia azul activa) |
| `REJECTED` | Solicitud rechazada |

---

## 7. Usuarios bloqueados

El `BlockService` permite bloquear y desbloquear usuarios, impidiendo que reciban mensajes del número. Soporta tanto números de teléfono convencionales como BSUIDs.

### 7.1 Bloquear usuarios

Bloquea uno o más usuarios. Se pueden mezclar números de teléfono y BSUIDs en el mismo array.

```php
$blockService = app('whatsapp.block');

// Bloquear por número de teléfono
$response = $blockService->blockUsers($phoneNumberId, [
    '+5491112345678',
    '+5491187654321',
]);

// Bloquear por BSUID (formato CC.XXXXXXXXXX)
$response = $blockService->blockUsers($phoneNumberId, [
    'AR.13491208655302741918',
]);

// Mezclar ambos formatos
$response = $blockService->blockUsers($phoneNumberId, [
    '+5491112345678',
    'AR.13491208655302741918',
]);

// Respuesta exitosa:
// ['success' => true]

// Si algunos ya estaban bloqueados:
// [
//   'success'         => true,
//   'message'         => 'Some users were already blocked',
//   'already_blocked' => ['+5491112345678'],
// ]
```

### 7.2 Desbloquear usuarios

Desbloquea uno o más usuarios. Soporta los mismos formatos que `blockUsers`.

```php
$blockService = app('whatsapp.block');

$response = $blockService->unblockUsers($phoneNumberId, [
    '+5491112345678',
    'AR.13491208655302741918',
]);

// Respuesta exitosa:
// ['success' => true]

// Si algunos ya estaban desbloqueados:
// [
//   'success'            => true,
//   'message'            => 'Some users were already unblocked',
//   'already_unblocked'  => ['+5491112345678'],
// ]
```

### 7.3 Listar usuarios bloqueados

Lista los usuarios actualmente bloqueados con soporte de paginación mediante cursores.

```php
$blockService = app('whatsapp.block');

// Primera página (50 resultados por defecto)
$result = $blockService->listBlockedUsers($phoneNumberId);

// Con límite personalizado
$result = $blockService->listBlockedUsers($phoneNumberId, limit: 20);

// Paginación hacia adelante
$result = $blockService->listBlockedUsers(
    phoneNumberId: $phoneNumberId,
    limit:         20,
    after:         $result['paging']['cursors']['after'] ?? null
);

// Paginación hacia atrás
$result = $blockService->listBlockedUsers(
    phoneNumberId: $phoneNumberId,
    limit:         20,
    before:        $result['paging']['cursors']['before'] ?? null
);

// Estructura de respuesta:
// [
//   'data' => [
//     ['user' => '+5491112345678'],
//     ['user' => 'AR.13491208655302741918'],
//   ],
//   'paging' => [
//     'cursors' => [
//       'before' => 'cursor_antes',
//       'after'  => 'cursor_despues',
//     ],
//   ],
// ]
```

---

## 8. Nombre de usuario del negocio (Username)

El username del negocio es un identificador único y amigable (ej: `@miempresa`) que los usuarios pueden usar para encontrar el negocio en WhatsApp.

**Restricciones del username:**
- Entre 3 y 35 caracteres
- Solo letras minúsculas (`a-z`), números (`0-9`), punto (`.`) y guión bajo (`_`)
- Debe contener al menos una letra
- No puede comenzar ni terminar con `.`
- No puede tener puntos consecutivos (`..`)
- No puede comenzar con `www`
- No puede terminar con sufijos de dominio (`.com`, `.org`, etc.)

### 8.1 Establecer username

Adopta o cambia el nombre de usuario del negocio para un número de teléfono.

```php
use ScriptDevelop\WhatsappManager\Services\UsernameService;

$usernameService = app(UsernameService::class);

$response = $usernameService->setUsername($phoneNumberId, 'miempresa');

// Respuesta:
// ['status' => 'approved']   // Asignado inmediatamente
// ['status' => 'reserved']   // Reservado, pendiente de aprobación
```

### 8.2 Consultar username actual

Obtiene el username actualmente asignado y su estado.

```php
$usernameService = app(UsernameService::class);

$result = $usernameService->getUsername($phoneNumberId);

// Respuesta:
// [
//   'username' => 'miempresa',   // Puede estar ausente si no tiene username
//   'status'   => 'approved',
// ]
```

### 8.3 Eliminar username

Elimina el nombre de usuario del negocio.

```php
$usernameService = app(UsernameService::class);

$response = $usernameService->deleteUsername($phoneNumberId);

// Respuesta:
// ['success' => true]   // Eliminado correctamente
// ['success' => false]  // No se pudo eliminar
```

### 8.4 Obtener sugerencias de username

Obtiene sugerencias de usernames disponibles basados en el nombre del negocio. Los usernames sugeridos tienen mayor probabilidad de ser aprobados.

```php
$usernameService = app(UsernameService::class);

$suggestions = $usernameService->getUsernameSuggestions($phoneNumberId);

// Estructura de respuesta:
// [
//   'data' => [
//     [
//       'username_suggestions' => [
//         'miempresa',
//         'miempresa_oficial',
//         'miempresa.ar',
//       ]
//     ]
//   ]
// ]

// Acceder a las sugerencias:
$usernames = $suggestions['data'][0]['username_suggestions'] ?? [];
foreach ($usernames as $username) {
    echo $username . "\n";
}
```

---

## 9. Configuración de Comercio (Catálogo en Chat)

Controla si el ícono del catálogo aparece en el encabezado del chat de WhatsApp para un número de teléfono. Cuando está activado, los clientes pueden explorar el catálogo de productos directamente desde el chat.

### 9.1 Activar/desactivar visibilidad del catálogo

Usa `setCatalogVisibility()` para mostrar u ocultar el ícono del catálogo en el chat de WhatsApp.

```php
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->setCatalogVisibility($phoneNumberId, true);  // true = visible, false = oculto

// Respuesta: ['success' => true]
```

**Endpoint:** `POST /{phone_number_id}/whatsapp_commerce_settings`

**Parámetros:**
| Campo | Tipo | Descripción |
|---|---|---|
| `is_catalog_visible` | `boolean` | `true` para mostrar el ícono, `false` para ocultarlo |

### 9.2 Consultar configuración actual

Usa `getCatalogSettings()` para obtener el estado actual de la configuración de comercio, incluyendo si el catálogo está visible y si el carrito de compras está habilitado.

```php
$settings = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->getCatalogSettings($phoneNumberId);

// Estructura de respuesta:
// [
//   'data' => [
//     [
//       'is_catalog_visible' => true,
//       'is_cart_enabled'    => false,
//     ]
//   ]
// ]
```

**Endpoint:** `GET /{phone_number_id}/whatsapp_commerce_settings`

> **Nota:** Esta configuración solo tiene efecto si el catálogo ya está vinculado al número de WhatsApp. Vincular un catálogo a una WABA se hace desde el paquete `meta-catalog-manager`.

---

## Autenticación temporal

Para realizar llamadas a la API de Meta usando un token diferente al configurado para la cuenta (por ejemplo, durante procesos de migración o testing), podés usar `withTempToken()`:

```php
$response = Whatsapp::phone()
    ->forAccount($whatsappBusinessAccountId)
    ->withTempToken('EAABwzLixnjY_temporal...')
    ->getBusinessAccount($whatsappBusinessAccountId);
```

> **Nota:** El token temporal aplica únicamente a la llamada encadenada. No se persiste en la base de datos ni afecta las credenciales configuradas.

---

<div align="center">
<sub>Documentación de WhatsApp API Manager |
<a href="https://github.com/djdang3r/whatsapp-api-manager">Ver en GitHub</a></sub>
</div>
