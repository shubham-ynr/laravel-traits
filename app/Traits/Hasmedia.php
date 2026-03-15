<?php

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║                        HasMedia Trait                           ║
 * ║                  Complete Media Handling System                  ║
 * ╚══════════════════════════════════════════════════════════════════╝
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ INSTALL                                                          │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   composer require intervention/image
 *   php artisan storage:link
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ SETUP — 5 steps, do once                                         │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *  1) config/filesystems.php — add inside 'disks' array:
 *
 *     'private' => [
 *         'driver'     => 'local',
 *         'root'       => storage_path('app/private'),
 *         'visibility' => 'private',
 *     ],
 *
 *  2) routes/api.php — register upload + serve routes:
 *
 *     \App\Traits\HasMedia::routes();
 *
 *  3) Create media table migration:
 *
 *     Schema::create('media', function (Blueprint $table) {
 *         $table->id();
 *         $table->uuid('uuid')->unique();
 *         $table->string('disk')->default('private');
 *         $table->string('collection')->nullable();
 *         $table->string('path');
 *         $table->string('mime_type');
 *         $table->string('original_name');
 *         $table->string('extension');
 *         $table->unsignedBigInteger('size');
 *         $table->unsignedBigInteger('original_size');
 *         $table->string('savings')->default('0%');
 *         $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
 *         $table->timestamps();
 *     });
 *
 *  4) Create app/Models/Media.php:
 *
 *     class Media extends Model {
 *         protected $fillable = [
 *             'uuid', 'disk', 'collection', 'path', 'mime_type',
 *             'original_name', 'extension', 'size', 'original_size',
 *             'savings', 'uploaded_by',
 *         ];
 *     }
 *
 *  5) Add FK columns to your model's table:
 *
 *     // Single file
 *     $table->foreignId('logo_id')->nullable()->constrained('media')->nullOnDelete();
 *
 *     // Multiple files (JSON array of IDs)
 *     $table->json('images_id')->nullable();
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ ADD TO YOUR MODEL                                                │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   class BrandConfig extends Model {
 *       use HasMedia;
 *
 *       protected $fillable = [
 *           'name',
 *           'logo_id',    // single file
 *           'favicon_id', // single file
 *           'images_id',  // multiple files (JSON array)
 *       ];
 *
 *       protected $casts = [
 *           'images_id' => 'array', // required for array columns
 *       ];
 *
 *       // Define which columns hold media IDs and their visibility:
 *       // 'public'  → permanent URL, no expiry, browser cacheable
 *       // 'private' → signed URL, expires (default: 5 min)
 *       protected array $mediaColumns = [
 *           'logo_id'    => 'public',
 *           'favicon_id' => 'public',
 *           'images_id'  => 'public',   // works for arrays too
 *           'document_id'=> 'private',
 *       ];
 *
 *       // Optional overrides:
 *       // protected int $mediaQuality = 90;  // WebP quality (default: 80)
 *       // protected int $mediaExpiry  = 30;  // private URL expiry in minutes (default: 5)
 *   }
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ UPLOAD — one route handles everything                            │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   POST /api/media/upload
 *   Headers: Authorization: Bearer <token>
 *   Body:    file=<file>          (required)
 *            disk=public|private  (optional, default: private)
 *
 *   Response:
 *   {
 *     "id":      1,
 *     "uuid":    "abc-123",
 *     "url":     "https://...",
 *     "savings": "42%",
 *     "size":    12345,
 *     "name":    "photo.webp"
 *   }
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ SAVE IDs TO YOUR MODEL                                           │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   // Single file
 *   $brand->update(['logo_id' => 1]);
 *
 *   // Indexed array
 *   $product->update(['images_id' => [1, 2, 3]]);
 *
 *   // Associative array (preserves keys)
 *   $product->update([
 *       'images_id' => [
 *           'front' => 1,
 *           'back'  => 2,
 *           'side'  => 3,
 *       ]
 *   ]);
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ RESPONSE — IDs auto become URLs, nothing extra needed            │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   return response()->json($brand->fresh());
 *
 *   // Single ID:
 *   { "logo_url": "https://yourapp.com/storage/media/uuid.webp" }
 *
 *   // Indexed array:
 *   { "images_url": ["https://.../1.webp", "https://.../2.webp"] }
 *
 *   // Associative array:
 *   { "images_url": { "front": "https://...", "back": "https://..." } }
 *
 *   // Not set:
 *   { "logo_url": null }
 *
 *   NOTE: *_id columns are hidden automatically. Only *_url is returned.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ FILE SERVING — handled automatically by HasMedia::routes()       │
 * └──────────────────────────────────────────────────────────────────┘
 *
 *   GET /api/media/{uuid}
 *
 *   public  → streams directly, permanent, browser cacheable (1 year)
 *   private → validates signed URL, streams, no cache
 *             returns 403 if URL tampered or expired
 *             returns 404 if file missing
 */

namespace App\Traits;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

trait HasMedia
{
    /**
     * WebP quality — override per model: protected int $mediaQuality = 90;
     */
    protected int $mediaQuality = 80;

    /**
     * Private URL expiry in minutes — override: protected int $mediaExpiry = 30;
     */
    protected int $mediaExpiry = 5;

    // ──────────────────────────────────────────────────
    // Boot — hide *_id columns, show *_url instead
    // ──────────────────────────────────────────────────

    public function initializeHasMedia(): void
    {
        // Hide all *_id media columns from JSON output
        foreach (array_keys($this->mediaColumns ?? []) as $column) {
            $this->makeHidden($column);
        }
    }

    /**
     * Override toArray — injects *_url keys into output automatically.
     * This is how logo_id becomes logo_url in every JSON response.
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        foreach ($this->mediaColumns ?? [] as $column => $visibility) {
            $urlKey  = str_replace('_id', '_url', $column);
            $value   = $this->getAttribute($column);

            if (! $value) {
                $array[$urlKey] = null;
                continue;
            }

            // Array of IDs (indexed or associative)
            if (is_array($value)) {
                $isAssoc = array_keys($value) !== range(0, count($value) - 1);

                if ($isAssoc) {
                    $result = [];
                    foreach ($value as $key => $id) {
                        $media        = Media::find($id);
                        $result[$key] = $media ? $this->resolveMediaUrl($media) : null;
                    }
                    $array[$urlKey] = $result;
                } else {
                    $array[$urlKey] = Media::findMany($value)
                        ->map(fn(Media $media) => $this->resolveMediaUrl($media))
                        ->filter()
                        ->values()
                        ->toArray();
                }

                continue;
            }

            // Single ID
            $media          = Media::find($value);
            $array[$urlKey] = $media ? $this->resolveMediaUrl($media) : null;
        }

        return $array;
    }

    // ──────────────────────────────────────────────────
    // Dynamic Accessor — logo_url, favicon_url etc.
    // Called automatically when model is serialized
    // ──────────────────────────────────────────────────

    /**
     * Allows direct property access: $brand->logo_url
     */
    public function getAttribute($key)
    {
        if (str_ends_with($key, '_url')) {
            $column = str_replace('_url', '_id', $key);

            if (isset(($this->mediaColumns ?? [])[$column])) {
                // Delegate to toArray logic by resolving on the fly
                $value = parent::getAttribute($column);

                if (! $value) return null;

                if (is_array($value)) {
                    $isAssoc = array_keys($value) !== range(0, count($value) - 1);
                    if ($isAssoc) {
                        $result = [];
                        foreach ($value as $k => $id) {
                            $media      = Media::find($id);
                            $result[$k] = $media ? $this->resolveMediaUrl($media) : null;
                        }
                        return $result;
                    }
                    return Media::findMany($value)
                        ->map(fn(Media $m) => $this->resolveMediaUrl($m))
                        ->filter()->values()->toArray();
                }

                $media = Media::find($value);
                return $media ? $this->resolveMediaUrl($media) : null;
            }
        }

        return parent::getAttribute($key);
    }

    // ──────────────────────────────────────────────────
    // Routes — registers both upload + serve
    // ──────────────────────────────────────────────────

    /**
     * Registers:
     *   POST /media/upload  → upload file, returns media record
     *   GET  /media/{uuid}  → serve file (public or private)
     *
     * Call once in routes/api.php:
     *   \App\Traits\HasMedia::routes();
     */
    public static function routes(): void
    {
        // ── Upload ──────────────────────────────────────
        Route::post('/media/upload', function (Request $request) {
            $request->validate([
                'file' => ['required', 'file', 'max:20480'],
                'disk' => ['sometimes', 'in:public,private'],
            ]);

            $disk = $request->input('disk', 'private');

            $media = self::storeFile($request->file('file'), $disk);

            return response()->json([
                'id'      => $media->id,
                'uuid'    => $media->uuid,
                'url'     => $disk === 'public'
                                ? asset('storage/' . $media->path)
                                : URL::temporarySignedRoute('media.serve', now()->addMinutes(5), ['uuid' => $media->uuid]),
                'savings' => $media->savings,
                'size'    => $media->size,
                'name'    => $media->original_name,
            ]);

        })->middleware(['auth:sanctum', 'throttle:30,1'])->name('media.upload');

        // ── Serve ───────────────────────────────────────
        Route::get('/media/{uuid}', function (Request $request, string $uuid) {
            $media = Media::where('uuid', $uuid)->firstOrFail();

            if ($media->disk === 'private') {
                abort_unless($request->hasValidSignature(), 403, 'Invalid or expired link.');
                abort_unless(Storage::disk('private')->exists($media->path), 404);
                $path   = Storage::disk('private')->path($media->path);
                $cache  = 'no-store, private';
            } else {
                abort_unless(Storage::disk('public')->exists($media->path), 404);
                $path   = Storage::disk('public')->path($media->path);
                $cache  = 'public, max-age=31536000';
            }

            return response()->file($path, [
                'Content-Type'        => $media->mime_type,
                'Content-Disposition' => 'inline; filename="' . $media->original_name . '"',
                'Cache-Control'       => $cache,
            ]);

        })->name('media.serve')->middleware('throttle:120,1');
    }

    // ──────────────────────────────────────────────────
    // Private Helpers
    // ──────────────────────────────────────────────────

    public static function storeFile(UploadedFile $file, string $disk = 'private'): Media
    {
        $uuid         = Str::uuid()->toString();
        $originalSize = $file->getSize();

        if (self::mediaShouldConvert($file)) {
            $ext            = 'webp';
            $mime           = 'image/webp';
            $path           = "media/{$uuid}.webp";
            $binary         = self::mediaToWebp($file);
            $compressedSize = strlen($binary);
            $savings        = round((1 - $compressedSize / $originalSize) * 100, 1) . '%';

            Storage::disk($disk)->put($path, $binary);
        } else {
            $ext            = strtolower($file->getClientOriginalExtension());
            $mime           = $file->getMimeType();
            $path           = "media/{$uuid}.{$ext}";
            $compressedSize = $originalSize;
            $savings        = '0%';

            Storage::disk($disk)->putFileAs('media', $file, "{$uuid}.{$ext}");
        }

        return Media::create([
            'uuid'          => $uuid,
            'disk'          => $disk,
            'path'          => $path,
            'mime_type'     => $mime,
            'original_name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) . '.' . $ext,
            'extension'     => $ext,
            'size'          => $compressedSize,
            'original_size' => $originalSize,
            'savings'       => $savings,
            'uploaded_by'   => Auth::id(),
        ]);
    }

    private function resolveMediaUrl(Media $media): string
    {
        $column   = array_search(
            $media->disk,
            array_map(fn($v) => $v, $this->mediaColumns ?? [])
        );

        if ($media->disk === 'public') {
            return asset('storage/' . $media->path);
        }

        return URL::temporarySignedRoute(
            'media.serve',
            now()->addMinutes($this->mediaExpiry),
            ['uuid' => $media->uuid]
        );
    }

    private static function mediaShouldConvert(UploadedFile $file): bool
    {
        return in_array($file->getMimeType(), [
            'image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/bmp',
        ]);
    }

    private static function mediaToWebp(UploadedFile $file): string
    {
        return (string) (new ImageManager(new Driver()))
            ->read($file->getPathname())
            ->toWebp(80);
    }
}