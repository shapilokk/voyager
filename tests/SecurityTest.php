<?php

namespace TCG\Voyager\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use TCG\Voyager\Helpers\UploadGuard;

/**
 * CVE-2024-55415, CVE-2024-55416, CVE-2024-55417.
 */
class SecurityTest extends TestCase
{
    protected $disk;

    public function setUp(): void
    {
        parent::setUp();

        Auth::loginUsingId(1);

        $this->disk = config('voyager.storage.disk');
        Storage::fake($this->disk);
    }

    protected function upload(UploadedFile $file, $uploadPath = '/', $extra = [])
    {
        $response = $this->json('POST', route('voyager.media.upload'), array_merge([
            'file'        => $file,
            'upload_path' => $uploadPath,
        ], $extra));

        return json_decode($response->response->getContent(), true);
    }

    protected function allFiles()
    {
        return Storage::disk($this->disk)->allFiles();
    }

    /**
     * A valid PNG with PHP code appended (polyglot), uploaded as .php.
     */
    protected function polyglot($name)
    {
        // fake files are removed together with their object, keep an own copy
        $path = tempnam(sys_get_temp_dir(), 'voyager');
        $fake = UploadedFile::fake()->image('test.png');
        copy($fake->getPathname(), $path);
        file_put_contents($path, '<?php system($_GET["c"]); ?>', FILE_APPEND);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    public function testMediaUploadRejectsPhpPolyglot()
    {
        foreach (['shell.php', 'shell.PHP', 'shell.phtml', 'shell.phar', '.htaccess', '.user.ini', 'shell.php5'] as $name) {
            $result = $this->upload($this->polyglot($name));

            $this->assertFalse($result['success'], $name);
        }

        $this->assertEquals([], $this->allFiles());
    }

    public function testMediaUploadRejectsActiveContentByDefault()
    {
        foreach (['xss.html', 'xss.svg', 'xss.js'] as $name) {
            $result = $this->upload(UploadedFile::fake()->createWithContent($name, '<script>alert(1)</script>'));

            $this->assertFalse($result['success'], $name);
        }

        $this->assertEquals([], $this->allFiles());
    }

    public function testMediaUploadHandlesDoubleExtension()
    {
        $result = $this->upload($this->polyglot('shell.php.png'));
        $this->assertFalse($result['success']);

        $result = $this->upload($this->polyglot('report.2024.png'));
        $this->assertTrue($result['success'], $result['message']);

        $this->assertEquals(['report-2024.png'], $this->allFiles());
    }

    public function testMediaUploadSanitizesCustomFilename()
    {
        $result = $this->upload($this->polyglot('image.png'), '/', ['filename' => '../../shell.php']);

        $this->assertFalse($result['success']);

        $result = $this->upload($this->polyglot('image.png'), '/', ['filename' => '{uid}/shell.php']);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertEquals(['1/shell-php.png'], $this->allFiles());
    }

    public function testMediaUploadRejectsPathTraversal()
    {
        $result = $this->upload(UploadedFile::fake()->image('test.png'), '../../');

        $this->assertFalse($result['success']);
        $this->assertEquals([], $this->allFiles());
    }

    public function testMediaUploadStillWorks()
    {
        $result = $this->upload(UploadedFile::fake()->image('Фото 1.png'), '/nested/');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertEquals(['nested/Фото-1.png'], $this->allFiles());
    }

    public function testMediaRenameCanNotChangeToExecutable()
    {
        Storage::disk($this->disk)->put('test.png', 'x');

        $response = $this->call('POST', route('voyager.media.rename'), [
            'folder_location' => '/',
            'filename'        => 'test.png',
            'new_filename'    => 'test.php.png',
        ]);

        $this->assertFalse(json_decode($response->getContent(), true)['success']);
        $this->assertEquals(['test.png'], $this->allFiles());
    }

    public function testMediaDeleteRejectsTraversalAndRoot()
    {
        Storage::disk($this->disk)->put('keep.png', 'x');

        $response = $this->call('POST', route('voyager.media.delete'), [
            'path'  => '/',
            'files' => [['name' => '../../', 'type' => 'folder']],
        ]);
        $this->assertEquals(400, $response->status());

        $response = $this->call('POST', route('voyager.media.delete'), [
            'path'  => '/',
            'files' => [['name' => '', 'type' => 'folder']],
        ]);
        $this->assertFalse(json_decode($response->getContent(), true)['success']);

        $this->assertEquals(['keep.png'], $this->allFiles());
    }

    public function testCompassRejectsFilesOutsideLogs()
    {
        $this->app['config']->set('voyager.compass_in_production', true);

        $outside = base_path('composer.json');
        $this->assertFileExists($outside);

        foreach (['download', 'log', 'del'] as $param) {
            foreach ([$outside, '../../composer.json', '..\\..\\composer.json'] as $file) {
                $response = $this->call('GET', route('voyager.compass.index'), [$param => base64_encode($file)]);

                $this->assertEquals(404, $response->status(), $param.' '.$file);
            }
        }

        $this->assertFileExists($outside);
    }

    public function testCompassDeleteMessageIsNotReflected()
    {
        $this->app['config']->set('voyager.compass_in_production', true);

        $response = $this->call('GET', route('voyager.compass.index'), ['del' => base64_encode('<img src=x onerror=alert(1)>')]);

        $this->assertEquals(404, $response->status());
    }

    public function testFlashMessageIsEscaped()
    {
        $response = $this->withSession([
            'message'    => '<img src=x onerror=alert(1)>',
            'alert-type' => 'success',
        ])->call('GET', route('voyager.dashboard'));

        $this->assertStringNotContainsString('<img src=x onerror', $response->getContent());
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $response->getContent());
    }

    public function testUploadGuard()
    {
        $this->assertTrue(UploadGuard::isAllowedFileName('photo.JPG'));
        $this->assertTrue(UploadGuard::isAllowedFileName('report.2024.pdf'));
        $this->assertTrue(UploadGuard::isAllowedFileName('example.com.pdf'));
        $this->assertFalse(UploadGuard::isAllowedFileName('shell.php'));
        $this->assertFalse(UploadGuard::isAllowedFileName('shell.php.jpg'));
        $this->assertFalse(UploadGuard::isAllowedFileName('shell.pHp7.jpg'));
        $this->assertFalse(UploadGuard::isAllowedFileName('noextension'));
        $this->assertFalse(UploadGuard::isAllowedFileName('dir/photo.jpg'));
        $this->assertFalse(UploadGuard::isAllowedFileName('photo.jpg:$DATA'));

        $this->app['config']->set('voyager.media.allowed_extensions', '*');
        $this->assertTrue(UploadGuard::isAllowedFileName('page.html'));
        $this->assertFalse(UploadGuard::isAllowedFileName('shell.php'));

        $this->assertEquals('a/b', UploadGuard::normalizePath('/a//./b/'));
        $this->assertEquals('', UploadGuard::normalizePath('/'));
        $this->assertEquals('shell-php', UploadGuard::sanitizeFileName('shell.php'));
        $this->assertEquals('Отчёт-2024', UploadGuard::sanitizeFileName('Отчёт 2024'));

        $this->expectException(\Exception::class);
        UploadGuard::normalizePath('a/../../b');
    }
}
