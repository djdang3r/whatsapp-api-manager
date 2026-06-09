<?php

namespace ScriptDevelop\WhatsappManager\Services\Flows;

use Illuminate\Support\Facades\File;
use Exception;

class FlowCryptoService
{
    protected ?string $privateKey = null;
    protected ?string $decryptedAesKey = null;

    public function __construct() {}

    public function loadFromPath(string $path): static
    {
        if (!File::exists($path)) {
            throw new Exception("No se encontró la llave privada en: {$path}");
        }
        $this->privateKey = File::get($path);
        return $this;
    }

    public function loadForAccount(string $accountId): static
    {
        return $this->loadFromPath(
            storage_path("app/whatsapp/flows/keys/{$accountId}/private.pem")
        );
    }

    public function getDecryptedAesKey(): ?string
    {
        return $this->decryptedAesKey;
    }

    protected function ensureKeyLoaded(): void
    {
        if ($this->privateKey !== null) {
            return;
        }
        $legacyPath = storage_path('app/public/whatsapp/flows/keys/private.pem');
        if (File::exists($legacyPath)) {
            $this->privateKey = File::get($legacyPath);
            return;
        }
        throw new Exception(
            'No hay clave privada cargada. Llamá a loadFromPath() o loadForAccount() antes de desencriptar.'
        );
    }

    public function decryptRequest(string $encryptedAesKey, string $encryptedFlowData, string $initialVector): array
    {
        $this->ensureKeyLoaded();

        $encodedBinary = base64_decode($encryptedAesKey);
        $decryptedAesKey = null;

        if (version_compare(PHP_VERSION, '8.5.0', '>=')) {
            $keyResource = openssl_pkey_get_private($this->privateKey);
            if (! openssl_private_decrypt($encodedBinary, $decryptedAesKey, $keyResource, OPENSSL_PKCS1_OAEP_PADDING, 'sha256')) {
                throw new Exception('Fallo al desencriptar la llave AES: ' . openssl_error_string());
            }
        } elseif (class_exists('\\phpseclib3\\Crypt\\PublicKeyLoader')) {
            try {
                $key = \phpseclib3\Crypt\PublicKeyLoader::load($this->privateKey)
                    ->withHash('sha256')
                    ->withMGFHash('sha256');
                $result = $key->decrypt($encodedBinary);
                if ($result === null || $result === false || $result === '') {
                    throw new Exception('phpseclib3: RSA-OAEP SHA-256 decryption failed');
                }
                $decryptedAesKey = $result;
            } catch (\Throwable $e) {
                throw new Exception('Fallo al desencriptar la llave AES (phpseclib3): ' . $e->getMessage());
            }
        } else {
            $keyResource = openssl_pkey_get_private($this->privateKey);
            if (! openssl_private_decrypt($encodedBinary, $decryptedAesKey, $keyResource, OPENSSL_PKCS1_OAEP_PADDING)) {
                throw new Exception('Fallo al desencriptar la llave AES: ' . openssl_error_string());
            }
        }

        $this->decryptedAesKey = base64_encode($decryptedAesKey);

        $encryptedDataBinary = base64_decode($encryptedFlowData);
        $iv = base64_decode($initialVector);
        $tag = substr($encryptedDataBinary, -16);
        $ciphertext = substr($encryptedDataBinary, 0, -16);

        $decryptedData = openssl_decrypt(
            $ciphertext, 'aes-128-gcm', $decryptedAesKey,
            OPENSSL_RAW_DATA, $iv, $tag
        );

        if ($decryptedData === false) {
            throw new Exception('Fallo al desencriptar los datos del flujo.');
        }

        return json_decode($decryptedData, true);
    }

    public function encryptResponse(array $data, string $aesKey, string $iv): string
    {
        $plainText = json_encode($data);
        $tag = null;

        $decodedIv = base64_decode($iv);
        $flippedIv = '';
        $len = strlen($decodedIv);
        for ($i = 0; $i < $len; $i++) {
            $flippedIv .= chr(ord($decodedIv[$i]) ^ 0xFF);
        }

        $ciphertext = openssl_encrypt(
            $plainText, 'aes-128-gcm', base64_decode($aesKey),
            OPENSSL_RAW_DATA, $flippedIv, $tag
        );

        return base64_encode($ciphertext . $tag);
    }
}
