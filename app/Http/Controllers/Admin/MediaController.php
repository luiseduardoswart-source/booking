<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaAlt;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaController extends Controller implements HasMiddleware
{
    /** Only the owner and whoever has the Media manager permission (see Access). */
    public static function middleware(): array
    {
        return [
            function ($request, $next) {
                abort_unless(\App\Support\Access::mayMediaFiles(auth()->user()), 403);

                return $next($request);
            },
        ];
    }

    private const ROOT = 'media';

    private const ALLOWED = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'pdf', 'zip', 'mp4', 'webm', 'doc', 'docx', 'xls', 'xlsx'];

    private const IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];

    public function index()
    {
        return view('admin.media.index');
    }

    public function list(Request $request)
    {
        $path = $this->cleanPath((string) $request->query('path'));

        if ($this->isAccommodationPath($path)) {
            ['dirs' => $dirs, 'files' => $files] = $this->accommodationList($path);

            return response()->json([
                'ok' => true,
                'path' => $path,
                'breadcrumbs' => $this->breadcrumbs($path),
                'dirs' => $dirs,
                'files' => $files,
            ]);
        }

        $disk = Storage::disk('public');
        $full = self::ROOT.($path ? '/'.$path : '');

        if (! $disk->exists($full)) {
            $disk->makeDirectory($full);
        }

        $dirs = collect($disk->directories($full))->map(fn ($d) => [
            'name' => basename($d),
            'path' => trim(Str::after($d, self::ROOT), '/'),
        ])->sortBy('name')->values();

        // The property photos live outside the media folder, but belong in
        // this overview: at the top as a folder of their own.
        if ($path === '' && Property::withoutGlobalScope('withoutMaster')->has('media')->exists()) {
            $dirs = collect([['name' => __("Property photos"), 'path' => self::ACCOMMODATIONS]])
                ->concat($dirs)->values();
        }

        $files = collect($disk->files($full))->map(function ($f) use ($disk) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));

            return [
                'name' => basename($f),
                'path' => $f,
                'url' => $disk->url($f),
                'size' => $disk->size($f),
                'is_image' => in_array($ext, self::IMAGES),
                'modified' => date('Y-m-d H:i', $disk->lastModified($f)),
                // alt: set manually or automatically from the file name
                'alt' => MediaAlt::forPath($f),
                'alt_custom' => MediaAlt::stored($f) !== null,
            ];
        })->sortByDesc('modified')->values();

        return response()->json([
            'ok' => true,
            'path' => $path,
            'breadcrumbs' => $this->breadcrumbs($path),
            'dirs' => $dirs,
            'files' => $files,
        ]);
    }

    public function upload(Request $request)
    {
        $request->validate([
            'path' => 'nullable|string',
            'files' => 'required|array',
            'files.*' => 'file|max:102400', // 100 MB — pdf, word and mp4 can be large (PHP limits apply too)
        ]);

        $path = $this->cleanPath((string) $request->input('path'));

        if ($this->isAccommodationPath($path)) {
            return $this->uploadAccommodationPhotos($path, $request);
        }

        $disk = Storage::disk('public');
        $uploaded = [];

        foreach ($request->file('files') as $file) {
            $ext = strtolower($file->getClientOriginalExtension());
            if (! in_array($ext, self::ALLOWED)) {
                continue;
            }
            $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
            $name = $name ?: 'file';
            $target = self::ROOT.($path ? '/'.$path : '');
            $filename = "{$name}.{$ext}";
            $i = 1;
            while ($disk->exists("{$target}/{$filename}")) {
                $filename = "{$name}-{$i}.{$ext}";
                $i++;
            }
            $stored = $file->storeAs($target, $filename, 'public');
            $uploaded[] = [
                'path' => $stored,
                'url' => $disk->url($stored),
                'name' => $filename,
                'alt' => MediaAlt::forPath($stored),
            ];
        }

        return response()->json(['ok' => true, 'uploaded' => $uploaded]);
    }

    /** Save an edited image from the photo editor (as a copy or overwrite). */
    public function editSave(Request $request)
    {
        $data = $request->validate([
            'path' => 'required|string',
            'file' => 'required|file|max:102400',
            'overwrite' => 'boolean',
        ]);

        if ($this->isAccommodationPath($data['path'])) {
            return $this->saveAccommodationPhoto($data['path'], $request);
        }

        $disk = Storage::disk('public');
        $target = $this->assertInRoot($data['path']);
        abort_unless($disk->exists($target), 404);

        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        abort_unless(in_array($ext, self::IMAGES), 422);

        if ($request->boolean('overwrite')) {
            $new = $target;
        } else {
            $dir = dirname($target);
            $base = pathinfo($target, PATHINFO_FILENAME);
            $new = "{$dir}/{$base}-edited.{$ext}";
            $i = 1;
            while ($disk->exists($new)) {
                $new = "{$dir}/{$base}-edited-{$i}.{$ext}";
                $i++;
            }
        }
        $disk->put($new, file_get_contents($request->file('file')->getRealPath()));

        return response()->json(['ok' => true, 'path' => $new, 'url' => $disk->url($new)]);
    }

    public function folder(Request $request)
    {
        $data = $request->validate(['path' => 'nullable|string', 'name' => 'required|string|max:100']);
        $path = $this->cleanPath((string) ($data['path'] ?? ''));
        $name = Str::slug($data['name']) ?: 'map';
        if ($this->isAccommodationPath($path)) {
            return response()->json([
                'ok' => false,
                'message' => __('You do not create folders here: every property has its own photo folder.'),
            ], 422);
        }

        Storage::disk('public')->makeDirectory(self::ROOT.($path ? '/'.$path : '').'/'.$name);

        return response()->json(['ok' => true]);
    }

    public function delete(Request $request)
    {
        $data = $request->validate(['path' => 'required|string', 'type' => 'required|in:file,dir']);

        if ($this->isAccommodationPath($data['path'])) {
            // A property is not a folder you can throw away; only loose photos.
            if ($data['type'] === 'dir') {
                return response()->json([
                    'ok' => false,
                    'message' => __('You remove a property at the property itself, not here.'),
                ], 422);
            }

            $this->accommodationPhoto($data['path'])->delete();

            return response()->json(['ok' => true]);
        }

        $disk = Storage::disk('public');
        $target = $this->assertInRoot($data['path']);

        if ($data['type'] === 'dir') {
            $disk->deleteDirectory($target);
        } else {
            $disk->delete($target);
        }
        MediaAlt::forget($target);

        return response()->json(['ok' => true]);
    }

    /** Save manual alt text; clearing it resets it to the file name. */
    public function alt(Request $request)
    {
        $data = $request->validate([
            'path' => 'required|string',
            'alt' => 'nullable|string|max:255',
        ]);

        if ($this->isAccommodationPath($data['path'])) {
            $media = $this->accommodationPhoto($data['path']);
            $text = trim((string) ($data['alt'] ?? ''));

            $text === ''
                ? $media->forgetCustomProperty('label')
                : $media->setCustomProperty('label', $text);
            $media->save();

            $file = $this->accommodationFile($media->fresh());

            return response()->json([
                'ok' => true,
                'alt' => $file['alt'],
                'alt_custom' => $file['alt_custom'],
            ]);
        }

        $target = $this->assertInRoot($data['path']);
        abort_unless(Storage::disk('public')->exists($target), 404);

        MediaAlt::put($target, $data['alt'] ?? '');

        return response()->json([
            'ok' => true,
            'alt' => MediaAlt::forPath($target),
            'alt_custom' => MediaAlt::stored($target) !== null,
        ]);
    }

    public function rename(Request $request)
    {
        $data = $request->validate(['path' => 'required|string', 'name' => 'required|string|max:150']);

        if ($this->isAccommodationPath($data['path'])) {
            return $this->renameAccommodationPhoto($data['path'], $data['name']);
        }

        $disk = Storage::disk('public');
        $target = $this->assertInRoot($data['path']);
        abort_unless($disk->exists($target), 404);

        $ext = pathinfo($target, PATHINFO_EXTENSION);
        $base = Str::slug(pathinfo($data['name'], PATHINFO_FILENAME)) ?: 'file';
        $new = dirname($target).'/'.$base.($ext ? '.'.$ext : '');
        if ($new !== $target) {
            // do not fail silently: the user must see that the name is already taken
            if ($disk->exists($new)) {
                return response()->json(['ok' => false, 'message' => __('A file with that name already exists in this folder.')], 422);
            }
            $disk->move($target, $new);
            MediaAlt::move($target, $new);
        }

        return response()->json([
            'ok' => true,
            'path' => $new,
            'url' => $disk->url($new),
            'alt' => MediaAlt::forPath($new),
        ]);
    }

    /** @return array<int, array{name: string, path: string}> */
    private function breadcrumbs(string $path): array
    {
        $crumbs = [];
        $acc = [];
        foreach (array_filter(explode('/', $path)) as $segment) {
            $acc[] = $segment;
            $crumbs[] = ['name' => $segment, 'path' => implode('/', $acc)];
        }

        return $crumbs;
    }

    private function cleanPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $segments = array_filter(explode('/', $path), fn ($s) => $s !== '' && $s !== '.' && $s !== '..');

        return implode('/', $segments);
    }

    /**
     * Delete several files and folders at once.
     *
     * All paths are checked first and only then is anything thrown away: if a single
     * path is outside the media folder, the whole request is rejected instead of half
     * of it being gone already.
     */
    public function deleteMany(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:500',
            'items.*.path' => 'required|string',
            'items.*.type' => 'required|in:file,dir',
        ]);

        // Handle property photos separately first: they live in the database
        // and not in the media folder, so assertInRoot would reject them.
        $targets = [];
        $photos = [];

        foreach ($data['items'] as $item) {
            if ($this->isAccommodationPath($item['path'])) {
                // We skip a property folder itself; only loose photos.
                if ($item['type'] === 'file') {
                    $photos[] = $this->accommodationPhoto($item['path']);
                }

                continue;
            }

            $targets[] = ['path' => $this->assertInRoot($item['path']), 'type' => $item['type']];
        }

        $disk = Storage::disk('public');
        $deleted = 0;
        $missing = [];

        foreach ($photos as $photo) {
            $photo->delete();
            $deleted++;
        }

        foreach ($targets as $target) {
            if (! $disk->exists($target['path'])) {
                $missing[] = $target['path'];   // already gone (or deleted by someone else)

                continue;
            }
            if ($target['type'] === 'dir') {
                $disk->deleteDirectory($target['path']);
            } else {
                $disk->delete($target['path']);
            }
            MediaAlt::forget($target['path']);
            $deleted++;
        }

        return response()->json(['ok' => true, 'deleted' => $deleted, 'missing' => $missing]);
    }

    /**
     * Property photos belong to a property and are managed by Spatie's
     * medialibrary: a file on disk plus a database row with caption,
     * order and resized versions. So they are not in the media folder.
     *
     * To still be able to manage them from the Media manager, we show them as a
     * virtual folder. The paths the browser sends back look like this:
     *
     *   accommodations                    the folder with all properties
     *   accommodations/<property-id>      the photos of one property
     *   accommodations/<property-id>/<id> one photo
     *
     * Every operation below works on the database row and the file, so the
     * website never points to a photo that no longer exists.
     */
    private const ACCOMMODATIONS = 'accommodations';

    private function isAccommodationPath(?string $path): bool
    {
        $path = $this->withoutMediaPrefix((string) $path);

        return $path === self::ACCOMMODATIONS || str_starts_with($path, self::ACCOMMODATIONS.'/');
    }

    private function withoutMediaPrefix(string $path): string
    {
        $path = trim(str_replace(chr(92), '/', $path), '/');

        return Str::startsWith($path, self::ROOT.'/') ? Str::after($path, self::ROOT.'/') : $path;
    }

    /**
     * @return array{0: string, 1: ?int, 2: ?int} [kind, property-id, photo-id]
     */
    private function accommodationPath(string $path): array
    {
        $share = array_values(array_filter(explode('/', $this->withoutMediaPrefix($path))));

        return match (count($share)) {
            1 => ['root', null, null],
            2 => ['accommodation', (int) $share[1], null],
            3 => ['photo', (int) $share[1], (int) $share[2]],
            default => ['unknown', null, null],
        };
    }

    private function accommodationPhoto(string $path): Media
    {
        [$kind, $accommodationId, $photoId] = $this->accommodationPath($path);
        abort_unless($kind === 'photo', 422);

        $media = Media::query()->find($photoId);

        // Also check that the photo really belongs to that property: otherwise
        // a crafted path could reach media of another model.
        abort_unless(
            $media !== null
            && $media->model_type === Property::class
            && (int) $media->model_id === $accommodationId,
            404
        );

        return $media;
    }

    /**
     * The contents of the virtual folder, in the same shape as regular files.
     *
     * @return array{dirs: Collection<int, mixed>, files: Collection<int, mixed>}
     */
    private function accommodationList(string $path): array
    {
        [$kind, $accommodationId] = $this->accommodationPath($path);

        if ($kind === 'root') {
            $dirs = Property::query()
                ->withoutGlobalScope('withoutMaster')
                ->orderBy('name')
                ->get()
                ->filter(fn (Property $p) => $p->getMedia('photos')->isNotEmpty())
                ->map(fn (Property $p) => [
                    'name' => $p->name,
                    'path' => self::ACCOMMODATIONS.'/'.$p->id,
                ])->values();

            return ['dirs' => $dirs, 'files' => collect()];
        }

        $property = Property::withoutGlobalScope('withoutMaster')->find($accommodationId);
        abort_unless($property !== null, 404);

        return [
            'dirs' => collect(),
            'files' => $property->getMedia('photos')->map(fn (Media $media) => $this->accommodationFile($media))->values(),
        ];
    }

    /** The virtual path of a property photo, see the explanation at ACCOMMODATIONS. */
    public static function accommodationPhotoPath(Media $media): string
    {
        return self::ACCOMMODATIONS.'/'.$media->model_id.'/'.$media->id;
    }

    /** @return array<string, mixed> */
    private function accommodationFile(Media $media): array
    {
        $ext = strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION));
        $label = (string) $media->getCustomProperty('label');

        return [
            'name' => $media->file_name,
            'path' => self::accommodationPhotoPath($media),
            'url' => $media->getUrl(),
            'size' => $media->size,
            'is_image' => in_array($ext, self::IMAGES),
            'modified' => $media->updated_at?->format('Y-m-d H:i') ?? '',
            // A photo's caption doubles as its alt text; that field already
            // existed on the property and we keep it the same here.
            'alt' => $label !== '' ? $label : Str::headline(pathinfo($media->file_name, PATHINFO_FILENAME)),
            'alt_custom' => $label !== '',
        ];
    }

    /**
     * After replacing or renaming the original, the resized versions are no
     * longer correct. Throw away the old ones and have them recreated.
     */
    private function rebuildFormats(Media $media): void
    {
        $disk = Storage::disk($media->conversions_disk ?: $media->disk);
        $directory = dirname($media->getPathRelativeToRoot()).'/conversions';

        if ($disk->exists($directory)) {
            $disk->deleteDirectory($directory);
        }

        app(FileManipulator::class)->createDerivedFiles($media->fresh());
    }

    /**
     * Rename a property photo: the file moves and the database row follows,
     * so the website keeps pointing to the same photo. The resized versions
     * carry the old name and are recreated.
     */
    private function renameAccommodationPhoto(string $path, string $name)
    {
        $media = $this->accommodationPhoto($path);
        $disk = Storage::disk($media->disk);

        $ext = pathinfo($media->file_name, PATHINFO_EXTENSION);
        $base = Str::slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'photo';
        $newName = $base.($ext ? '.'.$ext : '');

        if ($newName !== $media->file_name) {
            $old = $media->getPathRelativeToRoot();
            $new = dirname($old).'/'.$newName;

            if ($disk->exists($new)) {
                return response()->json([
                    'ok' => false,
                    'message' => __('A file with that name already exists in this folder.'),
                ], 422);
            }

            $disk->move($old, $new);
            $media->file_name = $newName;
            $media->save();

            $this->rebuildFormats($media);
        }

        $file = $this->accommodationFile($media->fresh());

        return response()->json([
            'ok' => true,
            'path' => $file['path'],
            'url' => $file['url'],
            'alt' => $file['alt'],
        ]);
    }

    /**
     * Save the edited image from the photo editor. Overwriting replaces the
     * property's original; otherwise the edit is added as an extra photo,
     * just like regular media where a copy is created.
     */
    private function saveAccommodationPhoto(string $path, Request $request)
    {
        $media = $this->accommodationPhoto($path);
        $ext = strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION));
        abort_unless(in_array($ext, self::IMAGES), 422);

        $property = $media->model;
        $file = $request->file('file');

        if (! $request->boolean('overwrite')) {
            $base = pathinfo($media->file_name, PATHINFO_FILENAME);

            $new = $property
                ->addMediaFromString(file_get_contents($file->getRealPath()))
                ->usingFileName("{$base}-edited.{$ext}")
                ->toMediaCollection('photos');

            return response()->json($this->accommodationFile($new) + ['ok' => true]);
        }

        Storage::disk($media->disk)->put(
            $media->getPathRelativeToRoot(),
            file_get_contents($file->getRealPath()),
        );

        $media->size = $file->getSize();
        $media->save();

        $this->rebuildFormats($media);

        return response()->json($this->accommodationFile($media->fresh()) + ['ok' => true]);
    }

    /** Upload photos into a property's folder. */
    private function uploadAccommodationPhotos(string $path, Request $request)
    {
        [$kind, $accommodationId] = $this->accommodationPath($path);

        if ($kind !== 'accommodation') {
            return response()->json([
                'ok' => false,
                'message' => __('Choose a property first; loose files cannot be placed here.'),
            ], 422);
        }

        $property = Property::withoutGlobalScope('withoutMaster')->find($accommodationId);
        abort_unless($property !== null, 404);

        $uploaded = [];

        foreach ($request->file('files') as $file) {
            $ext = strtolower($file->getClientOriginalExtension());

            // A property only shows images, no documents or video.
            if (! in_array($ext, self::IMAGES)) {
                continue;
            }

            $uploaded[] = $this->accommodationFile($property->addPhoto($file));
        }

        return response()->json(['ok' => true, 'uploaded' => $uploaded]);
    }

    private function assertInRoot(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        abort_if(str_contains($path, '..'), 422);
        abort_unless(str_starts_with($path, self::ROOT.'/') || $path === self::ROOT, 422);
        abort_if($path === self::ROOT, 422); // never delete/rename root itself

        return $path;
    }
}
