<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AppBrand;
use Illuminate\Http\JsonResponse;

class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(AppBrand::webManifest())
            ->header('Cache-Control', 'no-cache, must-revalidate');
    }
}
