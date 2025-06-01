<?php

namespace App\Http\Services\ExcelServices;

use Maatwebsite\Excel\Concerns\FromArray;

abstract class TotalExportHandle implements Exportable, FromArray
{
    protected array $data;

    public function __construct(...$args)
    {
        $this->initData(...$args);
    }

    abstract public function initData(...$args);

    /**
     * Get the data.
     */
    public function array(): array
    {
        return $this->data;
    }
}
