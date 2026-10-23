<?php

namespace TCG\Voyager\Helpers;

use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Upload hardening: CVE-2024-55417 (arbitrary file write / RCE via media upload).
 */
class UploadGuard
{
    /**
     * Extensions that may be executed or interpreted by a web server.
     * They are always rejected and can not be enabled through the config.
     */
    public const DENIED_EXTENSIONS = [
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phps', 'pht', 'phtm', 'phtml', 'phar', 'pgif',
        'shtml', 'shtm', 'stm', 'htaccess', 'htpasswd', 'ini', 'cgi', 'fcgi', 'pl', 'pm', 'py', 'pyc', 'rb',
        'sh', 'bash', 'zsh', 'asp', 'aspx', 'ascx', 'ashx', 'asmx', 'asa', 'cer', 'config', 'jsp', 'jspx', 'jsw', 'jsv', 'jspf',
        'cfm', 'cfml', 'exe', 'com', 'bat', 'cmd', 'dll', 'so', 'vbs', 'ps1', 'jar', 'war',
    ];

    /**
     * Allowed extensions when "voyager.media.allowed_extensions" is not set.
     * Active content (html, svg, js, xml) is not allowed by default because it is
     * served from the application origin and leads to stored XSS.
     */
    public const DEFAULT_ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'avif',
        'pdf', 'txt', 'csv', 'rtf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        'zip', 'rar', '7z', 'gz',
        'mp3', 'wav', 'ogg', 'mp4', 'webm', 'mov', 'avi', 'mkv',
    ];

    /**
     * Inner extensions that a misconfigured server may still execute (shell.php.jpg).
     */
    public const DENIED_INNER_EXTENSIONS_PATTERN = '/^(php\d*|phps|pht|phtm|phtml|phar|shtml?|cgi|fcgi|pl|py|asp|aspx|jsp|jspx)$/';

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /**
     * @param string $extension
     *
     * @return bool
     */
    public static function isAllowedExtension($extension)
    {
        $extension = strtolower(trim((string) $extension));

        if ($extension === '' || in_array($extension, self::DENIED_EXTENSIONS, true)) {
            return false;
        }

        $allowed = config('voyager.media.allowed_extensions', self::DEFAULT_ALLOWED_EXTENSIONS);
        if ($allowed === '*') {
            return true;
        }

        return in_array($extension, array_map('strtolower', (array) $allowed), true);
    }

    /**
     * Checks the extension and every inner "extension" (shell.php.jpg) of a file name.
     *
     * @param string $filename
     *
     * @return bool
     */
    public static function isAllowedFileName($filename)
    {
        $filename = (string) $filename;

        if ($filename === '' || preg_match('#[/\\\\\x00-\x1f:]#', $filename)) {
            return false;
        }

        $parts = explode('.', strtolower($filename));
        $extension = array_pop($parts);
        array_shift($parts);

        foreach ($parts as $part) {
            if (preg_match(self::DENIED_INNER_EXTENSIONS_PATTERN, $part)) {
                return false;
            }
        }

        return self::isAllowedExtension($extension);
    }

    /**
     * Returns a safe lower-case extension of an uploaded file or throws.
     *
     * @param UploadedFile $file
     * @param array|null   $allowed restrict to the given extensions (e.g. images only)
     *
     * @throws Exception
     *
     * @return string
     */
    public static function assertAllowedFile($file, $allowed = null)
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (!self::isAllowedFileName($file->getClientOriginalName())
            || ($allowed !== null && !in_array($extension, $allowed, true))) {
            throw new Exception(__('voyager::generic.extension_not_allowed', ['extension' => self::displayExtension($file->getClientOriginalName())]));
        }

        return $extension;
    }

    /**
     * Extension of a client file name that is safe to show in a message (rendered as HTML by toastr).
     *
     * @param string $filename
     *
     * @return string
     */
    public static function displayExtension($filename)
    {
        return Str::limit(preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION))), 20, '');
    }

    /**
     * Makes a file name (without extension) safe: no dots, slashes or control chars.
     *
     * @param string $name
     *
     * @return string
     */
    public static function sanitizeFileName($name)
    {
        $name = preg_replace('/[^\p{L}\p{N}_\-]+/u', '-', (string) $name);
        $name = trim($name, '-');

        return $name === '' ? Str::random(20) : Str::limit($name, 150, '');
    }

    /**
     * Normalizes a relative path inside the storage disk and rejects traversal.
     *
     * @param string|null $path
     *
     * @throws Exception
     *
     * @return string
     */
    public static function normalizePath($path)
    {
        $path = str_replace('\\', '/', (string) $path);

        if (str_contains($path, "\0")) {
            throw new Exception(__('voyager::generic.path_not_allowed'));
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new Exception(__('voyager::generic.path_not_allowed'));
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }
}
