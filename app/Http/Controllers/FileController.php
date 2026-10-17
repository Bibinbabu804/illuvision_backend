<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class FileController extends Controller
{
    public function upload(Request $request)
    {
        try {
            $file = $request->file('file');
            $path = $request->path;

            // Compress and convert to WebP if file is an image and target section matches
            $mimeType = $file->getMimeType();
            $isImage = str_starts_with($mimeType, 'image/');
            $isTargetSection = in_array($path, ['products', 'blogs', 'gallery', 'screens']) ||
                              str_contains($path, 'products') ||
                              str_contains($path, 'blogs') ||
                              str_contains($path, 'gallery') ||
                              str_contains($path, 'screens');

            if ($isImage && $isTargetSection) {
                $result = \App\Helpers\MediaHelper::compressUploadedImage($file, $path);
                $file_path = $result['file_path'];
            } else {
                $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file_path = $file->storeAs($path, $fileName, 'public');
            }
            return $this->successResponse(['file_path' => $file_path], 'File uploaded successfully');
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('File upload failed', 400, $e->getMessage());
        }
    }

    public function videoupload(Request $request)
    {
        try {
            $file = $request->file('file');
            $path = $request->path;
            $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file_path = $file->storeAs($path, $fileName, 'public');
            return $this->successResponse(['file_path' => $file_path], 'Video uploaded successfully');
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Video upload failed', 400, $e->getMessage());
        }
    }
}
