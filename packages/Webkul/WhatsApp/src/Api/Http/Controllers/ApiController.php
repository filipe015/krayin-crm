<?php

namespace Webkul\WhatsApp\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

abstract class ApiController extends Controller
{
    protected function ok(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data] + ($meta ? ['meta' => $meta] : []), $status);
    }

    protected function fail(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message] + $extra, $status);
    }
}
