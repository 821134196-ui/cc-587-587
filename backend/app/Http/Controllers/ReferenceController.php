<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\Process;
use App\Models\Station;
use Illuminate\Http\Request;

class ReferenceController extends Controller
{
    public function stations()
    {
        return Station::with('equipment:id,code,name,station_id,status')
            ->where('is_active', true)->orderBy('id')->get();
    }

    public function processes(Request $request)
    {
        $product = $request->query('product_code');
        $q = Process::with(['station:id,code,name', 'templates',
            'versions' => fn ($q) => $q->with('items.template')->orderByDesc('id')])
            ->where('is_active', true);
        if ($product) {
            $q->where('product_code', $product);
        }
        return $q->orderBy('product_code')->orderBy('sequence')->get();
    }

    public function stationEquipment(int $stationId)
    {
        return Equipment::where('station_id', $stationId)
            ->where('status', '!=', 'maintenance')->get();
    }
}
