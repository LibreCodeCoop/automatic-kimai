<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\AutomaticKimai\OfficialRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Throwable;

final class OfficialReleaseController extends Controller
{
    public function latest(OfficialRelease $releases): JsonResponse
    {
        try {
            $response=$releases->latest();
            return response()->json($response)->header('Cache-Control','no-store');
        } catch (Throwable) {
            return response()->json([
                'error'=>'release_unavailable',
            ],503)->header('Cache-Control','no-store');
        }
    }
}
