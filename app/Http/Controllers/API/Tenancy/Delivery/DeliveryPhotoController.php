<?php

namespace App\Http\Controllers\API\Tenancy\Delivery;

use App\Http\Controllers\API\BaseController;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves a delivery proof photo behind a time-limited signed URL — the signature is the
 * authorization, which keeps proof photos from being enumerated across organizations.
 */
class DeliveryPhotoController extends BaseController
{
    public function show(string $name): BinaryFileResponse|Response
    {
        // Guard against traversal: only a bare filename is ever valid.
        if ($name !== basename($name)) {
            abort(404);
        }

        $path = 'delivery-photos/'.$name;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path));
    }
}
