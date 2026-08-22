<?php

declare(strict_types=1);

namespace App\Running\Application;

use App\Running\Domain\RunStatus;
use App\Running\Domain\TimePrecision;
use App\Running\Domain\TimeValue;

final readonly class RunningPdfService
{
    /** @param array<string, mixed> $run */
    public function create(array $run, string $document): string
    {
        if (($run['event']['status'] ?? null) === 'cancelled') { throw new \DomainException('Für abgebrochene Veranstaltungen dürfen keine offiziellen Laufdokumente erzeugt werden.'); }
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8'); $pdf->SetCreator('Swiss Club SaaS'); $pdf->SetAuthor((string) ($run['event']['name'] ?? '')); $pdf->SetMargins(15, 15, 15); $pdf->SetAutoPageBreak(true, 15);
        if ($document === 'sheets') { $this->sheets($pdf, $run); } else { $this->rankings($pdf, $run, $document); }
        return $pdf->Output('', 'S');
    }

    /** @param array<string, mixed> $run */
    private function sheets(\TCPDF $pdf, array $run): void
    {
        foreach ($run['participants'] as $participant) { if (!is_array($participant)) { continue; } $pdf->AddPage(); $pdf->SetFont('helvetica', 'B', 18); $pdf->Cell(0, 12, 'Laufzettel '.$participant['start_number'], 0, 1); $pdf->SetFont('helvetica', '', 12); $pdf->Cell(0, 8, $participant['first_name'].' '.$participant['last_name'], 0, 1); $pdf->Cell(0, 8, 'Kategorie: '.($participant['category_name'] ?? 'keiner Kategorie zugeordnet'), 0, 1); for ($runNumber = 1; $runNumber <= (int) $run['settings']['qualification_runs']; ++$runNumber) { $pdf->Cell(45, 14, 'Lauf '.$runNumber, 1); $pdf->Cell(0, 14, '', 1, 1); } }
    }

    /** @param array<string, mixed> $run */
    private function rankings(\TCPDF $pdf, array $run, string $document): void
    {
        $groups = $document === 'final' ? $run['finals'] : $run['qualification'];
        $precision = TimePrecision::from((string) $run['settings']['time_precision']);
        foreach ($groups as $group => $rows) {
            $pdf->AddPage();
            $pdf->SetFont('helvetica', 'B', 16);
            $title = $document === 'finalists' ? 'Finalistenliste' : ($document === 'final' ? 'Schlussrangliste' : 'Qualifikationsrangliste');
            $pdf->Cell(0, 10, $title.' – '.$group, 0, 1);
            $pdf->SetFont('helvetica', '', 11);
            foreach ($rows as $row) {
                if (!is_array($row)) { continue; }
                $participant = $run['participants_by_id'][(string) $row['id']] ?? null;
                if (!is_array($participant)) { continue; }
                if ($document === 'finalists' && empty($participant['finalist_confirmed'])) { continue; }
                $rank = $document === 'finalists' ? (string) ($participant['final_start_order'] ?? '') : (string) ($row['rank'] ?? '–');
                $time = $document === 'final'
                    ? (($row['status'] ?? null) === RunStatus::Valid && isset($row['time']) ? TimeValue::format((int) $row['time'], $precision) : strtoupper(($row['status'] ?? RunStatus::Dns)->value))
                    : (isset($row['times'][0]) ? TimeValue::format((int) $row['times'][0], $precision) : '–');
                $pdf->Cell(18, 8, $rank, 1);
                $pdf->Cell(0, 8, $participant['first_name'].' '.$participant['last_name'].'  '.$time, 1, 1);
            }
        }
    }
}
