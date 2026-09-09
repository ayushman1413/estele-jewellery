<?php

namespace App\Http\Controllers;

use App\Models\OldJewelleryVendorInvitation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VendorMediaController extends Controller
{
    public function video(Request $request, OldJewelleryVendorInvitation $invitation): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $media = $invitation->request->getFirstMedia('video');
        abort_unless($media, 404);

        return response()->streamDownload(function () use ($media) {
            echo $media->stream();
        }, $media->file_name, ['Content-Type' => $media->mime_type]);
    }
}
