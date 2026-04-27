<?php

namespace ScriptDevelop\WhatsappManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ScriptDevelop\WhatsappManager\Services\TemplateMediaCompressionService;
use ScriptDevelop\WhatsappManager\Support\WhatsappModelResolver;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CompressTemplateMediaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Model $version,
        public string $mediaUrl,
        public string $mediaType,
        public ?int $maxBytes = null,
        public int $maxAttempts = 3,
    ) {
        if( empty($this->maxBytes) ) {
            $this->maxBytes = (int) config('whatsapp.media.max_file_size.video', 16 * 1024 * 1024);
        }
    }

    /**
     * @return array{success: bool, compressed: bool, final_size: int|null, message: string|null}
     */
    public function handle(TemplateMediaCompressionService $compressionService): array
    {
        try {
            if( empty($this->mediaType) ) {
                Log::channel('whatsapp')->error('Falta el tipo de media', [
                    'version_id' => $this->version->version_id,
                    'media_url'  => $this->mediaUrl,
                ]);
                return [
                    'success' => false,
                    'compressed' => false,
                    'final_size' => null,
                    'message' => 'Falta el tipo de media',
                ];
            }

            if( !config('whatsapp.package_ffmpeg_installed', false) || !config('whatsapp.package_php_gd_installed', false) ) {
                Log::channel('whatsapp')->error('El paquete ffmpeg o PHP GD está configurado como instalado pero no se encuentra disponible en el sistema. La compresión de media no funcionará.', [
                    'version_id' => $this->version->version_id,
                    'media_url'  => $this->mediaUrl,
                ]);

                return [
                    'success' => false,
                    'compressed' => false,
                    'final_size' => null,
                    'message' => 'El paquete ffmpeg o PHP GD está configurado como instalado pero no se encuentra disponible en el sistema. La compresión de media no funcionará.',
                ];
            }

            $this->mediaType = Str::lower($this->mediaType);

            // Obtener la ruta de almacenamiento configurada desde la config
            $directory = config('whatsapp.media.storage_path.'.$this->mediaType.'s'); // Por defecto pluralizar el tipo de media

            if (!$directory) {
                Log::channel('whatsapp')->info('No se ha configurado una ruta de almacenamiento para el tipo de media', [
                    'version_id'       => $this->version->version_id,
                    'mediaType'        => $this->mediaType,
                ]);
                return ['success' => false, 'compressed' => false, 'final_size' => null, 'message' => 'No se ha configurado una ruta de almacenamiento para el tipo de media'];
            }

            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            if (Str::endsWith($directory, '/')) {
                $directory = rtrim($directory, '/');
            }

            $baseFileName = "{$this->version->version_id}_{$this->mediaType}";
            $tempPath = "{$directory}/{$baseFileName}.part";

            // Descargar en streaming a un archivo temporal para evitar consumo alto de memoria
            $response = Http::withOptions(['sink' => $tempPath])->get($this->mediaUrl);
            if ($response->successful()) {
                $extension    = $this->getFileExtension($response->header('Content-Type'));
                $this->mediaType    = Str::lower($this->mediaType);
                $fileName = "{$this->version->version_id}_{$this->mediaType}.{$extension}";
                $filePath = "{$directory}/{$fileName}";

                if (!file_exists($tempPath)) {
                    Log::channel('whatsapp')->error('No se pudo guardar temporalmente el media de plantilla', [
                        'version_id' => $this->version->version_id,
                        'media_url'  => $this->mediaUrl,
                        'temp_path'  => $tempPath
                    ]);
                    return ['success' => false, 'compressed' => false, 'final_size' => null, 'message' => 'No se pudo guardar temporalmente el media de plantilla'];
                }

                $writtenBytes = filesize($tempPath);
                if ($writtenBytes === false) {
                    Log::channel('whatsapp')->error('No se pudo determinar el tamaño del media descargado', [
                        'version_id' => $this->version->version_id,
                        'media_url'  => $this->mediaUrl,
                        'temp_path'  => $tempPath
                    ]);
                    return ['success' => false, 'compressed' => false, 'final_size' => null, 'message' => 'No se pudo determinar el tamaño del media descargado'];
                }

                // En Windows, rename() puede fallar si el destino ya existe.
                if (file_exists($filePath) && !unlink($filePath)) {
                    Log::channel('whatsapp')->error('No se pudo reemplazar el archivo existente', [
                        'version_id' => $this->version->version_id,
                        'media_url'  => $this->mediaUrl,
                        'file_path'  => $filePath
                    ]);
                    return ['success' => false, 'compressed' => false, 'final_size' => null, 'message' => 'No se pudo reemplazar el archivo existente'];
                }

                if (!rename($tempPath, $filePath)) {
                    Log::channel('whatsapp')->error('No se pudo mover el archivo temporal al destino final', [
                        'version_id' => $this->version->version_id,
                        'media_url'  => $this->mediaUrl,
                        'temp_path'  => $tempPath,
                        'file_path'  => $filePath
                    ]);
                    return ['success' => false, 'compressed' => false, 'final_size' => null, 'message' => 'No se pudo mover el archivo temporal al destino final'];
                }

                // Convertir el path absoluto a relativo para Storage::url
                $relativePath = str_replace(storage_path('app/public/'), '', $directory . '/' . $fileName);

                // Guardar la URL pública en el campo header_media_url de la versión
                $publicPath = Storage::url($relativePath);

                $mediaFile = WhatsappModelResolver::template_media_file()->create([
                    'version_id' => $this->version->version_id,
                    'media_type' => $this->mediaType,
                    'file_name'  => $fileName,
                    'mime_type'  => $response->header('Content-Type'),
                    'url'        => $publicPath,
                    'file_size'  => $writtenBytes,
                ]);

                Log::channel('whatsapp')->info('Template version header media saved', [
                    'version_id'       => $this->version->version_id,
                    'mediaType'        => $this->mediaType,
                    'header_media_url' => $publicPath,
                    'media_file_id'    => $mediaFile->media_file_id
                ]);

                $maxTemplateMediaSize = (int) config('whatsapp.media.max_file_size.video', 16 * 1024 * 1024);

                if( $writtenBytes > $maxTemplateMediaSize ) {
                    if( empty($filePath) ) {
                        return [
                            'success' => false,
                            'compressed' => false,
                            'final_size' => null,
                            'message' => 'Falta la ruta del archivo.',
                        ];
                    }

                    if( !file_exists($filePath) ) {
                        return [
                            'success' => false,
                            'compressed' => false,
                            'final_size' => null,
                            'message' => 'El archivo no existe para comprimir: ' . $filePath,
                        ];
                    }

                    $currentSize = filesize($filePath);
                    if ($currentSize === false) {
                        return [
                            'success' => false,
                            'compressed' => false,
                            'final_size' => null,
                            'message' => "No se pudo determinar el tamaño del archivo: {$filePath}",
                        ];
                    }

                    if( $currentSize <= $this->maxBytes ) {
                        return [
                            'success' => true,
                            'compressed' => false,
                            'final_size' => $currentSize,
                            'message' => 'El archivo ya está dentro del límite de tamaño, no se necesita compresión.',
                        ];
                    }

                    $compressionResult = $compressionService->compressIfNeeded(
                        $filePath,
                        $this->mediaType,
                        $this->maxBytes,
                        $this->maxAttempts
                    );

                    if (($compressionResult['compressed'] ?? false) && !empty($compressionResult['final_size'])) {
                        $mediaFile->update([
                            'file_size' => (string) $compressionResult['final_size'],
                        ]);
                    }

                    if (!($compressionResult['success'] ?? false) and
                        config('whatsapp.allow_compression_multimedia_template', false) === true
                    ) {
                        Log::channel('whatsapp')->warning('Template version media compression failed. Version marked as REJECTED.', [
                            'version_id' => $this->version->version_id,
                            'media_file_id' => $mediaFile->template_media_file_id,
                            'media_type' => $this->mediaType,
                            'file_path' => $filePath,
                            'max_size_bytes' => $this->maxBytes,
                            'final_size_bytes' => $compressionResult['final_size'] ?? null,
                            'error_message' => $compressionResult['message'] ?? null,
                        ]);
                    }

                    return $compressionResult;
                }
                else{
                    Log::channel('whatsapp')->info('El archivo está dentro del límite de tamaño, no se necesita compresión.', [
                        'version_id' => $this->version->version_id,
                        'media_file_id' => $mediaFile->template_media_file_id,
                        'media_type' => $this->mediaType,
                        'file_path' => $filePath,
                        'file_size_bytes' => $writtenBytes,
                    ]);

                    return [
                        'success' => true,
                        'compressed' => false,
                        'final_size' => $writtenBytes,
                        'message' => 'El archivo está dentro del límite de tamaño, no se necesita compresión.',
                    ];
                }

            } else {
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }

                Log::channel('whatsapp')->warning('Failed to download template header media', [
                    'version_id' => $this->version->version_id,
                    'media_url'  => $this->mediaUrl,
                    'status'     => $response->status()
                ]);

                return ['success' => false, 'compressed' => false, 'final_size' => null, 'message' => 'Error al descargar el media de plantilla: HTTP ' . $response->status()];
            }
        } catch (\Exception $e) {
            if (isset($tempPath) && file_exists($tempPath)) {
                @unlink($tempPath);
            }

            Log::channel('whatsapp')->error('Error saving template version header media', [
                'version_id'    => $this->version->version_id,
                'media_url'     => $this->mediaUrl,
                'error_message' => $e->getMessage()
            ]);
            return ['success' => false, 'compressed' => false, 'final_size' => null, 'message' => 'Error al guardar el media de plantilla: ' . $e->getMessage()];
        }
    }

    private function getFileExtension(?string $mimeType): string
    {
        //Prevenir que el mimetype sea parecido a esto: "audio/ogg; codecs=opus", así son las notas de voz
        if ($mimeType && str_contains($mimeType, ';')) {
            $mimeType = explode(';', $mimeType)[0];
        }

        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'audio/ogg', 'audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr' => 'ogg',
            'video/mp4', 'video/3gpp' => 'mp4',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt',
            'image/webp' => 'webp',
            default => $this->logUnknownMimeType($mimeType),
        };
    }

    private function logUnknownMimeType(?string $mimeType): string
    {
        Log::channel('whatsapp')->warning("Extensión desconocida para MIME type: {$mimeType}, se usará bin como extensión por defecto.", [
            'version_id' => $this->version->version_id,
            'media_url'  => $this->mediaUrl,
            'mime_type'  => $mimeType,
        ]);

        return 'bin';
    }
}
