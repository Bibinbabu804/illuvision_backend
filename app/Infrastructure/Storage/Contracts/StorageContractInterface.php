<?php

namespace App\Infrastructure\Storage\Contracts;

use Illuminate\Http\UploadedFile;

/**
 * File storage port for the media layer.
 *
 * Extracted so that services depend on this contract rather than the Storage
 * facade. Note this is intentionally narrower than Laravel's Storage facade: the
 * media layer only ever needs to store, probe, delete and resolve URLs against
 * the `public` disk, and pinning the disk here stops a caller from silently
 * addressing a different one.
 */
interface StorageContractInterface
{
    /**
     * Store an uploaded file under the given directory and filename.
     *
     * Takes the UploadedFile rather than raw bytes so the implementation can
     * stream the upload, which is what UploadedFile::storeAs() did before this
     * port existed. Buffering large media in memory would be a behaviour change.
     *
     * @return string the stored path
     */
    public function storeAs(UploadedFile $file, string $directory, string $name): string;

    /**
     * Store an uploaded file under the given directory using a generated name.
     *
     * @return string the stored path
     */
    public function store(UploadedFile $file, string $directory): string;

    /**
     * Store raw contents at the given path and return the stored path.
     */
    public function put(string $path, string $contents): string;

    /**
     * Whether an object exists at the given path.
     */
    public function exists(string $path): bool;

    /**
     * Delete an object. Returns true when something was removed.
     */
    public function delete(string $path): bool;

    /**
     * Public URL for an object.
     */
    public function url(string $path): string;
}
