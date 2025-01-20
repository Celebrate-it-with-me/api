<?php

namespace App\Http\Services\ExcelServices;

use Maatwebsite\Excel\Concerns\FromArray;

abstract class TotalExportHandle implements FromArray, Exportable
{
    protected array $data;

    public function __construct(...$args)
    {
        $this->initData(...$args);
    }

    abstract public function initData(...$args);

    /**
     * Get the data.
     *
     * @return array
     */
    public function array(): array
    {
        return $this->data;
    }
}
