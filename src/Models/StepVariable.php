<?php

namespace ScriptDevelop\WhatsappManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;

class StepVariable extends Model
{
    use HasFactory, SoftDeletes;
    use GeneratesUlid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'step_variables';

    protected $primaryKey = 'variable_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'flow_step_id',
        'name',
        'type',
        'validation_regex',
        'error_message',
        'is_required',
    ];

}
