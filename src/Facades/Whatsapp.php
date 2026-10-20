<?php

namespace ScriptDevelop\WhatsappManager\Facades;

use Illuminate\Support\Facades\Facade;
use ScriptDevelop\WhatsappManager\Services\WhatsappService;
use ScriptDevelop\WhatsappManager\Services\Flows\FlowResponse;
//use ScriptDevelop\WhatsappManager\Models\Flow;

/**
 * @method static \ScriptDevelop\WhatsappManager\Services\AccountRegistrationService account()
 * @method static \ScriptDevelop\WhatsappManager\Services\MessageDispatcherService message()
 * @method static \ScriptDevelop\WhatsappManager\Services\WhatsappService phone()
 * @method static \ScriptDevelop\WhatsappManager\Services\TemplateService template()
 * @method static \ScriptDevelop\WhatsappManager\Services\BotBuilderService bot()
 * @method static \ScriptDevelop\WhatsappManager\Services\FlowBuilderService flow()
 * @method static \ScriptDevelop\WhatsappManager\Services\StepBuilderService step(Flow $flow)
 * @method static mixed getBusinessAccount(string $whatsappBusinessId)
 * @method static array getPhoneNumbers(string $whatsappBusinessId)
 * @method static array getPhoneNumberDetails(string $phoneNumberId)
 * @method static array getBusinessProfile(string $phoneNumberId)
 * @method static array registerPhoneForCloudApi(string $phoneNumberId, string $pin, ?string $dataLocalizationRegion = null)
 * @method static array updateTwoStepPin(string $phoneNumberId, string $pin)
 */
class Whatsapp extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'whatsapp.manager';
    }

    /**
     * Get the business account service instance.
     */
    public static function phone()
    {
        return app('whatsapp.phone');
    }

    /**
     * Get the business account service instance.
     */
    public static function account()
    {
        return app('whatsapp.account');
    }

    /**
     * Get the message dispatcher service instance.
     */
    public static function message()
    {
        return app('whatsapp.message');
    }

    /**
     * Get the template service instance.
     */
    public static function template()
    {
        return app('whatsapp.template');
    }

    /**
     * Get the flow builder service instance.
     */
    public static function flow()
    {
        return app('whatsapp.flow');
    }

    public static function service()
    {
        return app('whatsapp.service');
    }

    /**
     * @method static \ScriptDevelop\WhatsappManager\Services\Flows\FlowResponse flowResponse()
     */
    public static function flowResponse()
    {
        return new FlowResponse();
    }
}