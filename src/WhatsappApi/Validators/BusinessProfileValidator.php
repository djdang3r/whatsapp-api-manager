<?php

namespace ScriptDevelop\WhatsappManager\WhatsappApi\Validators;

use Illuminate\Support\Facades\Validator;
use ScriptDevelop\WhatsappManager\Exceptions\InvalidApiResponseException;

class BusinessProfileValidator
{
    protected array $rules = [
        'about' => 'nullable|string|max:512',
        'address' => 'nullable|string|max:256',
        'description' => 'nullable|string|max:512',
        'email' => 'nullable|email|max:128',
        'profile_picture_url' => 'nullable|url|max:512', // Ahora es string
        'vertical' => 'nullable|string|in:UNDEFINED,OTHER,PROFESSIONAL_SERVICES',
        'websites' => 'nullable|array',
        'websites.*' => 'url|max:512', // Validar cada URL directamente
        'messaging_product' => 'nullable|string'
    ];

    /**
     * @throws InvalidApiResponseException
     */
    public function validate(array $apiData): array
    {
        $validator = Validator::make($apiData, $this->rules);

        if ($validator->fails()) {
            throw InvalidApiResponseException::fromValidationError(
                $validator->errors()->first(),
                $validator->errors()->toArray()
            );
        }

        return $this->formatValidData($apiData);
    }

    private function formatValidData(array $data): array
    {
        return [
            'about' => $data['about'] ?? null,
            'address' => $data['address'] ?? null,
            'description' => $data['description'] ?? null,
            'email' => $data['email'] ?? null,
            'profile_picture_url' => $data['profile_picture_url'] ?? null,
            'vertical' => $data['vertical'] ?? 'OTHER',
            'messaging_product' => $data['messaging_product'] ?? 'whatsapp'
        ];
    }

    private function parseProfilePicture(array $data): ?string
    {
        $url = $data['profile_picture_url'] ?? null;
        
        if (is_array($url)) {
            return $url['url'] ?? null;
        }
        
        return $url;
    }

    private function parseWebsites(array $websites): array
    {
        return array_map(function ($website) {
            return is_array($website) 
                ? ['url' => $website['url'], 'type' => $website['type'] ?? 'WEB']
                : ['url' => $website, 'type' => 'WEB'];
        }, $websites);
    }

    // Nuevo método para obtener websites
    public function extractWebsites(array $data): array
    {
        return $this->parseWebsites($data['websites'] ?? []);
    }
}