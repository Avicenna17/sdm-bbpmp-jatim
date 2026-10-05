<?php

namespace App\Domain\Templates;

use App\Domain\Import\WorkbookParser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class TemplateSampleLoader
{
    public function load(string $path, string $source, int $fallbackVersion): array
    {
        // Livewire upload paths can exceed ZIP reader limits on Windows.
        $temporaryPath = tempnam(sys_get_temp_dir(), 'sdm');
        $book = null;
        try {
            if (! $temporaryPath || ! copy($path, $temporaryPath)) {
                throw new RuntimeException('File sementara tidak dapat dibaca. Silakan unggah ulang.');
            }
            $path = $temporaryPath;
            $book = IOFactory::load($path);
            $reader = new TemplateReader;
            $marker = $book->getProperties()->getCustomPropertyValue('sdm_template_version');
            $reader->resolve($book, $source, $marker ? null : $fallbackVersion, [], [], true);
            $candidates = [];
            $messages = [];
            $sheets = $source === WorkbookParser::PERSONNEL ? array_filter([$book->getSheetByName('DUK PEGAWAI')]) : $book->getAllSheets();
            foreach ($sheets as $candidate) {
                if ($candidate->getHighestDataRow() > 15000 || \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($candidate->getHighestDataColumn()) > 200) {
                    $messages[] = 'Lembar '.$candidate->getTitle().' melebihi batas 15.000 baris / 200 kolom.';
                    continue;
                }
                $candidateReader = clone $reader;
                try {
                    $table = $candidateReader->table($candidate, true);
                    $candidates[] = [$candidate, $candidateReader, $table];
                } catch (RuntimeException $e) {
                    $messages[] = $e->getMessage();
                }
            }
            if (count($candidates) !== 1) {
                throw new RuntimeException(count($candidates) > 1 ? 'Lebih dari satu lembar memiliki kolom wajib yang sesuai. Sisakan satu lembar data utama untuk diuji.' : ($messages[0] ?? 'Lembar DUK PEGAWAI tidak ditemukan. Gunakan nama lembar tersebut untuk data pegawai.'));
            }
            [$sheet, $reader, [$data, $map, $header]] = $candidates[0];
            $definition = $reader->version->definition;
            $definition['columns'] = $reader->columns;
            unset($definition['initial_template']);
            if ($header >= 2) {
                $definition['title'] = trim((string) ($data[0][0] ?? '')) ?: $definition['title'];
                $subtitle = '';
                foreach ($sheet->getRowIterator(2, 2) as $row) {
                    foreach ($row->getCellIterator() as $cell) {
                        if ($cell->getValue() !== null && $cell->getValue() !== '') {
                            $subtitle = \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell) && is_numeric($cell->getValue())
                                ? \Carbon\Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($cell->getValue()))->locale('id')->translatedFormat('F Y')
                                : (string) $cell->getValue();
                            break;
                        }
                    }
                }
                $definition['subtitle'] = $subtitle;
            }
            $columns = [];
            foreach ($definition['columns'] as $column) {
                $index = $map[$column['key']];
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                $style = $sheet->getStyle($letter.$reader->headerRow);
                $column['label'] = trim((string) $data[$header][$index]);
                $column['color'] = $style->getFill()->getFillType() === 'solid' ? '#'.$style->getFill()->getStartColor()->getRGB() : '#FFFFFF';
                $column['bold'] = (bool) $style->getFont()->getBold();
                $align = $style->getAlignment()->getHorizontal();
                $column['align'] = in_array($align, ['left', 'center', 'right']) ? $align : 'left';
                $width = $sheet->getColumnDimension($letter)->getWidth();
                $column['width'] = $width > 0 ? max(8, min(80, $width)) : 24;
                $columns[$index] = $column;
            }
            ksort($columns);
            $definition['columns'] = array_values($columns);
            app(TemplateService::class)->validate($source, $definition);
            $samples = [];
            foreach (array_slice($data, $header + 1) as $line) {
                if (! array_filter($line, fn ($value) => $value !== null && $value !== '')) {
                    continue;
                }
                $sample = [];
                foreach ($map as $key => $index) {
                    $sample[$key] = mb_substr((string) ($line[$index] ?? ''), 0, 2000);
                }
                $samples[] = $sample;
                if (count($samples) === 5) {
                    break;
                }
            }
            return ['definition' => $definition, 'samples' => $samples, 'rows' => count(array_filter(array_slice($data, $header + 1), fn ($line) => count(array_filter($line, fn ($value) => $value !== null && $value !== '')) > 0))];
        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            throw new RuntimeException('File Excel tidak dapat dibaca. Pastikan file tidak rusak atau dilindungi sandi, lalu unggah ulang.');
        } finally {
            $book?->disconnectWorksheets();
            if ($temporaryPath && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
