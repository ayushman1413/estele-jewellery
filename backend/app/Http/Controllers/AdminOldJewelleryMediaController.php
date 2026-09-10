<?php

namespace App\Http\Controllers;

use App\Models\OldJewelleryRequest;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminOldJewelleryMediaController extends Controller
{
    public function video(Request $request, OldJewelleryRequest $oldJewelleryRequest): StreamedResponse
    {
        abort_unless($request->user()?->can('View:OldJewelleryRequest'), 403);

        $media = $oldJewelleryRequest->getFirstMedia('video');
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
