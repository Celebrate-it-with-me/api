<?php

namespace App\Exports\Catering;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * XLSX of the catering list from a CateringReport: dish summary, by table (dish counts), list by table and people
 * without a table. Without the Menu module there are no dish columns; without Tables there is only the summary.
 */
class CateringExport implements Export, WithMultipleSheets
{
    use Exportable;

    /** @param  array<string, mixed>  $report */
    public function __construct(private readonly array $report) {}

    /** @return list<CateringSheet> */
    public function sheets(): array
    {
        $r = $this->report;
        $sheets = [new CateringSheet($this->t('sheet_summary'), [$this->t('type'), $this->t('dish'), $this->t('diet'), $this->t('count')], $this->summaryRows())];

        if ($r['seating']) {
            $sheets[] = new CateringSheet($this->t('sheet_tables'), $this->tableHeadings(), $this->tableRows());
            $sheets[] = new CateringSheet($this->t('sheet_list'), $this->listHeadings(true), $this->listRows(true));
            $sheets[] = new CateringSheet($this->t('sheet_no_table'), $this->listHeadings(false), $this->listRows(false));
        }

        return $sheets;
    }

    private function t(string $key): string
    {
        return (string) __("exports.catering.$key", [], $this->report['locale']);
    }

    private function type(string $type): string
    {
        return (string) __("exports.catering.type_$type", [], $this->report['locale']);
    }

    /** @return list<list<string|int|null>> */
    private function summaryRows(): array
    {
        $r = $this->report;
        $rows = [];
        foreach ($r['summary'] as $type => $block) {
            foreach ($block['items'] as $item) {
                $rows[] = [$this->type($type), $item['name'], $item['diet'], $item['count']];
            }
            $rows[] = [$this->type($type), $this->t('unchosen'), '', $block['unchosen']];
        }

        $rows[] = [];
        $rows[] = [$this->t('total'), '', '', $r['total_people']];
        $rows[] = [$r['include_pending'] ? $this->t('includes_pending') : $this->t('only_confirmed')];
        if (! $r['menus']) {
            $rows[] = [$this->t('note_menus_off')];
        }
        if (! $r['seating']) {
            $rows[] = [$this->t('note_seating_off')];
        }

        return $rows;
    }

    /** @return list<string> */
    private function tableHeadings(): array
    {
        return $this->report['menus']
            ? [$this->t('table'), $this->t('people'), $this->t('capacity'), $this->t('type'), $this->t('dish'), $this->t('diet'), $this->t('count')]
            : [$this->t('table'), $this->t('people'), $this->t('capacity')];
    }

    /** @return list<list<string|int|null>> */
    private function tableRows(): array
    {
        $rows = [];
        foreach ($this->report['tables'] as $table) {
            if (! $this->report['menus']) {
                $rows[] = [$table['name'], $table['people'], $table['capacity']];

                continue;
            }
            foreach ($table['dishes'] as $type => $block) {
                foreach ($block['items'] as $item) {
                    $rows[] = [$table['name'], $table['people'], $table['capacity'], $this->type($type), $item['name'], $item['diet'], $item['count']];
                }
                $rows[] = [$table['name'], $table['people'], $table['capacity'], $this->type($type), $this->t('unchosen'), '', $block['unchosen']];
            }
        }

        return $rows;
    }

    /** @return list<string> */
    private function listHeadings(bool $withTable): array
    {
        $head = $withTable ? [$this->t('table'), $this->t('seat')] : [];
        array_push($head, $this->t('guest'), $this->t('companion_of'));
        if ($this->report['include_pending']) {
            $head[] = $this->t('status');
        }
        foreach ($this->report['types'] as $type) {
            $head[] = $this->type($type);
        }

        return [...$head, $this->t('restrictions')];
    }

    /** @return list<list<string|int|null>> */
    private function listRows(bool $withTable): array
    {
        $people = $withTable
            ? collect($this->report['tables'])->flatMap(fn ($table) => $table['guests'])->all()
            : $this->report['no_table'];

        return array_map(function (array $p) use ($withTable) {
            $row = $withTable ? [$p['table'], $p['seat']] : [];
            array_push($row, $p['name'], $p['companion_of']);
            if ($this->report['include_pending']) {
                $row[] = $this->t('status_' . $p['status']);
            }
            foreach ($this->report['types'] as $type) {
                $row[] = $p['dishes'][$type];
            }

            return [...$row, $p['diets']];
        }, $people);
    }
}
