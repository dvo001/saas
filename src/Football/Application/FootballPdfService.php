<?php

declare(strict_types=1);

namespace App\Football\Application;

use App\Core\Application\Document\EventPdfBranding;

final readonly class FootballPdfService
{
    public function __construct(private ?EventPdfBranding $branding = null) {}

    /**
     * @param array<string, mixed> $football
     * @param array{version?: int, created_at?: string} $metadata
     */
    public function create(array $football, string $document, array $metadata = []): string
    {
        if (($football['event']['status'] ?? null) === 'cancelled') { throw new \DomainException('Für abgebrochene Turniere dürfen keine offiziellen Fussballdokumente erzeugt werden.'); }
        $allowed = ['schedule', 'schedule_category', 'schedule_field', 'schedule_time', 'standings', 'finals', 'final_rankings'];
        if (!in_array($document, $allowed, true)) { throw new \DomainException('Unbekanntes Fussballdokument.'); }
        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8'); if ($this->branding !== null) { $this->branding->apply($pdf, is_array($football['event'] ?? null) ? $football['event'] : [], $metadata); } else { $pdf->SetMargins(12, 12, 12); $pdf->SetAutoPageBreak(true, 12); }
        if (str_starts_with($document, 'schedule') || $document === 'finals') { $this->schedule($pdf, $football, $document); }
        elseif ($document === 'standings') { $this->standings($pdf, $football); }
        else { $this->finalRankings($pdf, $football); }
        return $pdf->Output('', 'S');
    }

    /** @param array<string, mixed> $football */
    private function schedule(\TCPDF $pdf, array $football, string $document): void
    {
        $matches = is_array($football['matches'] ?? null) ? $football['matches'] : []; $groups = [];
        foreach ($matches as $match) {
            if (!is_array($match)) { continue; }
            if ($document === 'finals' && ($match['stage'] ?? 'group') === 'group') { continue; }
            $key = match ($document) { 'schedule_category' => (string) ($match['category_name'] ?? 'Ohne Kategorie'), 'schedule_field' => (string) ($match['field_name'] ?? 'Ohne Feld'), default => 'Gesamtspielplan' };
            $groups[$key][] = $match;
        }
        foreach ($groups as $group => $rows) {
            if ($document === 'schedule') { usort($rows, static fn (array $left, array $right): int => (int) ($left['sequence_number'] ?? 0) <=> (int) ($right['sequence_number'] ?? 0)); }
            else { usort($rows, static fn (array $left, array $right): int => [(string) ($left['scheduled_start'] ?? ''), (int) ($left['sequence_number'] ?? 0)] <=> [(string) ($right['scheduled_start'] ?? ''), (int) ($right['sequence_number'] ?? 0)]); }
            $title = $document === 'finals' ? 'Finalrunde' : ($document === 'schedule_time' ? 'Spielplan nach Zeit' : 'Spielplan');
            $pdf->AddPage(); $pdf->SetFont('helvetica', 'B', 16); $pdf->Cell(0, 10, $title.' – '.$group, 0, 1); $pdf->SetFont('helvetica', '', 9);
            foreach ($rows as $match) { $time = $this->dateTime($match['scheduled_start'] ?? null); $label = $time.'  '.($match['field_name'] ?? 'ohne Feld').'  '.($match['home_name'] ?? 'offen').' – '.($match['away_name'] ?? 'offen'); if (($match['status'] ?? '') === 'played') { $label .= '  '.($match['home_goals'] ?? 0).':'.($match['away_goals'] ?? 0); if (($match['home_penalties'] ?? null) !== null) { $label .= ', '.$match['home_penalties'].':'.$match['away_penalties'].' n.P.'; } } elseif (in_array($match['status'] ?? '', ['cancelled', 'void'], true)) { $label .= '  '.strtoupper((string) $match['status']); } $pdf->Cell(0, 7, $label, 1, 1); }
        }
        if ($groups === []) { $pdf->AddPage(); $pdf->SetFont('helvetica', '', 12); $pdf->Cell(0, 10, 'Keine Spiele verfügbar.', 0, 1); }
    }

    /** @param array<string, mixed> $football */
    private function standings(\TCPDF $pdf, array $football): void
    {
        foreach (($football['standings'] ?? []) as $group) {
            if (!is_array($group)) { continue; } $pdf->AddPage(); $pdf->SetFont('helvetica', 'B', 16); $pdf->Cell(0, 10, 'Gruppenrangliste – '.($group['category_name'] ?? '').' / '.($group['group_name'] ?? ''), 0, 1); $pdf->SetFont('helvetica', '', 9);
            foreach (($group['rows'] ?? []) as $row) { if (!is_array($row)) { continue; } $pdf->Cell(12, 7, (string) ($row['position'] ?? ''), 1); $pdf->Cell(75, 7, (string) ($row['team_name'] ?? ''), 1); $pdf->Cell(18, 7, (string) ($row['played'] ?? 0).' Sp.', 1); $pdf->Cell(25, 7, ($row['goals_for'] ?? 0).':'.($row['goals_against'] ?? 0), 1); $pdf->Cell(20, 7, (string) ($row['points'] ?? 0).' P.', 1, 1); }
        }
        if (($football['standings'] ?? []) === []) { $pdf->AddPage(); $pdf->SetFont('helvetica', '', 12); $pdf->Cell(0, 10, 'Keine Gruppenranglisten verfügbar.', 0, 1); }
    }

    /** @param array<string, mixed> $football */
    private function finalRankings(\TCPDF $pdf, array $football): void
    {
        $categoryNames = []; foreach (($football['categories'] ?? []) as $category) { if (is_array($category)) { $categoryNames[(string) $category['public_id']] = (string) $category['name']; } }
        foreach (($football['final_rankings'] ?? []) as $categoryId => $rows) { if (!is_array($rows)) { continue; } $pdf->AddPage(); $pdf->SetFont('helvetica', 'B', 16); $pdf->Cell(0, 10, 'Schlussrangliste – '.($categoryNames[(string) $categoryId] ?? $categoryId), 0, 1); $pdf->SetFont('helvetica', '', 11); foreach ($rows as $row) { if (is_array($row)) { $pdf->Cell(18, 8, (string) ($row['position'] ?? ''), 1); $pdf->Cell(0, 8, (string) ($row['team_name'] ?? ''), 1, 1); } } }
        if (($football['final_rankings'] ?? []) === []) { $pdf->AddPage(); $pdf->SetFont('helvetica', '', 12); $pdf->Cell(0, 10, 'Keine Schlussranglisten verfügbar.', 0, 1); }
    }

    private function dateTime(mixed $value): string
    {
        if (!is_string($value) || $value === '') { return 'nicht angesetzt'; }
        try { return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('d.m.Y H:i'); } catch (\Exception) { return 'ungültige Zeit'; }
    }
}
