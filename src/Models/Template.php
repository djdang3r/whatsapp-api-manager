<?php

namespace ScriptDevelop\WhatsappManager\Models;

use ScriptDevelop\WhatsappManager\Services\TemplateEditor;
use ScriptDevelop\WhatsappManager\WhatsappApi\ApiClient;
use ScriptDevelop\WhatsappManager\Services\TemplateService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;

class Template extends Model
{
    use HasFactory, SoftDeletes;
    use GeneratesUlid;

    protected $table = 'whatsapp_templates';
    protected $primaryKey = 'template_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $with = ['components'];

    protected $fillable = [
        'whatsapp_business_id',
        'wa_template_id',
        'name',
        'language',
        'category_id',
        'status',
        'file',
        'json',
        'rejection_reason',
    ];

    public function businessAccount()
    {
        return $this->belongsTo(config('whatsapp.models.business_account'), 'whatsapp_business_id');
    }

    /**
     * Relación con la categoría de la plantilla.
     */
    public function category()
    {
        return $this->belongsTo(config('whatsapp.models.template_category'), 'category_id', 'category_id'); // Usar 'category_id'
    }

    public function languageData()
    {
        return $this->belongsTo(config('whatsapp.models.template_language'), 'language', 'id');
    }

    public function components()
    {
        return $this->hasMany(config('whatsapp.models.template_component'), 'template_id', 'template_id');
    }

    public function flows()
    {
        return $this->belongsToMany(
            config('whatsapp.models.flow'), 'whatsapp_template_flows', 'template_id', 'flow_id'
        );
    }

    /**
     * Scope para buscar plantillas activas.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'ACTIVE');
    }

    /**
     * Método para obtener el contenido de la plantilla en un idioma específico.
     */
    public function getContentByLanguage(string $language): ?array
    {
        return $this->json['languages'][$language] ?? null;
    }

    /**
     * Inicia el editor de plantillas para esta instancia
     */
    public function edit(): TemplateEditor
    {
        return app(TemplateEditor::class, [
            'template' => $this,
            'apiClient' => app(ApiClient::class),
            'templateService' => app(TemplateService::class)
        ]);
    }

    // Relación con versiones
    public function versions()
    {
        return $this->hasMany(config('whatsapp.models.template_version'), 'template_id');
    }

    // Relación con la última versión aprobada
    public function activeVersion()
    {
        return $this->hasOne(config('whatsapp.models.template_version'), 'template_id')
            ->where('is_active', true);
    }

    public function createNewVersion(array $newStructure): Model
    {
        // Desactivar todas las versiones anteriores
        $this->versions()->update(['is_active' => false]);

        // Crear nueva versión
        return $this->versions()->create([
            'version_hash' => md5(json_encode($newStructure)),
            'template_structure' => $newStructure,
            'status' => 'PENDING',
            'is_active' => true,
        ]);
    }
}
