<?php

namespace App\Http\Controllers\Api\Commerce\V1;

use App\Http\Controllers\Controller;
use App\Models\Commerce\CommerceSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var CommerceSite $site */
        $site = $request->attributes->get('commerce_site');

        return response()->json([
            'site_id' => $site->site_id,
            'api_version' => '1',
            'display_name' => $site->display_name,
        ]);
    }
}
