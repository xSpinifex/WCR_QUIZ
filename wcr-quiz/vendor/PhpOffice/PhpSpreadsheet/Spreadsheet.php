<?php

namespace PhpOffice\PhpSpreadsheet;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class Spreadsheet
{
    /** @var Worksheet */
    private $activeSheet;

    public function __construct()
    {
        $this->activeSheet = new Worksheet($this);
    }

    public function getActiveSheet()
    {
        return $this->activeSheet;
    }

    public function setActiveSheetIndex($index)
    {
        if ((int) $index !== 0) {
            throw new \InvalidArgumentException('Only a single worksheet is supported in this lightweight implementation.');
        }

        return $this;
    }
}
