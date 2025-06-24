<?php

namespace ScriptDevelop\WhatsappManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;
use ScriptDevelop\WhatsappManager\Enums\MessageStatus;

class Message extends Model
{
    use HasFactory, SoftDeletes;
    use GeneratesUlid;

    protected $table = 'whatsapp_messages';
    protected $primaryKey = 'message_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'whatsapp_phone_id',
        'contact_id',
        'conversation_id',
        'wa_id',
        'messaging_product',
        'message_method',
        'message_from',
        'message_to',
        'message_type',
        'message_content',
        'media_url',
        'message_context',
        'message_context_id',
        'message_context_from',
        'caption',
        'template_version_id',
        'json_content',
        'status',
        'delivered_at',
        'read_at',
        'edited_at',
        'failed_at',
        'code_error',
        'title_error',
        'message_error',
        'details_error',
        'json',
        'json_template_payload',
    ];

    protected $casts = [
        'status' => MessageStatus::class,
        'json_content' => 'array',
        'json' => 'array'
    ];

    /**
     * Cuando un mensaje es de tipo plantailla, hay que devolver un objeto con el contenido del template formateado
     * @return void
     */
    public function getTemplateContentFormatAttribute()
    {
        $content = [];

        //Es necesario tener el json_template_payload y la templateVersion con su estructura
        //para poder formatear el contenido del mensaje de plantilla
        if( $this->json_template_payload and
            $this->templateVersion and
            $this->templateVersion->template_structure and
            !empty($this->templateVersion->template_structure)
        ){
            $template_message            = collect(json_decode($this->json_template_payload, true))->recursive();
            $template_message_components = Arr::get($template_message, 'payload.template.components', collect([]));
            $template_version_structure  = collect($this->templateVersion->template_structure)->recursive();

            //dd($template_message->toArray(), $template_version_structure->toArray());

            //$body_text = Arr::get($template_message, 'body_text', '');
            $header = [];
            $body_text = '';
            $buttons = [];
            $footer_text = '';

            $foundHeader = $template_version_structure->first(function ($item) {
                    return isset($item['type']) && Str::lower($item['type']) === 'header';
                });

            if( $foundHeader ){
                $headerComponent = $template_message_components->first(function ($item) {
                        return isset($item['type']) && Str::lower($item['type']) === 'header';
                    });

                if( $headerComponent and $headerComponent->has('parameters') ){
                    $headerParameters = $headerComponent->get('parameters', collect());

                    if( $headerParameters and $headerParameters->count()>0 ){
                        $headerParameters = $headerParameters->first();

                        $header = [
                            'type' => Str::upper(Arr::get($headerParameters, 'type', '')),
                            'text' => Arr::get($headerParameters, 'text', ''),
                            'url' => Arr::get($headerParameters, 'image.link', ''),
                        ];
                    }
                }
            }

            $foundBodyText = $template_version_structure->first(function ($item) {
                    return isset($item['type']) && Str::lower($item['type']) === 'body';
                });

            if( $foundBodyText ){

                $body_text = Arr::get($foundBodyText, 'text', '');

                $bodyComponent = $template_message_components->first(function ($item) {
                        return isset($item['type']) && Str::lower($item['type']) === 'body';
                    });

                if( $bodyComponent and $bodyComponent->has('parameters') ){
                    $bodyParameters = $bodyComponent->get('parameters', collect());
                    // Reemplazo de {{1}}, {{2}}, ... por los valores de $bodyParameters en orden
                    $body_text = preg_replace_callback('/\{\{(\d+)\}\}/', function ($matches) use ($bodyParameters) {
                            $index = (int)$matches[1] - 1; // {{1}} corresponde al índice 0
                            if (isset($bodyParameters[$index]['text'])) {
                                return $bodyParameters[$index]['text'];
                            }
                            return $matches[0]; // Si no existe, deja el marcador igual
                        }, $body_text);
                }

            }

            $foundButtons = $template_version_structure->first(function ($item) {
                    return isset($item['type']) && Str::lower($item['type']) === 'buttons';
                });

            if( $foundButtons and $foundButtons->has('buttons') and $foundButtons->get('buttons')->count()>0 ){
                $buttonsComponent = $template_message_components->first(function ($item) {
                        return isset($item['type']) && Str::lower($item['type']) === 'button';
                    });

                $foundButtons->get('buttons')->each(function ($button) use (&$buttonsComponent, &$buttons) {
                    $payload = '';
                    $type = Arr::get($button, 'type', '');
                    $text = Arr::get($button, 'text', '');

                    if( Str::upper($type)=='URL' ){
                        $buttonParameters = Arr::get($buttonsComponent, 'parameters', collect());
                        $payload          = urldecode(Arr::get($button, 'url', ''));
                        //$payload = $payload.'{{1}}/{{2}}/{{3}}/{{4}}/{{5}}/{{6}}/{{7}}/{{8}}/{{9}}/{{10}}{{10}}{{1}}';
                        $payload          = preg_replace('/(\{\{\d+\}\})\1+/', '$1', $payload);

                        $payload = preg_replace_callback('/\{\{(\d+)\}\}/', function ($matches) use ($buttonParameters) {
                                $index = (int)$matches[1] - 1; // {{1}} corresponde al índice 0

                                if( isset($buttonParameters[$index]['type']) and
                                    Str::lower($buttonParameters[$index]['type'])=='text' and
                                    isset($buttonParameters[$index]['text'])
                                ){
                                    return $buttonParameters[$index]['text'];
                                }
                                return $matches[0]; // Si no existe, deja el marcador igual
                            }, $payload);
                    }
                    $buttons[] = [
                        'type'    => $type,
                        'text'    => $text,
                        'payload' => $payload,
                    ];
                });
            }

            $foundFooter = $template_version_structure->first(function ($item) {
                    return isset($item['type']) && Str::lower($item['type']) === 'footer';
                });

            if( $foundFooter ){
                $footer_text = Arr::get($foundFooter, 'text', '');
            }

            $content = [
                'body' => $body_text,
                'buttons' => $buttons,
                'header' => $header,
                'footer' => $footer_text,
            ];
        }
        return $content;
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function phoneNumber()
    {
        return $this->belongsTo(WhatsappPhoneNumber::class, 'whatsapp_phone_id');
    }

    public function mediaFiles()
    {
        return $this->hasMany(MediaFile::class, 'message_id');
    }

    public function parentMessage()
    {
        // Relación uno a uno: este mensaje pertenece a un mensaje de contexto
        return $this->belongsTo(Message::class, 'message_context_id', 'message_id');
    }

    public function replies()
    {
        // Relación uno a muchos: este mensaje tiene múltiples réplicas
        return $this->hasMany(Message::class, 'message_context_id', 'message_id');
    }

    // Relación con la versión de plantilla
    public function templateVersion()
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version_id');
    }

    public function getDisplayContentAttribute()
    {
        if ($this->message_type === 'INTERACTIVE') {
            return $this->json['body'] ?? 'Mensaje interactivo';
        }

        return $this->message_content ?? 'Sin contenido';
    }
}
