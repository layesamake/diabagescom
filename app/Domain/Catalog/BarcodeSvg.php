<?php

namespace App\Domain\Catalog;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use InvalidArgumentException;

/**
 * Dessin vectoriel (SVG) des codes-barres pour les étiquettes : EAN-13 (et UPC-A,
 * code interne), Code 128 (jeu B) et QR code. Aucun service externe ni extension :
 * le code est encodé ici, le texte lisible est affiché par l'étiquette.
 *
 * Les barres sont dessinées dans un repère de modules (largeur = nombre de
 * modules, hauteur = 1) étiré par l'affichage : l'étiquette fixe la taille.
 */
final class BarcodeSvg
{
    /** Zone de silence (en modules) de part et d'autre des barres. */
    private const int QUIET = 10;

    /** EAN : codes de la série L (parité impaire) par chiffre. */
    private const array EAN_L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];

    /** EAN : série de parité (L/G) des six chiffres de gauche, selon le premier chiffre. */
    private const array EAN_PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    /** Code 128 : largeurs barre/espace alternées de chaque valeur 0 à 106. */
    private const array C128 = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const int C128_START_B = 104;

    private const int C128_STOP = 106;

    /**
     * @throws InvalidArgumentException si le code ne peut pas être dessiné dans ce format
     */
    public static function render(string $code, string $symbology): string
    {
        return match ($symbology) {
            Barcode::QR => self::qr($code),
            Barcode::CODE128 => self::bars(self::code128($code)),
            Barcode::UPCA => self::bars(self::ean13('0'.$code)),
            default => self::bars(self::ean13($code)), // ean13 et code interne
        };
    }

    /**
     * Modules (1 = barre, 0 = espace) d'un EAN-13.
     */
    public static function ean13(string $code): string
    {
        if (preg_match('/^\d{13}$/', $code) !== 1) {
            throw new InvalidArgumentException('EAN-13 : 13 chiffres attendus.');
        }

        $digits = array_map('intval', str_split($code));
        $parity = self::EAN_PARITY[$digits[0]];
        $bits = '101';

        for ($i = 1; $i <= 6; $i++) {
            $left = self::EAN_L[$digits[$i]];
            $bits .= $parity[$i - 1] === 'L' ? $left : strrev(self::complement($left));
        }

        $bits .= '01010';

        for ($i = 7; $i <= 12; $i++) {
            $bits .= self::complement(self::EAN_L[$digits[$i]]);
        }

        return $bits.'101';
    }

    /**
     * Modules d'un Code 128 en jeu B (caractères ASCII imprimables 32 à 126).
     */
    public static function code128(string $code): string
    {
        if ($code === '' || preg_match('/^[ -~]+$/', $code) !== 1) {
            throw new InvalidArgumentException('Code 128 : caractères ASCII imprimables attendus.');
        }

        $values = [self::C128_START_B];
        $sum = self::C128_START_B;

        foreach (str_split($code) as $i => $char) {
            $value = ord($char) - 32;
            $values[] = $value;
            $sum += $value * ($i + 1);
        }

        $values[] = $sum % 103;
        $values[] = self::C128_STOP;
        $bits = '';

        foreach ($values as $value) {
            foreach (str_split(self::C128[$value]) as $k => $width) {
                $bits .= str_repeat($k % 2 === 0 ? '1' : '0', (int) $width);
            }
        }

        return $bits;
    }

    private static function complement(string $bits): string
    {
        return strtr($bits, '01', '10');
    }

    private static function bars(string $modules): string
    {
        $width = strlen($modules) + 2 * self::QUIET;
        $rects = '';

        if (preg_match_all('/1+/', $modules, $runs, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($runs[0] as [$run, $offset]) {
                $rects .= '<rect x="'.($offset + self::QUIET).'" y="0" width="'.strlen($run).'" height="1"/>';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$width.' 1" preserveAspectRatio="none" shape-rendering="crispEdges" fill="#000" role="img" aria-label="Code-barres">'.$rects.'</svg>';
    }

    private static function qr(string $code): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 2), new SvgImageBackEnd)))->writeString($code);

        // Sans l'en-tête XML : l'image est insérée dans une page HTML.
        return trim((string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg));
    }
}
