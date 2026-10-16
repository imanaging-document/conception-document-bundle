<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Service;

final class BookletImposer
{
    public function order(int $count): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Le document est vide.');
        }
        $total = (int) (ceil($count / 4) * 4);
        $sides = [];
        for ($i = 0; $i < $total / 4; ++$i) {
            $sides[] = [$total - 2 * $i, 1 + 2 * $i];
            $sides[] = [2 + 2 * $i, $total - 1 - 2 * $i];
        }
        return array_map(static fn ($side) => array_map(static fn ($page) => $page <= $count ? $page : null, $side), $sides);
    }
    public function impose(string $source, string $destination, bool $rotateBack = false): int
    {
        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi('L', 'mm', [420,297], true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $count = $pdf->setSourceFile($source);
        $sides = $this->order($count);
        foreach ($sides as $index => $side) {
            $pdf->AddPage('L', [420,297]);
            if ($rotateBack && $index % 2 === 1) {
                $pdf->StartTransform();
                $pdf->Rotate(180, 210, 148.5);
            }
            foreach ($side as $column => $page) {
                if ($page === null) {
                    continue;
                }
                $id = $pdf->importPage($page, 'MediaBox', true, true);
                $size = $pdf->getTemplateSize($id);
                if (abs($size['width'] - 210) > .5 || abs($size['height'] - 297) > .5) {
                    throw new \DomainException('Le livret exige des pages A4 portrait.');
                }
                $pdf->useTemplate($id, $column * 210, 0, 210, 297);
            }
            if ($rotateBack && $index % 2 === 1) {
                $pdf->StopTransform();
            }
        }
        $pdf->Output($destination, 'F');
        return count($sides);
    }
}
