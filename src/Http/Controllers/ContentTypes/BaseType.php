<?php

namespace TCG\Voyager\Http\Controllers\ContentTypes;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use TCG\Voyager\Helpers\UploadGuard;

abstract class BaseType
{
    /**
     * @var Request
     */
    protected $request;

    /**
     * @var
     */
    protected $slug;

    /**
     * @var
     */
    protected $row;

    /**
     * @var
     */
    protected $options;

    /**
     * Password constructor.
     *
     * @param Request $request
     * @param $slug
     * @param $row
     */
    public function __construct(Request $request, $slug, $row, $options)
    {
        $this->request = $request;
        $this->slug = $slug;
        $this->row = $row;
        $this->options = $options;
    }

    /**
     * @return mixed
     */
    abstract public function handle();

    /**
     * Rejects files with a dangerous or not allowed extension (CVE-2024-55417).
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param array|null                    $allowed
     *
     * @throws ValidationException
     *
     * @return string the lower-case extension
     */
    protected function guardUpload($file, $allowed = null)
    {
        try {
            return UploadGuard::assertAllowedFile($file, $allowed);
        } catch (Exception $e) {
            throw ValidationException::withMessages([$this->row->field => $e->getMessage()]);
        }
    }
}
