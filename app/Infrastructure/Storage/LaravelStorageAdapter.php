<?php

namespace App\Infrastructure\Storage;
use App\Infrastructure\Storage\Contracts\StorageContractInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Laravel filesystem adapter for the public disk.
 *
 * The disk name is fixed here rather than being passed in by callers, which
 * preserves the previous behaviour where every media call site hard-coded
 * `Storage::disk('public')`.
 */
class LaravelStorageAdapter implements StorageContractInterface
{
    protected const DISK = 'public';

    /**
     * {@inheritdoc}
     */
    public function storeAs(UploadedFile $file, string $directory, string $name): string
    {
        return $file->storeAs($directory, $name, self::DISK);
    }

    /**
     * {@inheritdoc}
     */
    public function store(UploadedFile $file, string $directory): string
    {
        return $file->store($directory, self::DISK);
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $path, string $contents): string
    {
        Storage::disk(self::DISK)->put($path, $contents);

        return $path;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $path): bool
    {
        return Storage::disk(self::DISK)->delete($path);
    }

    /**
     * {@inheritdoc}
     */
    public function url(string $path): string
    {
        return Storage::url($path);
    }
}
