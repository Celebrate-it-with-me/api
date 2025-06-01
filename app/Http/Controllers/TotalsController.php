<?php

namespace App\Http\Controllers;

use App\Http\Services\ExcelServices\ExportFactory;
use App\Http\Services\TotalsServices;
use Exception;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TotalsController extends Controller
{
    private Excel $excel;

    public function __construct(Excel $excel)
    {
        $this->excel = $excel;
    }

    /**
     * Get the totals.
     */
    public function getTotals(): JsonResponse
    {
        try {
            return response()->json(['totals' => (new TotalsServices)->totals()]);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get the total details.
     */
    public function totalDetails(string $type): JsonResponse
    {
        try {
            return response()->json(['totals' => (new TotalsServices)->details($type)]);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Export excel file based on the given type
     *
     * @param  string  $type  The type of export (e.g. 'sales', 'orders')
     * @return \Illuminate\Http\BinaryFileResponse|\Illuminate\Http\JsonResponse
     */
    public function exportExcel(string $type): BinaryFileResponse|JsonResponse
    {
        try {
            return $this->excel->download(
                ExportFactory::make($type),
                "totals_$type.xlsx"
            );
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
