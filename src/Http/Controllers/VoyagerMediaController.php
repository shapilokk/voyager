<?php

namespace TCG\Voyager\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use TCG\Voyager\Events\MediaFileAdded;
use TCG\Voyager\Facades\Image;
use TCG\Voyager\Facades\Voyager;
use TCG\Voyager\Helpers\UploadGuard;

class VoyagerMediaController extends Controller
{
    /** @var string */
    private $filesystem;

    /** @var string */
    private $directory = '';

    public function __construct()
    {
        $this->filesystem = config('voyager.storage.disk');
    }

    public function index()
    {
        // Check permission
        $this->authorize('browse_media');

        return Voyager::view('voyager::media.index');
    }

    public function files(Request $request)
    {
        // Check permission
        $this->authorize('browse_media');

        $options = $request->details ?? [];
        $thumbnail_names = [];
        $thumbnails = [];
        if (!($options->hide_thumbnails ?? false)) {
            $thumbnail_names = array_column(($options['thumbnails'] ?? []), 'name');
        }

        $folder = $this->safePath($request->folder);

        $dir = $this->directory.$folder;

        $files = [];
        if (class_exists(\League\Flysystem\Plugin\ListWith::class)) {
            $storage = Storage::disk($this->filesystem)->addPlugin(new \League\Flysystem\Plugin\ListWith());
            $storageItems = $storage->listWith(['mimetype'], $dir);
        } else {
            $storage = Storage::disk($this->filesystem);
            $storageItems = $storage->listContents($dir)->sortByPath()->toArray();
        }

        foreach ($storageItems as $item) {
            if ($item['type'] == 'dir') {
                $files[] = [
                    'name'          => $item['basename'] ?? basename($item['path']),
                    'type'          => 'folder',
                    'path'          => Storage::disk($this->filesystem)->url($item['path']),
                    'relative_path' => $item['path'],
                    'items'         => '',
                    'last_modified' => '',
                ];
            } else {
                if (empty(pathinfo($item['path'], PATHINFO_FILENAME)) && !config('voyager.hidden_files')) {
                    continue;
                }
                // Its a thumbnail and thumbnails should be hidden
                if (Str::endsWith($item['path'], $thumbnail_names)) {
                    $thumbnails[] = $item;
                    continue;
                }
                $mime = 'file';
                if (class_exists(\League\MimeTypeDetection\ExtensionMimeTypeDetector::class)) {
                    $mime = (new \League\MimeTypeDetection\ExtensionMimeTypeDetector())->detectMimeTypeFromFile($item['path']);
                }
                $files[] = [
                    'name'          => $item['basename'] ?? basename($item['path']),
                    'filename'      => $item['filename'] ?? basename($item['path'], '.'.pathinfo($item['path'])['extension']),
                    'type'          => $item['mimetype'] ?? $mime,
                    'path'          => Storage::disk($this->filesystem)->url($item['path']),
                    'relative_path' => $item['path'],
                    'size'          => $item['size'] ?? $item->fileSize(),
                    'last_modified' => $item['timestamp'] ?? $item->lastModified(),
                    'thumbnails'    => [],
                ];
            }
        }

        foreach ($files as $key => $file) {
            foreach ($thumbnails as $thumbnail) {
                if ($file['type'] != 'folder' && Str::startsWith($thumbnail['filename'], $file['filename'])) {
                    $thumbnail['thumb_name'] = str_replace($file['filename'].'-', '', $thumbnail['filename']);
                    $thumbnail['path'] = Storage::disk($this->filesystem)->url($thumbnail['path']);
                    $files[$key]['thumbnails'][] = $thumbnail;
                }
            }
        }

        return response()->json($files);
    }

    public function new_folder(Request $request)
    {
        // Check permission
        $this->authorize('browse_media');

        $new_folder = $this->safePath($request->new_folder);
        $success = false;
        $error = '';

        if ($new_folder === '') {
            $error = __('voyager::media.error_creating_dir');
        } elseif (Storage::disk($this->filesystem)->exists($new_folder)) {
            $error = __('voyager::media.folder_exists_already');
        } elseif (Storage::disk($this->filesystem)->makeDirectory($new_folder)) {
            $success = true;
        } else {
            $error = __('voyager::media.error_creating_dir');
        }

        return compact('success', 'error');
    }

    public function delete(Request $request)
    {
        // Check permission
        $this->authorize('browse_media');

        $path = $this->safePath($request->path);
        $success = true;
        $error = '';

        foreach ($request->get('files') as $file) {
            $file_path = $this->safePath($path.'/'.$file['name']);
            if ($file_path === '' || $file_path === $this->safePath(config('voyager.media.path', '/'))) {
                // Never delete the root of the media disk
                $error = __('voyager::media.error_deleting_folder');
                $success = false;
            } elseif ($file['type'] == 'folder') {
                if (!Storage::disk($this->filesystem)->deleteDirectory($file_path)) {
                    $error = __('voyager::media.error_deleting_folder');
                    $success = false;
                }
            } elseif (!Storage::disk($this->filesystem)->delete($file_path)) {
                $error = __('voyager::media.error_deleting_file');
                $success = false;
            }
        }

        return compact('success', 'error');
    }

    public function move(Request $request)
    {
        // Check permission
        $this->authorize('browse_media');
        $path = $this->safePath($request->path);
        if (strpos(Str::finish((string) $request->destination, '/'), '/../') !== false) {
            // "../" option in the UI: move to the parent folder
            $dest = Str::contains($path, '/') ? Str::beforeLast($path, '/') : '';
        } else {
            $dest = $this->safePath($request->destination);
        }
        $path = $path === '' ? '' : $path.'/';
        $dest = $dest === '' ? '' : $dest.'/';

        $success = true;
        $error = '';

        foreach ($request->get('files') as $file) {
            $name = basename(str_replace('\\', '/', (string) $file['name']));
            $old_path = $path.$name;
            $new_path = $dest.$name;

            try {
                Storage::disk($this->filesystem)->move($old_path, $new_path);
            } catch (\Exception $ex) {
                $success = false;
                $error = $ex->getMessage();

                return compact('success', 'error');
            }
        }

        return compact('success', 'error');
    }

    public function rename(Request $request)
    {
        // Check permission
        $this->authorize('browse_media');

        $folderLocation = $request->folder_location;
        $filename = (string) $request->filename;
        $newFilename = (string) $request->new_filename;
        $success = false;
        $error = false;

        if (is_array($folderLocation)) {
            $folderLocation = implode('/', $folderLocation);
        }
        $location = $this->safePath($this->directory.'/'.$folderLocation);
        $isFolder = Storage::disk($this->filesystem)->directoryExists("{$location}/{$filename}");

        if (pathinfo($filename, PATHINFO_EXTENSION) !== pathinfo($newFilename, PATHINFO_EXTENSION)) {
            $error = __('voyager::media.error_renaming_ext');
        } elseif ($this->safePath($filename) !== $filename
            || $this->safePath($newFilename) !== $newFilename
            || Str::contains($filename.$newFilename, '/')
            || (!$isFolder && !UploadGuard::isAllowedFileName($newFilename))) {
            $error = __('voyager::generic.path_not_allowed');
        } else {

            if (!Storage::disk($this->filesystem)->exists("{$location}/{$newFilename}")) {
                if (Storage::disk($this->filesystem)->move("{$location}/{$filename}", "{$location}/{$newFilename}")) {
                    $success = true;
                } else {
                    $error = __('voyager::media.error_moving');
                }
            } else {
                $error = __('voyager::media.error_may_exist');
            }
        }

        return compact('success', 'error');
    }

    public function upload(Request $request)
    {
        // Check permission
        $this->authorize('browse_media');

        $details = json_decode($request->get('details') ?? '{}') ?: new \stdClass();

        try {
            $realPath = Storage::disk($this->filesystem)->path('/');

            // CVE-2024-55417: never trust the client extension
            $extension = UploadGuard::assertAllowedFile($request->file);
            $name = UploadGuard::sanitizeFileName(pathinfo($request->file->getClientOriginalName(), PATHINFO_FILENAME));
            $uploadPath = UploadGuard::normalizePath($request->upload_path);
            $uploadPath = $uploadPath === '' ? '' : $uploadPath.'/';

            $allowedMimeTypes = config('voyager.media.allowed_mimetypes', '*');
            if ($allowedMimeTypes != '*' && (is_array($allowedMimeTypes) && !in_array($request->file->getMimeType(), $allowedMimeTypes))) {
                throw new Exception(__('voyager::generic.mimetype_not_allowed'));
            }

            if (!$request->has('filename') || $request->get('filename') == 'null') {
                while (Storage::disk($this->filesystem)->exists($uploadPath.$name.'.'.$extension)) {
                    $name = get_file_name($name);
                }
            } else {
                $name = str_replace('{uid}', Auth::user()->getKey(), $request->get('filename'));
                if (Str::contains($name, '{date:')) {
                    $name = preg_replace_callback('/\{date:([^\/\}]*)\}/', function ($date) {
                        return \Carbon\Carbon::now()->format($date[1]);
                    }, $name);
                }
                if (Str::contains($name, '{random:')) {
                    $name = preg_replace_callback('/\{random:([0-9]+)\}/', function ($random) {
                        return Str::random($random[1]);
                    }, $name);
                }
                // The name may contain sub folders ({uid}/avatar), sanitize every segment
                $name = implode('/', array_map(
                    [UploadGuard::class, 'sanitizeFileName'],
                    explode('/', UploadGuard::normalizePath($name))
                ));
            }

            $file = $request->file->storeAs($uploadPath, $name.'.'.$extension, $this->filesystem);
            $file = preg_replace('#/+#', '/', $file);

            $imageMimeTypes = [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/bmp',
                'image/svg+xml',
            ];
            if (in_array($request->file->getMimeType(), $imageMimeTypes) && in_array($extension, UploadGuard::IMAGE_EXTENSIONS, true)) {
                $content = Storage::disk($this->filesystem)->get($file);
                $image = Image::make($content);

                if ($extension == 'gif') {
                    copy($request->file->getRealPath(), $realPath.$file);
                } else {
                    $image = Image::orientate($image);
                    // Generate thumbnails
                    if (property_exists($details, 'thumbnails') && is_array($details->thumbnails)) {
                        foreach ($details->thumbnails as $thumbnail_data) {
                            $type = $thumbnail_data->type ?? 'fit';
                            $thumbnail = Image::make(clone $image);
                            if ($type == 'fit') {
                                $thumbnail = $thumbnail->fit(
                                    $thumbnail_data->width,
                                    ($thumbnail_data->height ?? null),
                                    function ($constraint) {
                                        $constraint->aspectRatio();
                                    },
                                    ($thumbnail_data->position ?? 'center')
                                );
                            } elseif ($type == 'crop') {
                                $thumbnail = $thumbnail->crop(
                                    $thumbnail_data->width,
                                    $thumbnail_data->height,
                                    ($thumbnail_data->x ?? null),
                                    ($thumbnail_data->y ?? null)
                                );
                            } elseif ($type == 'resize') {
                                $thumbnail = $thumbnail->resize(
                                    $thumbnail_data->width,
                                    ($thumbnail_data->height ?? null),
                                    function ($constraint) use ($thumbnail_data) {
                                        $constraint->aspectRatio();
                                        if (!($thumbnail_data->upsize ?? true)) {
                                            $constraint->upsize();
                                        }
                                    }
                                );
                            }
                            if (
                                property_exists($details, 'watermark') &&
                                property_exists($details->watermark, 'source') &&
                                property_exists($thumbnail_data, 'watermark') &&
                                $thumbnail_data->watermark
                            ) {
                                $thumbnail = $this->addWatermarkToImage($thumbnail, $details->watermark);
                            }
                            $thumbnail_file = $uploadPath.$name.'-'.UploadGuard::sanitizeFileName($thumbnail_data->name ?? 'thumbnail').'.'.$extension;
                            Storage::disk($this->filesystem)->put($thumbnail_file, $thumbnail->encode($extension, ($details->quality ?? 90))->encoded);
                        }
                    }
                    // Add watermark to image
                    if (property_exists($details, 'watermark') && property_exists($details->watermark, 'source')) {
                        $image = $this->addWatermarkToImage($image, $details->watermark);
                    }
                    Storage::disk($this->filesystem)->put($file, $image->encode($extension, ($details->quality ?? 90))->encoded);
                }
            }

            $success = true;
            $message = __('voyager::media.success_uploaded_file');
            $path = preg_replace('/^public\//', '', $file);

            event(new MediaFileAdded($path));
        } catch (Exception $e) {
            $success = false;
            $message = $e->getMessage();
            $path = '';
        }

        return response()->json(compact('success', 'message', 'path'));
    }

    public function crop(Request $request)
    {
        // Check permission
        $this->authorize('browse_media');

        $createMode = $request->get('createMode') === 'true';
        $x = $request->get('x');
        $y = $request->get('y');
        $height = $request->get('height');
        $width = $request->get('width');

        try {
            $uploadPath = UploadGuard::normalizePath($request->upload_path);
            $originImageName = basename(str_replace('\\', '/', (string) $request->originImageName));
            $originImagePath = UploadGuard::normalizePath($uploadPath.'/'.$originImageName);

            if (!UploadGuard::isAllowedFileName($originImageName)
                || !in_array(strtolower(pathinfo($originImageName, PATHINFO_EXTENSION)), UploadGuard::IMAGE_EXTENSIONS, true)) {
                throw new Exception(__('voyager::generic.extension_not_allowed', ['extension' => UploadGuard::displayExtension($originImageName)]));
            }

            if ($createMode) {
                // create a new image with the cpopped data
                $fileNameParts = explode('.', $originImageName);
                array_splice($fileNameParts, count($fileNameParts) - 1, 0, 'cropped_'.time());
                $newImageName = implode('.', $fileNameParts);
                $destImagePath = UploadGuard::normalizePath($uploadPath.'/'.$newImageName);
            } else {
                // override the original image
                $destImagePath = $originImagePath;
            }

            $content = Storage::disk($this->filesystem)->get($originImagePath);
            $image = Image::make($content)->crop($width, $height, $x, $y);
            Storage::disk($this->filesystem)->put($destImagePath, $image->encode()->encoded);

            $success = true;
            $message = __('voyager::media.success_crop_image');
        } catch (Exception $e) {
            $success = false;
            $message = $e->getMessage();
        }

        return response()->json(compact('success', 'message'));
    }

    /**
     * Normalizes a path from the request, aborts on path traversal.
     *
     * @param string|null $path
     *
     * @return string
     */
    private function safePath($path)
    {
        try {
            return UploadGuard::normalizePath($path);
        } catch (Exception $e) {
            abort(400, $e->getMessage());
        }
    }

    private function addWatermarkToImage($image, $options)
    {
        // Read through the disk (normalized path) instead of an absolute path built from the request
        $watermark = Image::make(Storage::disk($this->filesystem)->get(UploadGuard::normalizePath($options->source)));
        // Resize watermark
        $width = $image->width() * (($options->size ?? 15) / 100);
        $watermark->resize($width, null, function ($constraint) {
            $constraint->aspectRatio();
        });

        return $image->insert(
            $watermark,
            ($options->position ?? 'top-left'),
            ($options->x ?? 0),
            ($options->y ?? 0)
        );
    }
}
