<?php

namespace app\common\service\storage;

/**
 * Recover an extension only when a client sends a multipart file without one.
 * The MIME value comes from the uploaded bytes, never from the request header.
 */
class UploadExtension
{
    public static function fromContent(string $path): string
    {
        if (!is_file($path) || !function_exists('finfo_open')) {
            return '';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }
        try {
            $mime = strtolower((string)finfo_file($finfo, $path));
        } finally {
            finfo_close($finfo);
        }

        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            'video/mp4', 'application/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/x-matroska' => 'mkv',
            'video/x-msvideo' => 'avi',
            'video/x-ms-wmv' => 'wmv',
            'video/mpeg' => 'mpg',
            'video/3gpp' => '3gp',
            'video/x-flv' => 'flv',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave' => 'wav',
            'audio/ogg', 'application/ogg' => 'ogg',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/aac' => 'aac',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'audio/opus' => 'opus',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/zip' => 'zip',
            'application/x-rar', 'application/vnd.rar' => 'rar',
            'application/x-7z-compressed' => '7z',
            'application/gzip', 'application/x-gzip' => 'gz',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            default => '',
        };
    }
}
