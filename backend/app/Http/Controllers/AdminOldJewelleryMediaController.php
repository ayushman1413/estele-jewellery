<?php

namespace App\Http\Controllers;

use App\Filament\Resources\OldJewelleryRequests\OldJewelleryRequestResource;
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

        // The permission above only proves the user can see *some* request —
        // a vendor contact's role holds it too, scoped elsewhere to the
        // requests it was invited to. Enforce that same scoping here, or a
        // vendor can stream any customer's photo/video by request number.
        $vendorId = OldJewelleryRequestResource::currentVendorId();
        abort_unless(
            $vendorId === null || $oldJewelleryRequest->invitations()->where('vendor_id', $vendorId)->exists(),
            403,
        );

        $media = $oldJewelleryRequest->getFirstMedia($collection);
        abort_unless($media, 404);

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response()->stream(function () use ($media) {
            // Media::stream() returns a PHP stream resource, not bytes —
            // echoing it directly prints the literal string "Resource id #N"
            // while Content-Length still advertises the real file size,
            // producing a body/length mismatch the browser cannot play or
            // render. fpassthru() reads the resource and writes its bytes.
            fpassthru($media->stream());
        }, 200, [
            'Content-Type' => $media->mime_type,
            'Content-Length' => $media->size,
            'Content-Disposition' => $disposition.'; filename="'.$media->file_name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
