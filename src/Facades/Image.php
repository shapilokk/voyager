<?php

namespace TCG\Voyager\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Intervention Image v2 manager.
 *
 * Laravel 13 binds its own Illuminate\Image\ImageManager to the "image" key,
 * so Intervention\Image\Facades\Image can not be used anymore.
 *
 * @method static \Intervention\Image\Image make(mixed $data)
 */
class Image extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'voyager.image';
    }

    /**
     * Rotates the image according to its EXIF data. Without the exif extension the image
     * is kept as is instead of failing the whole upload.
     *
     * @param \Intervention\Image\Image $image
     *
     * @return \Intervention\Image\Image
     */
    public static function orientate($image)
    {
        return function_exists('exif_read_data') ? $image->orientate() : $image;
    }
}
