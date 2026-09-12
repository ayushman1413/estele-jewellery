<?php

namespace App\Http\Controllers;

use App\Models\OldJewelleryRequest;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminOldJewelleryMediaController extends Controller
{
    public function video(Request $request, OldJewelleryRequest $oldJewelleryRequest): StreamedResponse
    {
        return $this->stream($request, $oldJewelleryRequest, 'video');
    }

    /**
     * The image original lives on the private 'original_images' disk, which
     * has no public URL — only the small 'thumb' conversion is web-reachable.
     * Serving it here lets the lightbox open the full-size photo without
     * making the originals publicly listable.
     */
    public function image(Request $request, OldJewelleryRequest $oldJewelleryRequest): StreamedResponse
    {
        return $this->stream($request, $oldJewelleryRequest, 'image');
    }

    private function stream(Request $request, OldJewelleryRequest $oldJewelleryRequest, string $collection): StreamedResponse
    {
        abort_unless($request->user()?->can('View:OldJewelleryRequest'), 403);

        $media = $oldJewelleryRequest->getFirstMedia($collection);
        abort_unless($media, 404);

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response()->stream(function () use ($media) {
            echo $media->stream();
        }, 200, [
            'Content-Type' => $media->mime_type,
            'Content-Length' => $media->size,
            'Content-Disposition' => $disposition.'; filename="'.$media->file_name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
