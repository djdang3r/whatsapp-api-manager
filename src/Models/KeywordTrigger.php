<?php

namespace ScriptDevelop\WhatsappManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;


class KeywordTrigger extends Model
{
    use HasFactory, SoftDeletes, GeneratesUlid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'keyword_triggers';

    protected $primaryKey = 'keyword_trigger_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'keywords',
        'case_sensitive',
        'match_type'
    ];

    protected $casts = [
        'keywords' => 'array',
        'case_sensitive' => 'boolean'
    ];

    public function trigger()
    {
        return $this->morphOne(FlowTrigger::class, 'triggerable');
    }
}
