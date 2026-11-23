<?php
declare(strict_types=1);

namespace EspacioLuz\Export;

use EspacioLuz\Report\Reporte;

/**
 * PDF con TCPDF (texto seleccionable, fuente Unicode):
 * - encabezado en cada página con título, empresa, fecha de emisión, quién lo emitió y filtros;
 * - tabla con títulos repetidos al cambiar de página y fila de totales;
 * - gráfico de barras opcional (reporte gerencial);
 * - pie con «Página X de Y».
 */
final class PdfExportador implements Exportador
{
    private const CAFE = [92, 64, 51];
    private const TERRACOTA = [184, 92, 62];
    private const CREMA = [245, 241, 232];

    public function extension(): string
    {
        return 'pdf';
    }

    public function tipoContenido(): string
    {
        return 'application/pdf';
    }

    public function generar(Reporte $r): string
    {
        $pdf = new ReportePdf('L', 'mm', 'A4', true, 'UTF-8');
        $pdf->reporte = $r;
        $pdf->SetCreator('Espacio Luz Demo');
        $pdf->SetAuthor($r->emisor);
        $pdf->SetTitle($r->titulo);
        $pdf->SetSubject('Reporte generado desde la API /api/v1');
        $pdf->SetMargins(12, 38, 12);
        $pdf->SetHeaderMargin(8);
        $pdf->SetFooterMargin(10);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->setFontSubsetting(true);
        $pdf->AddPage();

        if ($r->nota !== '') {
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->SetTextColor(...self::CAFE);
            $pdf->MultiCell(0, 0, $r->nota, 0, 'L', false, 1);
            $pdf->Ln(2);
        }
        if ($r->grafico) {
            $this->grafico($pdf, $r->grafico);
        }
        $this->tabla($pdf, $r);
        return $pdf->Output('reporte.pdf', 'S');
    }

    private function anchos(\TCPDF $pdf, Reporte $r): array
    {
        $pesos = array_map(fn ($c) => (float) ($c[2] ?? 10), $r->columnas);
        $util = $pdf->getPageWidth() - 24;
        $suma = array_sum($pesos) ?: 1;
        return array_values(array_map(fn ($w) => $util * $w / $suma, $pesos));
    }

    private function encabezadoTabla(\TCPDF $pdf, Reporte $r, array $anchos): void
    {
        $pdf->SetFont('dejavusans', 'B', 7.5);
        $pdf->SetFillColor(...self::CAFE);
        $pdf->SetTextColor(255, 255, 255);
        $i = 0;
        foreach ($r->columnas as [$etiqueta, $tipo]) {
            $pdf->Cell($anchos[$i++], 7, $etiqueta, 0, 0, in_array($tipo, ['texto', 'fecha'], true) ? 'L' : 'R', true);
        }
        $pdf->Ln();
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetTextColor(...self::CAFE);
    }

    private function tabla(\TCPDF $pdf, Reporte $r): void
    {
        $anchos = $this->anchos($pdf, $r);
        $this->encabezadoTabla($pdf, $r, $anchos);
        $par = false;
        foreach ($r->filas as $fila) {
            if ($pdf->GetY() > $pdf->getPageHeight() - 24) {
                $pdf->AddPage();
                $this->encabezadoTabla($pdf, $r, $anchos);
            }
            $pdf->SetFillColor(...($par ? self::CREMA : [255, 255, 255]));
            $i = 0;
            foreach ($r->columnas as $clave => [, $tipo]) {
                $txt = Reporte::texto($fila[$clave] ?? null, $tipo);
                $pdf->Cell($anchos[$i++], 6, mb_strimwidth($txt, 0, 60, '…'), 0, 0, in_array($tipo, ['texto', 'fecha'], true) ? 'L' : 'R', true);
            }
            $pdf->Ln();
            $par = !$par;
        }
        if ($r->totales) {
            $pdf->SetFont('dejavusans', 'B', 7.5);
            $pdf->SetFillColor(234, 224, 207);
            $i = 0;
            foreach ($r->columnas as $clave => [, $tipo]) {
                $txt = $i === 0 ? 'TOTALES' : (array_key_exists($clave, $r->totales) ? Reporte::texto($r->totales[$clave], $tipo) : '');
                $pdf->Cell($anchos[$i++], 7, $txt, 'T', 0, $i === 1 ? 'L' : 'R', true);
            }
            $pdf->Ln();
        }
        $pdf->Ln(3);
        $pdf->SetFont('dejavusans', 'I', 7.5);
        $pdf->Cell(0, 5, number_format(count($r->filas), 0, ',', '.') . ' filas. Documento generado por Espacio Luz Demo (datos sintéticos, sin validez tributaria).', 0, 1);
    }

    /** Barras verticales simples con su valor encima (texto, no imagen). */
    private function grafico(\TCPDF $pdf, array $g): void
    {
        $x0 = 18;
        $y0 = $pdf->GetY() + 8;
        $alto = 48;
        $ancho = $pdf->getPageWidth() - 36;
        $valores = array_map('floatval', $g['valores']);
        $max = max($valores ?: [1]) ?: 1;
        $n = max(1, count($valores));
        $paso = $ancho / $n;
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetTextColor(...self::CAFE);
        $pdf->Text($x0 - 6, $y0 - 7, (string) $g['titulo']);
        $pdf->SetDrawColor(200, 190, 175);
        $pdf->Line($x0, $y0 + $alto, $x0 + $ancho, $y0 + $alto);
        $pdf->SetFont('dejavusans', '', 6);
        foreach ($valores as $i => $v) {
            $h = $alto * $v / $max;
            $x = $x0 + $i * $paso + $paso * 0.15;
            $pdf->SetFillColor(...self::TERRACOTA);
            $pdf->Rect($x, $y0 + $alto - $h, $paso * 0.7, $h, 'F');
            $etq = ($g['tipo'] ?? 'entero') === 'clp' ? '$' . number_format($v / 1000000, 1, ',', '.') . 'M' : number_format($v, 0, ',', '.');
            $pdf->Text($x - 1, $y0 + $alto - $h - 3.5, $etq);
            $pdf->Text($x, $y0 + $alto + 1, (string) ($g['etiquetas'][$i] ?? ''));
        }
        $pdf->SetY($y0 + $alto + 9);
    }
}

/** TCPDF con el encabezado y el pie del reporte. */
final class ReportePdf extends \TCPDF
{
    public ?Reporte $reporte = null;

    public function Header(): void
    {
        if ($this->reporte === null) {
            return;
        }
        $r = $this->reporte;
        $this->SetTextColor(92, 64, 51);
        $this->SetFont('dejavusans', 'B', 13);
        $this->Cell(0, 7, $r->titulo, 0, 1, 'L');
        $this->SetFont('dejavusans', '', 8);
        $this->Cell(0, 4.5, $r->empresa . ' · Emitido el ' . date('d-m-Y H:i') . ' por ' . $r->emisor, 0, 1, 'L');
        $this->MultiCell(0, 4.5, $r->filtrosTexto(), 0, 'L', false, 1);
        $this->SetDrawColor(184, 92, 62);
        $this->Line(12, $this->GetY() + 1, $this->getPageWidth() - 12, $this->GetY() + 1);
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('dejavusans', '', 7.5);
        $this->SetTextColor(133, 119, 107);
        $this->Cell(($this->getPageWidth() - 24) / 2, 5, 'Espacio Luz Demo · ' . ($this->reporte?->titulo ?? ''), 0, 0, 'L');
        $this->Cell(0, 5, 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}
