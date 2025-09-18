<?php

namespace PhpOffice\PhpSpreadsheet\Worksheet;

class Worksheet
{
    /** @var array<int, array<int, mixed>> */
    private $cells = [];

    /** @var string */
    private $title = 'Worksheet';

    public function __construct($spreadsheet = null)
    {
        // No-op: kept for compatibility with PhpSpreadsheet signature.
    }

    public function setTitle($title)
    {
        $this->title = (string) $title;

        return $this;
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function setCellValueByColumnAndRow($column, $row, $value)
    {
        $column = (int) $column;
        $row = (int) $row;
        if ($column < 1 || $row < 1) {
            throw new \InvalidArgumentException('Row and column numbers must be positive integers.');
        }

        if (!isset($this->cells[$row])) {
            $this->cells[$row] = [];
        }

        $this->cells[$row][$column] = $value;

        return $this;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function getCellCollection()
    {
        ksort($this->cells);
        foreach ($this->cells as &$row) {
            ksort($row);
        }

        return $this->cells;
    }
}
