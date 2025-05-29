<?php

namespace ScriptDevelop\WhatsappManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use ScriptDevelop\WhatsappManager\Traits\GeneratesUlid;


class StepTransition extends Model
{
    use HasFactory, SoftDeletes;
    use GeneratesUlid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'step_transitions';

    protected $primaryKey = 'transition_id';
    protected $keyType = 'ulid';
    public $incrementing = false;

    protected $fillable = [
        'from_step_id',
        'to_step_id',
        'condition_type',
        'condition_config',
        'priority'
    ];

    protected $casts = [
        'condition_config' => 'json', // Ej: {"variable":"edad","operator":">=","value":18}
    ];

    public function fromStep(): BelongsTo
    {
        return $this->belongsTo(FlowStep::class, 'from_step_id', 'step_id');
    }

    public function toStep(): BelongsTo
    {
        return $this->belongsTo(FlowStep::class, 'to_step_id', 'step_id');
    }
}
