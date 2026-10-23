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
}
