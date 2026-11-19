<?php

namespace App\Http\Controllers;

use App\Models\MaterialLot;
use App\Support\Fmt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /lots/lookup?uid=... : the scan control (public/js/scan.js) asks which lot a scanned Metrc package UID belongs to.
 * ok=false with a plain message when it is not found, not released or expired. Read-only.
 */
class LotLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $uid = strtoupper(preg_replace('/\s+/', '', (string) $request->query('uid', '')));
        if ($uid === '' || strlen($uid) > 100) {
            return response()->json(['ok' => false, 'message' => 'Scan or type a Metrc package UID.'], 422);
        }
        $lots = MaterialLot::with('ingredient')->where('metrc_package_uid', $uid)->orderByDesc('id')->get();
        if ($lots->isEmpty()) {
            return response()->json(['ok' => false, 'message' => "No received lot has Metrc package UID $uid."], 404);
        }
        // Several lots can share a UID (e.g. a re-receipt); prefer a usable one.
        $lot = $lots->first(fn ($l) => $l->status === 'released' && ! $l->isExpired()) ?? $lots->first();
        $info = ['id' => $lot->id, 'public_id' => $lot->public_id, 'ingredient' => $lot->ingredient?->name ?? '',
            'status' => $lot->status, 'expiration_date' => $lot->expiration_date?->toDateString()];
        if ($lot->status !== 'released') {
            $st = MaterialLot::STATUSES[$lot->status] ?? $lot->status;
            return response()->json(['ok' => false, 'lot' => $info, 'message' => "Lot {$lot->public_id} ($uid) is not released (status: $st). It can't be used."], 409);
        }
        if ($lot->isExpired()) {
            return response()->json(['ok' => false, 'lot' => $info, 'message' => "Lot {$lot->public_id} ($uid) expired on ".Fmt::date($lot->expiration_date).'. It can\'t be used.'], 409);
        }

        return response()->json(['ok' => true, 'lot' => $info]);
    }
}
