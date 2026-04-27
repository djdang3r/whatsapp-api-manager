<?php

namespace ScriptDevelop\WhatsappManager\Models;

use InvalidArgumentException;
use ScriptDevelop\WhatsappManager\Services\FlowService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;

class WhatsappFlow extends Model
{
    use HasFactory, SoftDeletes;
    use GeneratesUlid;

    protected $table = 'whatsapp_flows';
    protected $primaryKey = 'flow_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'whatsapp_business_account_id',
        'name',
        'wa_flow_id',
        'flow_type',
        'description',
        'json_structure',
        'status',
        'version',

        'categories',
        'preview_url',
        'preview_expires_at',
        'validation_errors',
        'json_version',
        'health_status',
        'application_id',
        'application_name',
        'application_link',
    ];

    protected $casts = [
        'json_structure' => 'array',
        'categories' => 'array',
        'validation_errors' => 'array',
        'health_status' => 'array',
        'preview_expires_at' => 'datetime',
    ];

    public function setStatusAttribute(string $value): void
    {
        $this->attributes['status'] = strtolower($value);
    }

    public function screens()
    {
        return $this->hasMany(config('whatsapp.models.flow_screen'), 'flow_id', 'flow_id');
    }

    public function templates()
    {
        return $this->belongsToMany(
            config('whatsapp.models.template'),
            'whatsapp_template_flows',
            'flow_id',
            'template_id'
        );
    }

    public function sessions()
    {
        return $this->hasMany(config('whatsapp.models.flow_session'), 'flow_id', 'flow_id');
    }

    public function whatsappBusinessAccount()
    {
        return $this->belongsTo(
            config('whatsapp.models.business_account'),
            'whatsapp_business_account_id',
            'whatsapp_business_id'
        );
    }

    public function publish(): bool
    {
        // Validar que el flujo tenga un ID válido
        if (empty($this->wa_flow_id)) {
            throw new InvalidArgumentException('El flujo no tiene un ID válido, no puede ser publicado.');
        }

        $flowService = app(FlowService::class);

        return $flowService->publish($this);
    }

    public function sync(): bool
    {
        // Validar que el flujo tenga un ID válido
        if (empty($this->wa_flow_id)) {
            throw new InvalidArgumentException('El flujo no tiene un ID válido, no puede ser sincronizado.');
        }

        $flowService = app(FlowService::class);

        $updatedFlow = $flowService->syncFlowById($this->whatsappBusinessAccount, $this->wa_flow_id);

        if (!$updatedFlow) {
            throw new \RuntimeException('Error al sincronizar el flujo desde la API.');
        }

        return true;
    }
}
