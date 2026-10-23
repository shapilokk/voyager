<?php

namespace TCG\Voyager\Tests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use TCG\Voyager\Models\Menu;
use TCG\Voyager\Models\MenuItem;
use TCG\Voyager\VoyagerServiceProvider;

/**
 * Laravel 13 config: the cache does not unserialize PHP classes by default.
 */
class MenuCacheTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // in an application the config is loaded before the providers are registered
        config(['cache.default' => 'file', 'cache.serializable_classes' => false]);
        call_protected_method(new VoyagerServiceProvider($this->app), 'allowCachedMenuClasses');
        app('cache')->forgetDriver('file');
    }

    public function testMenuClassesAreAllowed()
    {
        $allowed = config('cache.serializable_classes');

        $this->assertContains(Menu::class, $allowed);
        $this->assertContains(MenuItem::class, $allowed);
    }

    public function testExistingListIsKept()
    {
        config(['cache.serializable_classes' => [\ArrayObject::class]]);
        call_protected_method(new VoyagerServiceProvider($this->app), 'allowCachedMenuClasses');

        $this->assertContains(\ArrayObject::class, config('cache.serializable_classes'));
        $this->assertContains(Menu::class, config('cache.serializable_classes'));
    }

    public function testAllowAllIsNotChanged()
    {
        config(['cache.serializable_classes' => true]);
        call_protected_method(new VoyagerServiceProvider($this->app), 'allowCachedMenuClasses');

        $this->assertTrue(config('cache.serializable_classes'));
    }

    public function testCachedMenuIsRestored()
    {
        Auth::loginUsingId(1);
        Cache::forget('voyager_menu_admin');

        $first = Menu::display('admin', '_json');
        $second = Menu::display('admin', '_json');

        $this->assertInstanceOf(Menu::class, Cache::get('voyager_menu_admin'));
        $this->assertNotEmpty((string) $second);
        $this->assertEquals((string) $first, (string) $second);

        Cache::forget('voyager_menu_admin');
    }

    public function testOtherClassesAreStillNotUnserialized()
    {
        Cache::put('voyager_test_object', new \ArrayObject([1]));

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, Cache::get('voyager_test_object'));

        Cache::forget('voyager_test_object');
    }
}
