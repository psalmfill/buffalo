<?php

namespace App\Support\Reports;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Streaming XLSX or CSV (OpenSpout, MIT) for data exports (Phase 7D).
 * Every cell goes through SafeCell (formula injection). The marking rows
 * come first; the XLSX also carries them in its document properties.
 */
final class SpreadsheetWriter
{
    private WriterInterface $writer;

    private int $rows = 0;

    /**
     * @param  list<string>  $markings  first rows (classification, generated for, reference)
     */
    public function __construct(string $format, string $path, array $markings, string $title)
    {
        if ($format === 'xlsx') {
            $options = new XlsxOptions(properties: new Properties(
                title: $title,
                subject: $markings[0] ?? null,
                application: 'BUFFALO',
                creator: $markings[1] ?? 'BUFFALO',
                lastModifiedBy: 'BUFFALO',
                keywords: $markings[0] ?? null,
                description: implode(' | ', $markings),
                category: $markings[0] ?? null,
            ));
            $this->writer = new XlsxWriter($options);
            $this->writer->openToFile($path);
        } else {
            $this->writer = new CsvWriter;
            $this->writer->openToFile($path);
        }

        foreach ($markings as $marking) {
            $this->writer->addRow(Row::fromValues([SafeCell::value($marking)]));
        }
        $this->writer->addRow(Row::fromValues([]));
    }

    /**
     * @param  list<mixed>  $values
     */
    public function header(array $values): void
    {
        $this->writer->addRow(Row::fromValues(array_map(SafeCell::value(...), $values)));
    }

    /**
     * @param  list<mixed>  $values
     */
    public function row(array $values): void
    {
        $this->writer->addRow(Row::fromValues(array_map(SafeCell::value(...), $values)));
        $this->rows++;
    }

    public function rows(): int
    {
        return $this->rows;
    }

    public function close(): void
    {
        $this->writer->close();
    }
}
