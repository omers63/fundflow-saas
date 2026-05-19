<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\DirectMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DirectMessageAttachmentController extends Controller
{
    public function __invoke(Request $request, DirectMessage $message, int $index): StreamedResponse
    {
        $user = $request->user('tenant');

        if ($user === null) {
            abort(403);
        }

        $userId = (int) $user->id;

        if ((int) $message->from_user_id !== $userId && (int) $message->to_user_id !== $userId) {
            abort(403);
        }

        $attachments = $message->attachments;

        if (! is_array($attachments) || ! array_key_exists($index, $attachments)) {
            abort(404);
        }

        $path = $attachments[$index];

        if (! is_string($path) || $path === '' || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        $filename = basename($path);

        return Storage::disk('public')->response(
            $path,
            $filename,
            [
                'Content-Disposition' => 'inline; filename="'.$filename.'"',
            ],
        );
    }
}
