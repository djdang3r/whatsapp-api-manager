
---

<div align="center">
<table>
  <tr>
    <td align="left">
      <a href="03-messages.md" title="Previous section">◄◄ Message Management</a>
    </td>
    <td align="center">
      <a href="00-content.md" title="Table of contents">▲ Table of contents</a>
    </td>
    <td align="right">
      <a href="05-events.md" title="Next section">Events ►►</a>
    </td>
  </tr>
</table>
</div>

<div align="center">
<sub>WhatsApp Manager Webhook Documentation | 
<a href="https://github.com/djdang3r/whatsapp-api-manager">View on GitHub</a></sub>
</div>

---

## 📋 Template Management

### Introduction
The template module provides comprehensive tools to create, manage, and send messages based on WhatsApp-approved templates. Templates are essential for automated communications like notifications, promotions, and transactional messages, allowing you to maintain consistency in your communication while complying with WhatsApp's policies.

**Key features:**
- Template creation for different categories (utility, marketing)
- Version and component management (headers, bodies, footers, buttons)
- Synchronization with WhatsApp API
- Bulk sending of template-based messages
- Advanced editing with real-time validation

### 📚 Table of Contents
1. Template Administration
    - Get all templates
    - Get by name
    - Get by ID
    - Delete templates
    - Soft delete
    - Hard delete

2. Edit Templates
    - Component management
    - Validations
    - Error handling

3. Create Templates
    - Utility templates
    - Marketing templates
    - With images
    - With buttons
    - Variations

4. Send Messages with Templates

## Template Administration

- **Get all templates for a WhatsApp business account**
    Retrieves all templates for a WhatsApp business account and stores them in the database.
    Makes a request to the WhatsApp API to get all templates associated with the account.

    ```php
    use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
    use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;

    // Get a WhatsApp Business Account instance
    $account = WhatsappBusinessAccount::find($accountId);

    // Get all templates for the account
    Whatsapp::template()->getTemplates($account);
    ```

- **Get a template by name**
    Makes a request to the WhatsApp API to get a template by name and stores it in the database.

    ```php
    use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
    use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;

    // Get a WhatsApp Business Account instance
    $account = WhatsappBusinessAccount::find($accountId);

    // Get template by name
    $template = Whatsapp::template()->getTemplateByName($account, 'order_confirmation');
    ```

- **Get a template by ID**
    Makes a request to the WhatsApp API to get a template by ID and stores it in the database.

    ```php
    use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
    use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;

    // Get a WhatsApp Business Account instance
    $account = WhatsappBusinessAccount::find($accountId);

    // Get template by ID
    $template = Whatsapp::template()->getTemplateById($account, '559947779843204');
    ```

- **Delete template from API and database simultaneously**
    Makes a request to the WhatsApp API to delete the selected template. There are two deletion methods: Soft Delete and Hard Delete.

    ```php
    use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
    use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;

    // Get a WhatsApp Business Account instance
    $account = WhatsappBusinessAccount::find($accountId);

    // Soft delete
    // Delete template by ID
    $template = Whatsapp::template()->deleteTemplateById($account, $templateId);

    // Delete template by name
    $template = Whatsapp::template()->deleteTemplateByName($account, 'order_confirmation');


    // Hard delete
    // Delete template by ID
    $template = Whatsapp::template()->deleteTemplateById($account, $templateId, true);

    // Delete template by name
    $template = Whatsapp::template()->deleteTemplateByName($account, 'order_confirmation', true);
    ```

- **Edit template in API and database simultaneously**
    Makes a request to the WhatsApp API to edit the selected template.

    ```php
    use ScriptDevelop\WhatsappManager\Models\Template;
    use ScriptDevelop\WhatsappManager\Exceptions\TemplateComponentException;
    use ScriptDevelop\WhatsappManager\Exceptions\TemplateUpdateException;

    $template = Template::find('template-id');

    try {
        $updatedTemplate = $template->edit()
            ->setName('new-template-name')
            ->changeBody('New body content {{1}}', [['New example']])
            ->removeHeader()
            ->addFooter('New footer text')
            ->removeAllButtons()
            ->addButton('URL', 'Visit site', 'https://mpago.li/2qe5G7E')
            ->addButton('QUICK_REPLY', 'Confirm')
            ->update();
        
        return response()->json($updatedTemplate);
        
    } catch (TemplateComponentException $e) {
        // Handle component error
        return response()->json(['error' => $e->getMessage()], 400);
        
    } catch (TemplateUpdateException $e) {
        // Handle update error
        return response()->json(['error' => $e->getMessage()], 500);
    }
    ```

    **Add components to templates that didn't have them:**

    ```php
    $template->edit()
        ->addHeader('TEXT', 'Added header')
        ->addFooter('New footer')
        ->addButton('PHONE_NUMBER', 'Call', '+1234567890')
        ->update();
    ```

    **Remove existing components:**
    
    ```php
    $template->edit()
        ->removeFooter()
        ->removeAllButtons()
        ->update();
    ```

    **Work with specific components:**
    
    ```php
    $editor = $template->edit();

    // Check and modify header
    if ($editor->hasHeader()) {
        $headerData = $editor->getHeader();
        if ($headerData['format'] === 'TEXT') {
            $editor->changeHeader('TEXT', 'Updated header');
        }
    } else {
        $editor->addHeader('TEXT', 'New header');
    }

    // Modify buttons
    $buttons = $editor->getButtons();
    foreach ($buttons as $index => $button) {
        if ($button['type'] === 'URL' && str_contains($button['url'], 'old-domain.com')) {
            $newUrl = str_replace('old-domain.com', 'new-domain.com', $button['url']);
            $editor->removeButtonAt($index);
            $editor->addButton('URL', $button['text'], $newUrl);
        }
    }

    $editor->update();
    ```

## Key Features of Edit Template

    1. Comprehensive component management:
        - Add, change, remove methods for each component type
        - Has methods to check existence
        - Get methods to retrieve data

    2. Robust validations:
        - Component uniqueness (only one HEADER, BODY, etc.)
        - Required components (BODY always required)
        - Button limits (maximum 10)
        - Modification restrictions (cannot change category, cannot modify approved templates)

    3. Atomic operations:
        - removeButtonAt: Deletes a specific button
        - removeAllButtons: Deletes all buttons
        - getButtons: Gets all current buttons

    4. Error handling:
        - Specific exceptions for component issues
        - Exceptions for update failures
        - Clear and descriptive error messages

    5. Intuitive workflow:
        - $template->edit() starts editing
        - Method chaining for modifications
        - update() applies changes

## ❤️ Support us with a GitHub Sponsors donation

You can support me as an open source developer on GitHub Sponsors:
- If this project has been useful to you, you can support it with a donation through
[![Sponsor](https://img.shields.io/badge/Sponsor%20me-GitHub-blue?style=for-the-badge&logo=github)](https://github.com/sponsors/djdang3r)

- Or via Mercadopago Colombia:
[![Donate via Mercado Pago](https://img.shields.io/badge/Donate%20via-Mercado%20Pago-blue?style=for-the-badge&logo=mercadopago)](https://mpago.li/2qe5G7E)
Thank you for your support 💙
---

## Create Templates in a WhatsApp Account
- ### Create Utility Templates

    Transactional templates are ideal for notifications like order confirmations, shipping updates, etc.

    ![Marketing template example](../../assets/template_1.png "Marketing Template")

    ```php
    use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
    use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;

    // Get business account
    $account = WhatsappBusinessAccount::first();

    // Create a transactional template
    $template = Whatsapp::template()
        ->createUtilityTemplate($account)
        ->setName('order_confirmation_3')
        ->setLanguage('en_US')
        ->addHeader('TEXT', 'Order Confirmation')
        ->addBody('Your order {{1}} has been confirmed.', ['12345'])
        ->addFooter('Thank you for shopping with us!')
        ->addButton('QUICK_REPLY', 'Track Order')
        ->addButton('QUICK_REPLY', 'Contact Support')
        ->save();
    ```
---

  - ### Create Marketing Templates

    Marketing templates are useful for promotions, discounts, and mass campaigns.

    ![Marketing template example](../../assets/template_2.png "Marketing Template")

    ```php
    use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
    use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;

    // Get business account
    $account = WhatsappBusinessAccount::first();

    // Create marketing template with text
    $template = Whatsapp::template()
        ->createMarketingTemplate($account)
        ->setName('personal_promotion_text_only')
        ->setLanguage('en')
        ->addHeader('TEXT', 'Our {{1}} is on!', ['Summer Sale'])
        ->addBody(
            'Shop now through {{1}} and use code {{2}} to get {{3}} off of all merchandise.',
            ['the end of August', '25OFF', '25%']
        )
        ->addFooter('Use the buttons below to manage your marketing subscriptions')
        ->addButton('QUICK_REPLY', 'Unsubscribe from Promos')
        ->addButton('QUICK_REPLY', 'Unsubscribe from All')
        ->save();
    ```

---

  - ### Create Marketing Templates with Images

    Marketing templates can also include images in the header to make them more attractive.

    ![Marketing template example](../../assets/template_3.png "Marketing Template")

    ```php
    use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
    use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;

    // Get business account
    $account = WhatsappBusinessAccount::first();

    // Image path
    $imagePath = storage_path('app/public/laravel-whatsapp-manager.png');

    // Create marketing template with image
    $template = Whatsapp::template()
        ->createMarketingTemplate($account)
        ->setName('image_template_test')
        ->setLanguage('en_US')
        ->setCategory('MARKETING')
        ->addHeader('IMAGE', $imagePath)
        ->addBody('Hi {{1}}, your order {{2}} has been shipped!', ['John', '12345'])
        ->addFooter('Thank you for your purchase!')
        ->save();
    ```

---

- ### Create Marketing Templates with URL Buttons

    You can add custom URL buttons to redirect users to specific pages.

    ![Marketing template example](../../assets/template_3.png "Marketing Template")

    ```php
    use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
    use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;

    // Get business account
    $account = WhatsappBusinessAccount::first();

    // Image path
    $imagePath = storage_path('app/public/laravel-whatsapp-manager.png');

    // Create marketing template with image and URL buttons
    $template = Whatsapp::template()
        ->createMarketingTemplate($account)
        ->setName('image_template_test_2')
        ->setLanguage('en_US')
        ->setCategory('MARKETING')
        ->addHeader('IMAGE', $imagePath)
        ->addBody('Hi {{1}}, your order {{2}} has been shipped!', ['John', '12345'])
        ->addFooter('Thank you for your purchase!')
        ->addButton('PHONE_NUMBER', 'Call Us', '+573234255686')
        ->addButton('URL', 'Track Order', 'https://mpago.li/{{1}}', ['2qe5G7E'])
        ->save();
    ```
---

- ### Create Marketing Template Variations

    You can create multiple template variations for different purposes.

    ![Marketing template example](../../assets/template_4.png "Marketing Template")

    ```php
        use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
        use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;

        // Get business account
        $account = WhatsappBusinessAccount::first();

        // Create marketing template variation
        $template = Whatsapp::template()
            ->createMarketingTemplate($account)
            ->setName('personal_promotion_text_only_22')
            ->setLanguage('en')
            ->addHeader('TEXT', 'Our {{1}} is on!', ['Summer Sale'])
            ->addBody(
                'Shop now through {{1}} and use code {{2}} to get {{3}} off of all merchandise.',
                ['the end of August', '25OFF', '25%']
            )
            ->addFooter('Use the buttons below to manage your marketing subscriptions')
            ->addButton('QUICK_REPLY', 'Unsubscribe from Promos')
            ->addButton('QUICK_REPLY', 'Unsubscribe from All')
            ->save();
    ```
    # Notes

    - Verify that images used in templates comply with WhatsApp API requirements: format (JPEG, PNG), maximum allowed size, and recommended dimensions.
    - URL-type buttons can accept dynamic parameters through template variables (`{{1}}`, `{{2}}`, etc.), allowing link personalization for each recipient.
    - If you experience issues creating templates, check the log files for detailed information about possible errors and their solutions.

---
## ❤️ Support us with a GitHub Sponsors donation

You can support me as an open source developer on GitHub Sponsors:
- If this project has been useful to you, you can support it with a donation through
[![Sponsor](https://img.shields.io/badge/Sponsor%20me-GitHub-blue?style=for-the-badge&logo=github)](https://github.com/sponsors/djdang3r)

- Or via Mercadopago Colombia:
[![Donate via Mercado Pago](https://img.shields.io/badge/Donate%20via-Mercado%20Pago-blue?style=for-the-badge&logo=mercadopago)](https://mpago.li/2qe5G7E)
Thank you for your support 💙
---

## Send Messages Using Templates
  - ### Send template messages

    You can send different template messages based on the template structure.

    ```php
    use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
    use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;
    use ScriptDevelop\WhatsappManager\Models\WhatsappPhoneNumber;

    // Get business account
    $account = WhatsappBusinessAccount::first();
    $phone = WhatsappPhoneNumber::first();

    // Send template 1: template without buttons or without needing to provide parameters for buttons
    $message = Whatsapp::template()
        ->sendTemplateMessage($phone)
        ->to('57', '3137555908')
        ->usingTemplate('order_confirmation_4')
        ->addBody(['12345'])
        ->send();

    // Send template 2: template with URL button that does not require parameters (static URL)
    $message = Whatsapp::template()
        ->sendTemplateMessage($phone)
        ->to('57', '3135666627')
        ->usingTemplate('payment_link')
        ->addHeader('TEXT', '123456')
        ->addBody(['20000'])
        ->addButton('Pay', []) // If the button in the template is static, no parameters are needed
        ->send();

    // Send template 3: template with URL button that requires parameters (dynamic URL)
    $message = Whatsapp::template()
        ->sendTemplateMessage($phone)
        ->to('57', '3135666627')
        ->usingTemplate('dynamic_payment_link')
        ->addHeader('TEXT', '123456')
        ->addBody(['20000'])
        ->addButton('Pay', ['1QFwRV']) // If the button in the template has a placeholder, pass the value for that placeholder
        ->send();

    // EXAMPLE 4: Template with multiple buttons
    $message = Whatsapp::template()
        ->sendTemplateMessage($phone)
        ->to('57', '3135666627')
        ->usingTemplate('options_menu')
        ->addBody(['Juan Pérez'])
        ->addButton('View Catalog', [])       // Static Quick Reply
        ->addButton('Contact', [])            // Static Quick Reply
        ->addButton('Buy', ['PROD123'])       // Dynamic URL with parameter
        ->send();

    // EXAMPLE 5: Template with phone number button
    $message = Whatsapp::template()
        ->sendTemplateMessage($phone)
        ->to('57', '3135666627')
        ->usingTemplate('phone_support')
        ->addBody(['Ticket #456'])
        // PHONE_NUMBER button is sent by default; no need to pass in code
        ->send();

    $message = Whatsapp::template()
        ->sendTemplateMessage($phone)
        ->to('57', '3135666627')
        ->usingTemplate('this_is_a_new_variable_test')
        ->addHeader('TEXT', 'Test_one')
        ->addBody(['Test_one'])
        ->send();

    // EXAMPLE 6: Template with IMAGE in header
    // Assuming you have a template named 'promo_with_image' that includes:
    // - Header type IMAGE
    // - Body with variables: "Hello {{1}}, take advantage of our {{2}}% offer"

    $imageUrl = 'https://example.com/images/promo.jpg'; // Public image URL

    $message = Whatsapp::template()
        ->sendTemplateMessage($phone)
        ->to('57', '3135666627')
        ->usingTemplate('promo_with_image')
        ->addHeader('IMAGE', $imageUrl)
        ->addBody(['Juan', '30'])
        ->send();

    // EXAMPLE 7: Template with IMAGE and various BUTTONS
    // Assuming you have a template named 'featured_product' that includes:
    // - Header type IMAGE
    // - Body with variables: "{{1}}, we present {{2}}"
    // - Footer: "Valid until end of month"
    // - Buttons:
    //   * Quick Reply: "More info"
    //   * Dynamic URL: "View product" -> https://mystore.com/product/{{1}}
    //   * Phone: "Call now" (sent by default, not passed in code)

    $productImageUrl = 'https://example.com/images/featured-product.jpg';

    $message = Whatsapp::template()
        ->sendTemplateMessage($phone)
        ->to('57', '3135666627')
        ->usingTemplate('featured_product')
        ->addHeader('IMAGE', $productImageUrl)
        ->addBody(['María', 'our new premium product'])
        ->addButton('More info', [])               // Quick Reply (no parameters)
        ->addButton('View product', ['PROD-789'])   // Dynamic URL (with parameter)
        // Phone Number button is sent by default; no need to pass in code
        ->send();

    // EXAMPLE 8: Template with VIDEO and BUTTONS
    // Assuming you have a template named 'tutorial_video' that includes:
    // - Header type VIDEO
    // - Body with variables: "Hello {{1}}, check out this tutorial about {{2}}"
    // - Buttons:
    //   * Static URL: "Subscribe" -> https://youtube.com/channel/123
    //   * Quick Reply: "Next tutorial"

    $videoUrl = 'https://example.com/videos/tutorial.mp4';

    $message = Whatsapp::template()
        ->sendTemplateMessage($phone)
        ->to('57', '3135666627')
        ->usingTemplate('tutorial_video')
        ->addHeader('VIDEO', $videoUrl)
        ->addBody(['Carlos', 'digital sales'])
        ->addButton('Subscribe', [])
        ->addButton('Next tutorial', [])
        ->send();

    // EXAMPLE 9: Template with DOCUMENT and information
    // Assuming you have a template named 'send_invoice' that includes:
    // - Header type DOCUMENT
    // - Body with variables: "{{1}}, we attach your invoice No. {{2}}"
    // - URL button: "Download PDF" -> https://invoices.com/{{1}}

    $documentUrl = 'https://example.com/docs/invoice-2024.pdf';

    $message = Whatsapp::template()
        ->sendTemplateMessage($phone)
        ->to('57', '3135666627')
        ->usingTemplate('send_invoice')
        ->addHeader('DOCUMENT', $documentUrl)
        ->addBody(['Dear customer', '2024-001'])
        ->addButton('Download PDF', ['2024-001'])
        ->send();

    // EXAMPLE 10: Complete marketing template with image and multiple buttons
    // Assuming you have a template named 'super_promo' that includes:
    // - Header type IMAGE
    // - Body: "¡{{1}}! {{2}}% off on {{3}}"
    // - Footer: "Use code {{1}} at checkout"
    // - Buttons:
    //   * Dynamic URL: "Buy now" -> https://store.com/promo/{{1}}
    //   * Phone Number: "Contact advisor"
    //   * Quick Reply: "No, thanks"

    $promoImageUrl = 'https://example.com/images/super-promo-christmas.jpg';

    $message = Whatsapp::template()
        ->sendTemplateMessage($phone)
        ->to('57', '3135666627')
        ->usingTemplate('super_promo')
        ->addHeader('IMAGE', $promoImageUrl)
        ->addBody(['¡ÚLTIMA OPORTUNIDAD!', '50', 'toda la tienda'])
        ->addButton('Comprar ahora', ['NAVIDAD50'])
        ->addButton('Contactar asesor', [])
        ->addButton('No, gracias', [])
        ->send();

    // EXAMPLE 11: Template with NAMED parameters using an Associative Array (Recommended)
    // Assuming a template named 'named_order_confirmation' exists that expects {{first_name}} and {{order_number}}
    // Order does not matter when using associative arrays
    $message = Whatsapp::template()
        ->sendTemplateMessage($phone)
        ->to('57', '3137555908')
        ->usingTemplate('named_order_confirmation')
        ->addBody([
            'order_number' => 'SKBUP2-4CPIG9', 
            'first_name' => 'Jessica'
        ])
        ->send();

    // EXAMPLE 12: Template with NAMED parameters using a Sequential Array
    // Assuming the same template 'named_order_confirmation'. If using a sequential array, 
    // the values MUST be provided in the exact order the placeholders appear in the template text.
    $message = Whatsapp::template()
        ->sendTemplateMessage($phone)
        ->to('57', '3137555908')
        ->usingTemplate('named_order_confirmation')
        ->addBody(['Jessica', 'SKBUP2-4CPIG9']) // Jessica -> first_name, SKBUP2.. -> order_number
        ->send();
    ```

---

<div align="center">
<table>
  <tr>
    <td align="left">
      <a href="03-messages.md" title="Previous section">◄◄ Message Management</a>
    </td>
    <td align="center">
      <a href="00-content.md" title="Table of contents">▲ Table of contents</a>
    </td>
    <td align="right">
      <a href="05-events.md" title="Next section">Events ►►</a>
    </td>
  </tr>
</table>
</div>

<div align="center">
<sub>WhatsApp Manager Webhook Documentation | 
<a href="https://github.com/djdang3r/whatsapp-api-manager">View on GitHub</a></sub>
</div>

---

## Authentication Templates (OTP)

Authentication templates are used to send one-time passwords or verification codes via WhatsApp.
Unlike marketing and utility templates, authentication templates have a **fixed body text** predefined by WhatsApp
(`"{{1}} is your verification code"`) and use a different API payload structure.

### Key Differences from Standard Templates

| Feature | Standard Templates | Authentication Templates |
|---------|-------------------|-------------------------|
| Body text | Customizable (`text` field) | Fixed by WhatsApp: `"{{1}} is your verification code"` |
| Body configuration | `addBody($text)` | `addAuthenticationBody(bool $addSecurityReco)` |
| Footer | `addFooter($text)` | `addAuthenticationFooter(?int $minutes)` |
| Buttons | `QUICK_REPLY`, `URL`, `PHONE_NUMBER` | `OTP` with types: `COPY_CODE`, `ONE_TAP`, `ZERO_TAP` |
| Button method | `addButton($type, $text)` | `addOtpButton($text, $otpType, ...)` |

### Creating Authentication Templates

```php
use ScriptDevelop\WhatsappManager\Facades\Whatsapp;
use ScriptDevelop\WhatsappManager\Models\WhatsappBusinessAccount;

$account = WhatsappBusinessAccount::find($accountId);

// EXAMPLE 1: Copy Code button (simplest)
$template = Whatsapp::template()
    ->createAuthenticationTemplate($account)
    ->setName('verification_code')
    ->setLanguage('en_US')
    ->addAuthenticationBody(true)            // Include security recommendation
    ->addAuthenticationFooter(10)             // Code expires in 10 minutes
    ->addOtpButton('Copy code', 'COPY_CODE')
    ->save();

// EXAMPLE 2: One-Tap Autofill button (Android only)
$template = Whatsapp::template()
    ->createAuthenticationTemplate($account)
    ->setName('auth_one_tap')
    ->setLanguage('en_US')
    ->addAuthenticationBody(true)
    ->addAuthenticationFooter(5)
    ->addOtpButton(
        text: 'Autofill',
        otpType: 'ONE_TAP',
        autofillText: 'Autofill',
        packageName: 'com.example.myapp',
        signatureHash: 'K8a/AINcGX7'
    )
    ->save();

// EXAMPLE 3: One-Tap with multiple supported apps (up to 5)
$template = Whatsapp::template()
    ->createAuthenticationTemplate($account)
    ->setName('auth_multi_app')
    ->setLanguage('en_US')
    ->addAuthenticationBody(false)           // No security recommendation
    ->addOtpButton(
        text: 'Autofill',
        otpType: 'ONE_TAP',
        autofillText: 'Autofill',
        supportedApps: [
            ['package_name' => 'com.example.app1', 'signature_hash' => 'K8a/AINcGX7'],
            ['package_name' => 'com.example.app2', 'signature_hash' => 'bB9c/DEFhY8z'],
        ]
    )
    ->save();

// EXAMPLE 4: Zero-Tap (no user interaction required, Android only)
$template = Whatsapp::template()
    ->createAuthenticationTemplate($account)
    ->setName('auth_zero_tap')
    ->setLanguage('en_US')
    ->addAuthenticationBody(true)
    ->addAuthenticationFooter(10)
    ->addOtpButton(
        text: 'Copy code',
        otpType: 'ZERO_TAP',
        autofillText: 'Autofill',
        supportedApps: [
            ['package_name' => 'com.example.myapp', 'signature_hash' => 'K8a/AINcGX7'],
        ],
        zeroTapTermsAccepted: true           // REQUIRED for ZERO_TAP
    )
    ->save();

// EXAMPLE 5: Minimal authentication template (no footer, no security recommendation)
$template = Whatsapp::template()
    ->createAuthenticationTemplate($account)
    ->setName('simple_auth')
    ->setLanguage('en_US')
    ->addAuthenticationBody(false)
    ->addOtpButton('Copy code', 'COPY_CODE')
    ->save();
```

### OTP Button Types

| Type | Description | Requires `supported_apps`? | Requires `zero_tap_terms_accepted`? |
|------|-------------|---------------------------|-------------------------------------|
| `COPY_CODE` | Shows a "Copy code" button. User copies the code to clipboard and pastes it in your app. | No | No |
| `ONE_TAP` | Shows an "Autofill" button. Tapping it opens your app with the code. Android only. Falls back to COPY_CODE on iOS. | Yes | No |
| `ZERO_TAP` | Code is delivered automatically to your app via broadcast receiver. No user interaction. Android only. Falls back to ONE_TAP or COPY_CODE. | Yes | **Yes** |

### `addOtpButton()` Parameters

```php
addOtpButton(
    string $text = 'Copy code',                      // Button text (max 25 chars)
    string $otpType = 'COPY_CODE',                   // COPY_CODE, ONE_TAP, ZERO_TAP
    ?string $autofillText = null,                    // Custom autofill button text (max 25 chars)
    ?string $packageName = null,                     // Android package name (ONE_TAP/ZERO_TAP)
    ?string $signatureHash = null,                   // App signing key hash — 11 chars (ONE_TAP/ZERO_TAP)
    array $supportedApps = [],                       // Up to 5: [{'package_name' => '...', 'signature_hash' => '...'}]
    bool $zeroTapTermsAccepted = false               // REQUIRED for ZERO_TAP
): self
```

> **Important Notes:**
> - Authentication templates do NOT support headers (no images, videos, or text headers).
> - The body text is always fixed: `"<CODE> is your verification code"` (localized per language).
> - `package_name` format: at least two segments separated by dots, alphanumeric + underscore only.
> - `signature_hash` format: exactly 11 characters, alphanumeric + `/+` `=`.
> - Maximum 5 apps in `supported_apps`.

---

## ❤️ Support

If you find this project useful, consider supporting its development:

[![GitHub Sponsors](https://img.shields.io/badge/Sponsor%20me-GitHub-blue?style=for-the-badge&logo=github)](https://github.com/sponsors/djdang3r)
[![Mercado Pago](https://img.shields.io/badge/Donate%20via-Mercado%20Pago-blue?style=for-the-badge&logo=mercadopago)](https://mpago.li/2qe5G7E)

## 📄 License

MIT License - See [LICENSE](LICENSE) for more details