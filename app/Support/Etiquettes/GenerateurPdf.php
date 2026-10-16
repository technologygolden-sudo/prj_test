<?php

namespace App\Support\Etiquettes;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Étiquettes PDF 50 × 30 mm pour une imprimante de bureau (dossier v2, section 6.1) :
 * une page par étiquette, même contenu que le gabarit ZPL.
 */
class GenerateurPdf
{
    /** 50 × 30 mm en points PDF. */
    private const FORMAT = [0, 0, 141.73, 85.04];

    /** @param list<ContenuEtiquette> $etiquettes */
    public function generer(array $etiquettes, int $copies = 1): string
    {
        $pages = [];
        foreach ($etiquettes as $c) {
            $page = [
                'c' => $c,
                'qr' => 'data:image/svg+xml;base64,'.base64_encode($this->qr($c->qr)),
                'code' => 'data:image/svg+xml;base64,'.base64_encode(Code128::svg($c->codeBarres)),
            ];
            for ($i = 0; $i < max(1, min(100, $copies)); $i++) {
                $pages[] = $page;
            }
        }

        return Pdf::loadView('etiquettes.pdf', ['pages' => $pages])
            ->setPaper(self::FORMAT)
            ->output();
    }

    private function qr(string $texte): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 0), new SvgImageBackEnd));

        return $writer->writeString($texte, Encoder::DEFAULT_BYTE_MODE_ENCODING, ErrorCorrectionLevel::M());
    }
}
