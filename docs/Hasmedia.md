# HasMedia Trait

Complete media handling for Laravel API — upload, compress, WebP conversion, public/private storage, and automatic URL resolution in JSON responses.

---

## How It Works

```
POST /api/media/upload  →  { "id": 1 }
         ↓
Save  logo_id = 1  on your model
         ↓
GET  /api/brand/1  →  { "logo_url": "https://..." }
```

- Upload returns a **media ID**
- You store that ID in any column (`logo_id`, `favicon_id`, `images_id`)
- Trait automatically replaces `*_id` with `*_url` in every JSON response
- Images are compressed and converted to **WebP** on upload
- Public files → permanent URL | Private files → signed URL with expiry

---

## Requirements

```bash
composer require intervention/image
php artisan storage:link
```

---

## Setup

### 1. Add private disk — `config/filesystems.php`

```php
'disks' => [

    // ... existing disks ...

    'private' => [
        'driver'     => 'local',
        'root'       => storage_path('app/private'),
        'visibility' => 'private',
    ],

],
```

---

### 2. Register routes — `routes/api.php`

```php
\App\Traits\HasMedia::routes();
```

This registers two routes automatically:
| Method | Route | Description |
|--------|-------|-------------|
| `POST` | `/api/media/upload` | Upload a file, returns media record |
| `GET` | `/api/media/{uuid}` | Serve a file (public or private) |

---

### 3. Media table migration

```php
Schema::create('media', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->string('disk')->default('private');
    $table->string('collection')->nullable();
    $table->string('path');
    $table->string('mime_type');
    $table->string('original_name');
    $table->string('extension');
    $table->unsignedBigInteger('size');
    $table->unsignedBigInteger('original_size');
    $table->string('savings')->default('0%');
    $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
});
```

---

### 4. Media model — `app/Models/Media.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    protected $fillable = [
        'uuid',
        'disk',
        'collection',
        'path',
        'mime_type',
        'original_name',
        'extension',
        'size',
        'original_size',
        'savings',
        'uploaded_by',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
```

---

### 5. Add media columns to your model's migration

```php
// Single file — stores one media ID
$table->foreignId('logo_id')->nullable()->constrained('media')->nullOnDelete();
$table->foreignId('favicon_id')->nullable()->constrained('media')->nullOnDelete();
$table->foreignId('document_id')->nullable()->constrained('media')->nullOnDelete();

// Multiple files — stores JSON array of IDs
$table->json('images_id')->nullable();
```

---

## Add Trait to Your Model

```php
<?php

namespace App\Models;

use App\Traits\HasMedia;
use Illuminate\Database\Eloquent\Model;

class BrandConfig extends Model
{
    use HasMedia;

    protected $fillable = [
        'name',
        'domain',
        'logo_id',
        'favicon_id',
        'images_id',
    ];

    protected $casts = [
        'images_id' => 'array', // required for every array column
    ];

    // Define which columns hold media IDs
    // 'public'  → permanent URL, no expiry, browser cacheable (1 year)
    // 'private' → signed URL, expires (default: 5 min)
    protected array $mediaColumns = [
        'logo_id'    => 'public',
        'favicon_id' => 'public',
        'images_id'  => 'public',    // works for arrays too
        'document_id'=> 'private',
    ];

    // Optional overrides
    // protected int $mediaQuality = 90; // WebP quality 1-100 (default: 80)
    // protected int $mediaExpiry  = 30; // private URL expiry minutes (default: 5)
}
```

---

## Usage

### Upload a file

```
POST /api/media/upload
Authorization: Bearer <token>
Content-Type: multipart/form-data

Body:
  file  = <file>           (required)
  disk  = public|private   (optional, default: private)
```

**Response:**
```json
{
    "id":      1,
    "uuid":    "abc-123-def-456",
    "url":     "https://yourapp.com/storage/media/abc-123.webp",
    "savings": "42%",
    "size":    12345,
    "name":    "photo.webp"
}
```

> All images (jpg, png, bmp, webp) are automatically converted to **WebP** and compressed.
> Non-images (pdf, doc, etc.) are stored as-is.

---

### Save the ID to your model

```php
// Single file
$brand->update(['logo_id' => 1]);

// Indexed array
$product->update([
    'images_id' => [1, 2, 3, 4],
]);

// Associative array — keys are preserved in response
$product->update([
    'images_id' => [
        'front' => 1,
        'back'  => 2,
        'side'  => 3,
    ],
]);
```

---

### Fetch model — IDs automatically become URLs

```php
return response()->json($brand->fresh());
```

**Single ID:**
```json
{
    "id": 1,
    "name": "Acme Corp",
    "logo_url": "https://yourapp.com/storage/media/uuid.webp",
    "favicon_url": null
}
```

**Indexed array:**
```json
{
    "id": 1,
    "name": "Nike Shoes",
    "images_url": [
        "https://yourapp.com/storage/media/uuid1.webp",
        "https://yourapp.com/storage/media/uuid2.webp",
        "https://yourapp.com/storage/media/uuid3.webp"
    ]
}
```

**Associative array:**
```json
{
    "id": 1,
    "name": "Nike Shoes",
    "images_url": {
        "front": "https://yourapp.com/storage/media/uuid1.webp",
        "back":  "https://yourapp.com/storage/media/uuid2.webp",
        "side":  "https://yourapp.com/storage/media/uuid3.webp"
    }
}
```

> `*_id` columns are **hidden automatically**. Only `*_url` is returned.

---

### Direct property access

```php
$brand->logo_url;     // "https://..." or null
$product->images_url; // array of URLs
```

---

## Public vs Private

| | Public | Private |
|---|---|---|
| **Disk** | `storage/app/public` | `storage/app/private` |
| **URL type** | Permanent direct URL | Temporary signed URL |
| **Expiry** | Never | 5 min (configurable) |
| **Browser cache** | 1 year | No cache |
| **Use for** | Logos, avatars, product images | Invoices, contracts, documents |
| **`storage:link` needed** | ✅ Yes | ❌ No |

---

## File Serving

Handled automatically by `HasMedia::routes()`. No controller needed.

```
GET /api/media/{uuid}
```

- **Public** → streams file, permanent, browser cacheable
- **Private** → validates signed URL, streams file, no cache
  - `403` if URL is tampered or expired
  - `404` if file not found on disk

---

## WebP Compression

All images are converted to WebP on upload. No resizing — quality compression only.

| Quality | Size reduction | Visual difference |
|---------|---------------|------------------|
| 90 | ~20% smaller | Nearly identical |
| **80** | **~40% smaller** | **Default — barely noticeable** |
| 70 | ~55% smaller | Slightly noticeable |
| 60 | ~65% smaller | Noticeable |

Override per model:
```php
protected int $mediaQuality = 90; // higher quality for brand logos
```

---

## Example Upload Route

```php
Route::middleware('auth:sanctum')->group(function () {

    // Upload logo and save to brand
    Route::post('/brand/{brand}/logo', function (Request $request, BrandConfig $brand) {
        $request->validate([
            'logo' => ['required', 'image', 'max:10240'],
        ]);

        // Step 1 — upload file
        // In real usage, frontend uploads separately and sends the ID
        // This is just an example of doing it in one step
        $media = \App\Models\Media::find(
            json_decode(app()->handle(
                \Illuminate\Http\Request::create('/api/media/upload', 'POST', [], [], ['file' => $request->file('logo')])
            )->getContent())->id
        );

        // Step 2 — save ID to model
        $brand->update(['logo_id' => $media->id]);

        return response()->json($brand->fresh());
        // { "logo_url": "https://..." } ✅
    });

});
```

**Recommended approach — two separate calls from frontend:**

```js
// Step 1 — upload file
const { data } = await axios.post('/api/media/upload', formData);
const mediaId = data.id; // e.g. 5

// Step 2 — save ID to model
await axios.patch('/api/brand/1', { logo_id: mediaId });

// Step 3 — fetch model, get URL
const brand = await axios.get('/api/brand/1');
console.log(brand.data.logo_url); // "https://..."
```

---

## File Structure

```
app/
├── Models/
│   └── Media.php          ← media model
├── Traits/
│   └── HasMedia.php        ← the trait (all logic lives here)
```

That's it. No controllers, no services, no extra files.