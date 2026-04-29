<div align="center">
<table>
  <tr>
    <td align="left">
      <a href="06-webhook.md" title="Sección anterior: Webhook">◄◄ Webhook</a>
    </td>
    <td align="center">
      <a href="00-tabla-de-contenido.md" title="Tabla de contenido">▲ Tabla de contenido</a>
    </td>
    <td align="right">
      <a href="08-ejemplos.md" title="Sección siguiente">Ejemplos ►►</a>
    </td>
  </tr>
</table>
</div>

<div align="center">
<sub>Documentación de WhatsApp Flows | 
<a href="https://github.com/djdang3r/whatsapp-api-manager">Ver en GitHub</a></sub>
</div>

---

## 🌊 Gestión de WhatsApp Flows

### Introducción a WhatsApp Flows
WhatsApp Flows te permite crear experiencias ricas e interactivas directamente dentro de la aplicación de WhatsApp. Con este módulo, puedes diseñar formularios, procesos de reserva, encuestas y más, utilizando una interfaz fluida (Builder Pattern) en PHP, sin tener que lidiar manualmente con la complejidad de la estructura JSON que exige Meta.

**Características principales:**
- Sincronización bidireccional de flujos existentes.
- Construcción fluida de Pantallas (Screens) y Elementos (Elements).
- Edición dinámica de flujos en estado DRAFT.
- Publicación directa a la API de WhatsApp.
- Configuración automática de Endpoints para manejo de datos.

### 📚 Tabla de Contenidos
1. Sincronización de Flujos
2. Crear un Nuevo Flujo (Flow Builder)
3. Estructura de Pantallas y Elementos
4. Editar un Flujo Existente (Flow Editor)
5. Publicar un Flujo

---

## 1. Sincronización de Flujos

Antes de empezar a trabajar o si has creado flujos directamente desde el Business Manager de Meta, es recomendable sincronizarlos con tu base de datos local.

- **Sincronizar todos los flujos de la cuenta:**
    ```php
    use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
    use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;

    $account = WhatsappBusinessAccount::first();

    // Esto descargará todos los flujos y su estructura JSON a la base de datos
    $flows = Whatsapp::flow()->syncFlows($account);
    ```

- **Sincronizar un flujo específico por su ID:**
    ```php
    // Útil si solo necesitas actualizar un flujo en particular
    $flowId = '1234567890';
    $flow = Whatsapp::flow()->syncFlowById($account, $flowId);
    ```

---

## 2. Crear un Nuevo Flujo (Flow Builder)

El paquete incluye un potente `FlowBuilder` que te permite diseñar la estructura visual del flujo paso a paso.

### Ejemplo: Flujo de Generación de Leads
Este ejemplo crea un flujo de captación de datos con una sola pantalla que pide nombre y correo.

```php
use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;

$account = WhatsappBusinessAccount::first();

// 1. Inicializar el constructor
$builder = Whatsapp::flow()->builder($account);

// 2. Definir metadatos y estructura
$flow = $builder
    ->name('Captura de Leads') // Máximo 120 caracteres
    ->type('MARKETING')        // AUTHENTICATION, MARKETING, UTILITY, SERVICE
    ->category('LEAD_GENERATION') 
    
    // 3. Construir la pantalla inicial
    ->screen('CONTACT_INFO')
        ->title('Déjanos tus datos')
        ->isStart(true) // Indica que es la primera pantalla
        
        // Agregar campo de nombre
        ->element('nombre')
            ->type('input')
            ->label('Nombre Completo')
            ->placeholder('Ej. Juan Pérez')
            ->required(true)
        ->endElement()
        
        // Agregar campo de correo
        ->element('email')
            ->type('input')
            ->label('Correo Electrónico')
            ->required(true)
        ->endElement()
        
        // Agregar botón de envío
        ->element('submit_btn')
            ->type('button')
            ->label('Enviar Datos')
            ->action('complete') // Acción para terminar el flujo
        ->endElement()
        
    ->endScreen() // Cierra la pantalla
    
    // 4. Guardar y subir a Meta
    ->save();



use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;

$account = WhatsappBusinessAccount::first();

$flowPhoto = Whatsapp::flow()->builder($account)
    ->name('Photo Picker Flow Corrected')
    ->type('UTILITY')
    ->category('OTHER')
    ->screen('FIRST')
        ->title('Photo Picker Example')
        ->isStart(true) // Importante para definir el punto de entrada
        ->terminal(true)
        ->success(true) // Opcional, el paquete lo asegura automáticamente en terminales
        ->data([]) 
        ->element('photo_picker')
            ->type('PhotoPicker')
            ->label('Upload photos')
            ->description('Please attach images about the received items')
            ->addAttributes([
                'photo-source' => 'camera_gallery',
                'min-uploaded-photos' => 1,
                'max-uploaded-photos' => 10,
                'max-file-size-kb' => 10240
            ])
        ->endElement()
        ->element('submit_btn')
            ->type('button')
            ->label('Submit')
            ->action('data_exchange', [
                'images' => '${form.photo_picker}'
            ])
        ->endElement()
    ->endScreen()
->save();

return $flowPhoto;






use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;

$account = WhatsappBusinessAccount::first();

$flowDoc = Whatsapp::flow()->builder($account)
    ->name('Document Picker Flow Test')
    ->type('UTILITY')
    ->category('OTHER')
    ->screen('SECOND')
        ->title('Document Picker Example')
        ->isStart(true)
        ->terminal(true)
        ->success(true) // El paquete asegura esto automáticamente en pantallas terminales
        ->data([]) 
        ->element('document_picker')
            ->type('DocumentPicker')
            ->label('Contract')
            ->description('Attach the signed copy of the contract')
            ->addAttributes([
                'min-uploaded-documents' => 1,
                'max-uploaded-documents' => 1,
                'max-file-size-kb' => 1024,
                'allowed-mime-types' => [
                    'image/jpeg',
                    'application/pdf'
                ]
            ])
        ->endElement()
        ->element('submit_btn')
            ->type('button')
            ->label('Submit')
            ->action('complete', [
                'documents' => '${form.document_picker}'
            ])
        ->endElement()
    ->endScreen()
->save();

return $flowDoc;

```


💡 Nota: Al ejecutar ->save(), el paquete automáticamente crea el flujo en Meta, sube el archivo JSON encriptado con la estructura, configura tu endpoint_uri y guarda el registro en tu base de datos con estado DRAFT.

3. Tipos de Elementos SoportadosAl construir tus pantallas con ->element('nombre_elemento'), puedes asignar diferentes tipos usando el método ->type():TipoDescripciónMétodos de configuracióninputCampo de texto libre.->label(), ->placeholder(), ->required()dropdownLista de selección.->label(), ->options([['label'=>'A', 'value'=>'a']])checkboxCasilla de verificación.->label(), ->required()buttonBotón de acción.->label(), ->action('nombre_accion', ['payload' => 'data'])


Ejemplo de Dropdown (Menú desplegable)


->element('pais')
    ->type('dropdown')
    ->label('Selecciona tu país')
    ->options([
        ['label' => 'Colombia', 'value' => 'CO'],
        ['label' => 'México', 'value' => 'MX'],
        ['label' => 'España', 'value' => 'ES']
    ])
->endElement()


4. Editar un Flujo Existente (Flow Editor)
Si el flujo aún está en estado DRAFT (Borrador), puedes modificarlo fácilmente utilizando el FlowEditor. Este editor te permite agregar, modificar o eliminar pantallas y elementos sin tener que reescribir todo el flujo.

use ScriptDevelop\WhatsappManager\Facades\Whatsapp;

// 1. Obtener el flujo de la base de datos
$flow = Whatsapp::flow()->getFlowById('wa_flow_12345');

// 2. Iniciar el editor
$editor = Whatsapp::flow()->edit($flow);

// 3. Modificar el flujo
$editor
    ->name('Captura de Leads (Actualizado)')
    ->description('Nueva versión del formulario')
    
    // Modificar un elemento existente en una pantalla
    ->screen('CONTACT_INFO')
        ->title('Actualiza tus datos') // Cambia el título de la pantalla
        
        // Agregar un elemento nuevo
        ->element('telefono')
            ->type('input')
            ->label('Número de Teléfono')
            ->required(false)
        ->endElement()
    ->endScreen()
    
    // Guardar cambios en Meta
    ->save();


Operaciones Avanzadas del Editor
Eliminar un elemento: ->removeElement('email')

Eliminar una pantalla completa: ->removeScreen('CONTACT_INFO')

Reordenar pantallas: ->reorderScreens(['SCREEN_2', 'SCREEN_1'])

---

### 4b. Editar un Flow con JSON completo (`setRawJsonStructure`)

Si tenés el JSON del flow ya construido (por ejemplo, desde un editor visual externo), usá `setRawJsonStructure()` en lugar de la API fluida. Esto sube el JSON tal como está, sin que PHP lo re-procese.

> **¿Por qué es importante?**
> Si PHP convierte el JSON a array (`json_decode($json, true)`) y luego lo re-encodifica, los objetos vacíos `{}` se convierten en `[]`. Meta rechaza ese JSON con el error *"Expected property 'data' to be of type 'object' but found 'array'"*. `setRawJsonStructure()` evita ese ciclo aceptando el string pre-encodificado directamente.

```php
use ScriptDevelop\WhatsappManager\Facades\Whatsapp;

// JSON generado por tu editor visual, ya serializado como string
$jsonString = json_encode($myJsonStructure, JSON_UNESCAPED_UNICODE);

$flow = Whatsapp::flow()->getFlowById('wa_flow_12345');

Whatsapp::flow()->edit($flow)
    ->setRawJsonStructure($jsonString)
    ->save();
```

**Notas:**
- `setRawJsonStructure()` y la API fluida (`->screen()->element()`) son mutuamente excluyentes. Si pasás el JSON raw, `buildFlowJson()` se omite completamente.
- El JSON debe estar completo y ser válido para Meta (incluye `version`, `routing_model`, `screens`).
- `save()` sube el JSON vía multipart al endpoint de assets de Meta y actualiza el registro local en la base de datos.

---

5. Publicar un Flujo
Una vez que hayas terminado de diseñar y probar tu flujo, debes publicarlo para que los usuarios puedan interactuar con él.

⚠️ Advertencia: Una vez que un flujo cambia de estado DRAFT a PUBLISHED, su estructura JSON no puede volver a ser modificada. Si necesitas hacer cambios, deberás clonarlo o crear uno nuevo.




EJEMPLO CON JSON

<div align="center">
<table>
  <tr>
    <td align="left">
      <a href="07-flows.md" title="Sección anterior: Flows">◄◄ WhatsApp Flows</a>
    </td>
    <td align="center">
      <a href="00-tabla-de-contenido.md" title="Tabla de contenido">▲ Tabla de contenido</a>
    </td>
    <td align="right">
      <a href="09-soporte.md" title="Sección siguiente">Soporte ►►</a>
    </td>
  </tr>
</table>
</div>

<div align="center">
<sub>Documentación de WhatsApp Flows | 
<a href="https://github.com/djdang3r/whatsapp-api-manager">Ver en GitHub</a></sub>
</div>

---

## 🛒 Ejemplos Avanzados: Flujo de Checkout con Lógica Condicional

El `FlowBuilder` de nuestro paquete está diseñado para soportar la creación de interfaces complejas utilizando métodos encadenados (Fluent Interface). Esto incluye el uso de variables locales (`data`), componentes avanzados (`RadioButtonsGroup`), navegación entre pantallas con paso de parámetros (`payload`) y renderizado condicional (`If`).

### Caso de Uso: Proceso de Compra (Checkout)
Este flujo consta de dos pantallas:
1. **Selección:** El usuario elige un producto de una lista dinámica y escribe su dirección.
2. **Checkout:** Se muestra un resumen, se elige el método de pago y, **solo si** selecciona "Transferencia Bancaria", se despliegan campos adicionales para seleccionar el banco y anotar el número de comprobante.

```php
use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;

$account = WhatsappBusinessAccount::first();

// 1. Inicializar el constructor
$builder = Whatsapp::flow()->builder($account)
    ->name('Checkout Avanzado')
    ->type('UTILITY')
    ->category('SHOPPING');

// ---------------------------------------------------------
// PANTALLA 1: SELECCIÓN DE PRODUCTO
// ---------------------------------------------------------
$builder->screen('PRODUCT_SELECTION')
    ->title('Selecciona Producto')
    
    // Definimos los datos locales (Variables) para esta pantalla
    ->data([
        'product_list' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'title' => ['type' => 'string']
                ]
            ],
            '__example__' => [
                ['id' => 'p1', 'title' => 'Suscripción SaaS - $29.99'],
                ['id' => 'p2', 'title' => 'Setup Inicial - $99.00']
            ]
        ]
    ])
    
    // Título interno de la vista
    ->element('heading')
        ->type('TextHeading')
        ->text('Tu Pedido')
    ->endElement()
    
    // Desplegable alimentado por la variable de datos
    ->element('product_id')
        ->type('dropdown')
        ->label('Elige un producto')
        ->required(true)
        ->dataSource('${data.product_list}')
    ->endElement()
    
    // Campo de texto normal
    ->element('shipping_addr')
        ->type('input')
        ->label('Dirección de envío')
        ->required(true)
    ->endElement()
    
    // Botón de navegación a la siguiente pantalla pasando el Payload
    ->element('footer_btn')
        ->type('button')
        ->label('Revisar Pedido')
        ->action('navigate', [
            'bank_list' => [
                ['id' => 'bancolombia', 'title' => 'Bancolombia'],
                ['id' => 'nequi', 'title' => 'Nequi']
            ],
            'payment_methods' => [
                ['id' => 'transfer', 'title' => 'Transferencia Bancaria'],
                ['id' => 'card', 'title' => 'Tarjeta (Stripe)']
            ]
        ])
        ->nextScreen('CHECKOUT_SCREEN') // Define a qué pantalla ir
    ->endElement()
->endScreen();


// ---------------------------------------------------------
// PANTALLA 2: CHECKOUT Y CONDICIONALES
// ---------------------------------------------------------
$builder->screen('CHECKOUT_SCREEN')
    ->title('Confirmación y Pago')
    ->terminal(true) // Indica que es una pantalla final
    ->success(true)  // El paquete lo asegura automáticamente, pero puedes definirlo explícitamente
    
    ->data([
        'payment_methods' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'title' => ['type' => 'string']
                ]
            ]
        ],
        'bank_list' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'title' => ['type' => 'string']
                ]
            ]
        ]
    ])
    
    ->element('resumen_heading')
        ->type('TextSubheading')
        ->text('Resumen de compra')
    ->endElement()
    
    // Imprimir variables de la pantalla anterior
    ->element('resumen_body')
        ->type('TextBody')
        ->text("`'Producto: ' \${screen.PRODUCT_SELECTION.form.product_id}`")
    ->endElement()
    
    // Grupo de botones de radio
    ->element('method')
        ->type('RadioButtonsGroup')
        ->label('Método de Pago')
        ->required(true)
        ->dataSource('${data.payment_methods}')
    ->endElement()
    
    // LÓGICA CONDICIONAL: Solo se muestra si eligen transferencia
    ->element('condicional_banco')
        ->type('If')
        ->condition("\${form.method} == 'transfer'")
        ->then([
            [
                'type' => 'TextCaption',
                'text' => 'Selecciona el banco y escribe el número de comprobante:'
            ],
            [
                'type' => 'Dropdown',
                'label' => 'Banco',
                'name' => 'selected_bank',
                'required' => true,
                'data-source' => '${data.bank_list}'
            ],
            [
                'type' => 'TextInput',
                'label' => 'Número de Comprobante',
                'name' => 'ref_number',
                'input-type' => 'number',
                'required' => true
            ]
        ])
    ->endElement()
    
    // Botón final que recolecta la información de AMBAS pantallas
    ->element('submit_btn')
        ->type('button')
        ->label('Finalizar Pedido')
        ->action('complete', [
            'p_id' => '${screen.PRODUCT_SELECTION.form.product_id}',
            'addr' => '${screen.PRODUCT_SELECTION.form.shipping_addr}',
            'pay_method' => '${form.method}',
            'bank' => '${form.selected_bank}',
            'reference' => '${form.ref_number}'
        ])
    ->endElement()
->endScreen();

// 3. Guardar y publicar el flujo en Meta
$flow = $builder->save();

echo "Flujo de Checkout creado con éxito. ID: " . $flow->wa_flow_id;

```



## 🚀 Ejemplos Avanzados de WhatsApp Flows

### Flujo de Checkout con Lógica Condicional
Cuando necesitas crear flujos altamente complejos que involucran **variables dinámicas, renderizado condicional (`If`), objetos de datos locales (`data`) y múltiples pantallas con banderas de terminal**, la forma más eficiente de utilizar el paquete es inyectar la estructura cruda (Raw Array) usando el método `setJsonStructure()`.

Este enfoque te da acceso absoluto al 100% de las capacidades de Meta, manteniendo la comodidad de guardado y publicación de nuestro paquete.

#### Ejemplo: Proceso de Compra (Checkout)
Este flujo consta de dos pantallas. La primera permite seleccionar un producto y agregar una dirección. La segunda pantalla confirma el pedido, pregunta el método de pago y, condicionalmente, despliega campos adicionales si el usuario elige "Transferencia Bancaria".

```php
use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;

$account = WhatsappBusinessAccount::first();

// Estructura avanzada del Flujo en formato Array PHP
$advancedStructure = [
    'version' => '7.3',
    'screens' => [
        [
            'id' => 'PRODUCT_SELECTION',
            'title' => 'Selecciona Producto',
            'data' => [
                'product_list' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'title' => ['type' => 'string']
                        ]
                    ],
                    '__example__' => [
                        ['id' => 'p1', 'title' => 'Suscripción SaaS - $29.99'],
                        ['id' => 'p2', 'title' => 'Setup Inicial - $99.00']
                    ]
                ]
            ],
            'layout' => [
                'type' => 'SingleColumnLayout',
                'children' => [
                    ['type' => 'TextHeading', 'text' => 'Tu Pedido'],
                    [
                        'type' => 'Dropdown',
                        'label' => 'Elige un producto',
                        'name' => 'product_id',
                        'required' => true,
                        'data-source' => '${data.product_list}'
                    ],
                    [
                        'type' => 'TextInput',
                        'label' => 'Dirección de envío',
                        'name' => 'shipping_addr',
                        'required' => true
                    ],
                    [
                        'type' => 'Footer',
                        'label' => 'Revisar Pedido',
                        'on-click-action' => [
                            'name' => 'navigate',
                            'next' => ['type' => 'screen', 'name' => 'CHECKOUT_SCREEN'],
                            'payload' => [
                                'bank_list' => [
                                    ['id' => 'bancolombia', 'title' => 'Bancolombia'],
                                    ['id' => 'nequi', 'title' => 'Nequi']
                                ],
                                'payment_methods' => [
                                    ['id' => 'transfer', 'title' => 'Transferencia Bancaria'],
                                    ['id' => 'card', 'title' => 'Tarjeta (Stripe)']
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ],
        [
            'id' => 'CHECKOUT_SCREEN',
            'title' => 'Confirmación y Pago',
            'terminal' => true,
            'success' => true, // El paquete lo asegura automáticamente en terminales si usas setJsonStructure o builder
            'data' => [
                'payment_methods' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'title' => ['type' => 'string']
                        ]
                    ]
                ],
                'bank_list' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'title' => ['type' => 'string']
                        ]
                    ]
                ]
            ],
            'layout' => [
                'type' => 'SingleColumnLayout',
                'children' => [
                    ['type' => 'TextSubheading', 'text' => 'Resumen de compra'],
                    [
                        'type' => 'TextBody',
                        'text' => "`'Producto: ' \${screen.PRODUCT_SELECTION.form.product_id}`"
                    ],
                    [
                        'type' => 'RadioButtonsGroup',
                        'label' => 'Método de Pago',
                        'name' => 'method',
                        'required' => true,
                        'data-source' => '${data.payment_methods}'
                    ],
                    [
                        'type' => 'If',
                        'condition' => "\${form.method} == 'transfer'",
                        'then' => [
                            [
                                'type' => 'TextCaption',
                                'text' => 'Selecciona el banco y escribe el número de comprobante:'
                            ],
                            [
                                'type' => 'Dropdown',
                                'label' => 'Banco',
                                'name' => 'selected_bank',
                                'required' => true,
                                'data-source' => '${data.bank_list}'
                            ],
                            [
                                'type' => 'TextInput',
                                'label' => 'Número de Comprobante',
                                'name' => 'ref_number',
                                'input-type' => 'number',
                                'required' => true
                            ]
                        ]
                    ],
                    [
                        'type' => 'Footer',
                        'label' => 'Finalizar Pedido',
                        'on-click-action' => [
                            'name' => 'complete',
                            'payload' => [
                                'p_id' => '${screen.PRODUCT_SELECTION.form.product_id}',
                                'addr' => '${screen.PRODUCT_SELECTION.form.shipping_addr}',
                                'pay_method' => '${form.method}',
                                'bank' => '${form.selected_bank}',
                                'reference' => '${form.ref_number}'
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ]
];

// Construir y guardar el flujo usando la estructura inyectada
$flow = Whatsapp::flow()->builder($account)
    ->name('Flujo de Pago Avanzado')
    ->type('UTILITY')
    ->category('SHOPPING')
    ->setJsonStructure($advancedStructure) // Inyectamos la estructura completa
    ->save();

echo "Flujo creado con ID: " . $flow->wa_flow_id;





use ScriptDevelop\WhatsappManager\Facades\Whatsapp;

// Obtener el flujo
$flow = Whatsapp::flow()->getFlowById('wa_flow_12345');

// Publicar el flujo en la API de Meta
try {
    Whatsapp::flow()->publish($flow);
    echo "¡Flujo publicado con éxito!";
} catch (\Exception $e) {
    echo "Error al publicar: " . $e->getMessage();
}



El método publish() cambiará el estado en Meta, actualizará tu base de datos local y sincronizará el estado de salud y validación final del flujo.


<div align="center">
<table>
<tr>
<td align="left">
<a href="06-webhook.md" title="Sección anterior: Webhook">◄◄ Webhook</a>
</td>
<td align="center">
<a href="00-tabla-de-contenido.md" title="Tabla de contenido">▲ Tabla de contenido</a>
</td>
<td align="right">
<a href="08-ejemplos.md" title="Sección siguiente">Ejemplos ►►</a>
</td>
</tr>
</table>
</div>

<div align="center">
<sub>Documentación de WhatsApp Flows |
<a href="https://github.com/djdang3r/whatsapp-api-manager">Ver en GitHub</a></sub>
</div>

❤️ Apoyo
Si este proyecto te resulta útil, considera apoyar su desarrollo:

📄 Licencia
MIT License - Ver LICENSE para más detalles
































## 🖼️ Procesamiento de Media — PhotoPicker y DocumentPicker

Cuando un usuario completa un Flow que incluye un componente `PhotoPicker` o `DocumentPicker`, los archivos **no llegan descifrados** en el webhook. Meta los almacena temporalmente (hasta 20 días) en su CDN encriptados con AES-256-CBC + HMAC-SHA256.

El paquete maneja esto automáticamente en `handleFlowResponseMessage()`, pero también podés invocar `FlowMediaService` directamente si necesitás personalizar el flujo.

---

### Cómo funciona el webhook `nfm_reply`

Cuando el usuario termina el Flow, llegás a tu webhook con un `nfm_reply` cuyo `response_json` contiene:

```json
{
    "photo_picker": [
        {
            "file_name": "IMG_5237.jpg",
            "mime_type": "image/jpeg",
            "sha256": "PqHgadp8cJ/N6mvAYGNMxhs9Ra5hbZFcctCtCClXsMU=",
            "id": "3631120727156756"
        }
    ],
    "flow_token": "xyz",
    "name": "Juan"
}
```

El campo `id` es el `media_id`. El paquete llama automáticamente a Meta API con ese ID para obtener el `cdn_url` y los metadatos de encriptación, descarga el archivo, lo valida y lo descifra.

---

### Algoritmo de descifrado (según spec oficial de Meta)

```
cdn_file  = ciphertext || hmac10   (el archivo del CDN tiene los últimos 10 bytes de HMAC al final)

1. SHA256(cdn_file)                           == encrypted_hash   → valida integridad del CDN
2. HMAC-SHA256(hmac_key, iv || ciphertext)[0:10] == hmac10        → valida autenticidad
3. AES-256-CBC(encryption_key, iv, ciphertext) + pkcs7 unpad      → descifra el contenido
4. SHA256(decrypted)                          == plaintext_hash   → valida el archivo final
```

---

### Escuchar el evento de finalización

```php
use ScriptDevelop\WhatsappManager\Events\Messages\Interactive\Received;

class FlowCompletedListener
{
    public function handle(Received $event): void
    {
        $data = $event->data;

        // Solo procesar si es una finalización de Flow
        if (empty($data['is_flow_completion'])) {
            return;
        }

        $flowData = $data['flow_data'];
        $token    = $flowData['flow_token'] ?? null;

        // Archivos procesados de PhotoPicker
        // El campo "{nombre_del_componente}_files" contiene los archivos ya descifrados
        $photos = $flowData['photo_picker_files'] ?? [];

        foreach ($photos as $photo) {
            // $photo['url']           → URL pública (Storage::url)
            // $photo['path']          → ruta relativa en Storage
            // $photo['name']          → nombre generado del archivo
            // $photo['original_name'] → nombre original del usuario
            // $photo['mime']          → mime type detectado
            // $photo['size']          → tamaño en bytes
            // $photo['media_id']      → media_id original de Meta

            $url = $photo['url'];
            // Guardá la URL, procesá la imagen, etc.
        }

        // Lo mismo para DocumentPicker
        $docs = $flowData['document_picker_files'] ?? [];
    }
}
```

---

### Uso manual de `FlowMediaService`

Si necesitás procesar un archivo fuera del webhook (por ejemplo, en un job asíncrono):

```php
use ScriptDevelop\WhatsappManager\Services\Flows\FlowMediaService;

$service      = app(FlowMediaService::class);
$phone        = \App\Models\WhatsappPhoneNumber::first(); // o el que corresponda

// Caso 1: nfm_reply — solo tenés el media_id
$mediaItem = [
    'id'        => '3631120727156756',
    'file_name' => 'IMG_5237.jpg',
    'mime_type' => 'image/jpeg',
];

$result = $service->processFlowMedia($mediaItem, $phone, 'uploads');

// Caso 2: data_exchange endpoint — ya tenés cdn_url + encryption_metadata
$inlineItem = [
    'cdn_url'             => 'https://mmg.whatsapp.net/v/...',
    'file_name'           => 'contrato.pdf',
    'encryption_metadata' => [
        'encrypted_hash' => '...',
        'iv'             => '...',
        'encryption_key' => '...',
        'hmac_key'       => '...',
        'plaintext_hash' => '...',
    ],
];

$result = $service->processInlineMedia($inlineItem, 'documentos');

// $result devuelve:
// [
//     'path'          => 'whatsapp/flows/media/uploads/imagen_abc123.jpg',
//     'url'           => '/storage/whatsapp/flows/media/uploads/imagen_abc123.jpg',
//     'name'          => 'imagen_abc123.jpg',
//     'original_name' => 'IMG_5237.jpg',
//     'mime'          => 'image/jpeg',
//     'size'          => 102400,
//     'media_id'      => '3631120727156756',
// ]
```

---

### Nota sobre BSUID

Si el usuario que envió el Flow usa BSUID (identificador sin número de teléfono), el comportamiento es idéntico. La resolución del número de teléfono del **negocio** siempre viene en `metadata.phone_number_id` del webhook, independientemente del tipo de identificador del contacto. El paquete maneja automáticamente ambos formatos de contacto (BSUID y `wa_id`).

---

### 🛡️ Gestión de Llaves de Encriptación (Data Channel)

Para que el **Data Exchange** (Endpoint) funcione, Meta requiere que tu WABA tenga una llave pública cargada. El paquete permite gestionar esto fácilmente.

#### Consultar estado de la llave
```php
use ScriptDevelop\WhatsappManager\Facades\Whatsapp;

$phoneNumber = $miModeloPhone; // Instancia de WhatsappPhoneNumber
$account     = $miModeloAccount; // Instancia de WhatsappBusinessAccount

$status = Whatsapp::flow()->getPhoneNumberPublicKeyStatus($phoneNumber, $account);

// Retorna:
// [
//     'business_public_key'        => '...', 
//     'business_public_key_status' => 'AVAILABLE' // o 'NOT_AVAILABLE'
// ]
```

#### Cargar/Actualizar llave pública
```php
$publicKey = "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----";

$result = Whatsapp::flow()->setPhoneNumberPublicKey($phoneNumber, $account, $publicKey);
```

---

🏗️ Plan Maestro: WhatsApp Flows Enterprise Integration
Fase 1: Infraestructura Criptográfica (The Security Layer)
Esta fase establece la confianza entre el servidor de Laravel y Meta.

1.1. Almacenamiento Seguro: Definir una ruta protegida en storage/app/whatsapp/keys/ no accesible vía web.

1.2. Generador Automático (GenerateWhatsappKeys): * Lógica para detectar si OpenSSL está instalado.

Generación de par de llaves (2048-bit).

Encriptación opcional de la llave privada.

1.3. Gestor de Registro en Meta (FlowEncryptionService):

Método setPublicKey(string $phoneNumberId): Lee el archivo .pem y lo envía a la API de Meta.

Método getPublicKeyStatus(): Consulta si la llave en Meta coincide con la local (VALID / MISMATCH).

1.4. Automatización del Setup: Integración en el comando de instalación para que el usuario solo confirme con un "yes".

Fase 2: El Motor del Data Channel (The Crypto Engine)
Aquí es donde ocurre la magia de la desencriptación en tiempo real.

2.1. Clase FlowCrypto: * Desencriptación Inbound: Usar la llave privada para obtener la clave AES temporal y luego desencriptar el cuerpo del mensaje (AES-128-GCM).

Encriptación Outbound: Re-encriptar la respuesta de Laravel antes de enviarla a WhatsApp.

2.2. Validación de Firma: Validar que la petición viene realmente de Meta (X-Hub-Signature).

2.3. Manejador de Pings: Responder automáticamente a las pruebas de salud de Meta para que el Flow nunca pase a estado "Blocked".

Fase 3: Routing y Procesamiento de Endpoints (The Delivery Layer)
Cómo el paquete entrega los datos al código del desarrollador.

3.1. Controller Centralizado (FlowEndpointController): Un solo punto de entrada que gestiona errores criptográficos y devuelve códigos HTTP correctos a Meta.

3.2. Abstracción de Acciones: Mapear automáticamente las acciones del Flow (data_exchange, init, back) a métodos específicos en PHP.

3.3. Base Processor Class: El desarrollador creará clases como App\Whatsapp\Flows\LoginProcessor que extiendan de nuestro paquete.

Fase 4: Gestión de Media (The Binary Layer)
Desencriptación de archivos subidos por el usuario (PhotoPicker/DocumentPicker).

4.1. Downloader Seguro: Descarga desde el CDN de WhatsApp usando el Access Token.

4.2. Doble Validación: 1.  Validar SHA256 del archivo cifrado.
2.  Validar HMAC con la clave de autenticación provista por Meta.

4.3. Descifrado AES-256-CBC: Convertir el binario cifrado en un archivo real (JPEG, PDF, etc.).

4.4. Limpieza de Padding: Implementar des-relleno PKCS7 para que el archivo no esté corrupto.

Fase 5: Ciclo de Vida y Monitoreo (The Intelligence Layer)
Escuchar lo que Meta dice sobre nuestros flujos.

5.1. Flows Webhook Handler: Extender el procesador de webhooks para manejar el campo flows.

5.2. Sistema de Alertas (Alert System): * Disparar eventos de Laravel (FlowThrottled, FlowBlocked, LatencyHigh) para que el desarrollador pueda reaccionar (ej. mandar un email de alerta).

5.3. Sincronización de Modelos: Actualizar la tabla whatsapp_flows automáticamente cuando cambie su estado en Meta.

Fase 6: Finalización y UX (The Completion Layer)
6.1. nfm_reply Handler: Procesar el mensaje de confirmación final que llega al chat cuando el usuario termina el flujo.

6.2. Documentación v7.3: Actualizar el FlowBuilder con los componentes que faltan (ChipsSelector, NavigationList, etc.).

🚀 Hoja de Ruta de Desarrollo
Para no cometer errores, seguiremos este orden de construcción:

Semana 1 (Cripto): Terminamos la Fase 1 (Llaves) y el Motor de la Fase 2 (Crypto Engine).

Semana 2 (Endpoints): Implementamos el Controlador y la lógica de respuesta para que el desarrollador pueda probar data_exchange.

Semana 3 (Media): Construimos el servicio de descifrado de fotos y documentos.

Semana 4 (Webhooks): Finalizamos con el sistema de monitoreo y alertas de estado.