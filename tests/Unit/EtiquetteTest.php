<?php

namespace Tests\Unit;

use App\Support\Etiquettes\Code128;
use App\Support\Etiquettes\ContenuEtiquette;
use App\Support\Etiquettes\GenerateurZpl;
use App\Support\Uid;
use PHPUnit\Framework\TestCase;

class EtiquetteTest extends TestCase
{
    public function test_uid_complet_avec_caractere_de_controle(): void
    {
        $uid = Uid::generer(Uid::KIT);

        $this->assertMatchesRegularExpression('/^K-[0-9A-Z]{26}-[0-9A-Z]$/', $uid);
        $this->assertSame(30, strlen($uid));
        $this->assertTrue(Uid::estValide($uid));

        // Une faute de frappe sur un caractère est détectée.
        $altere = substr_replace($uid, $uid[5] === '0' ? '1' : '0', 5, 1);
        $this->assertFalse(Uid::estValide($altere));
        $this->assertNotSame(Uid::generer(Uid::KIT), $uid);
    }

    public function test_le_zpl_encode_les_champs_pour_empecher_l_injection(): void
    {
        $zpl = new GenerateurZpl;
        $c = new ContenuEtiquette('article', 'GREENO', 'SAV 07 06 24 87 24', 'Onduleur ^XZ^XA~JR', 'Réf. OND_1', 'SN-1', 'A-0', 'S/N SN-1');
        $sortie = $zpl->generer($c);

        $this->assertStringContainsString('Onduleur _5EXZ_5EXA_7EJR', $sortie);
        $this->assertStringContainsString('R_C3_A9f_2E OND_5F1', $sortie);
        $this->assertSame(1, substr_count($sortie, '^XA'));
        $this->assertSame(1, substr_count($sortie, '^XZ'));
        $this->assertStringContainsString('^BQN,2,3^FH_^FDMA,A_2D0^FS', $sortie); // le QR porte l'UID
        $this->assertStringContainsString('^PW400', $sortie);
        $this->assertStringContainsString('^PW591', $zpl->generer($c, 300));
    }

    public function test_code128_calcule_la_somme_de_controle(): void
    {
        // « PJJ123C » : (104 + 48 + 2×42 + 3×42 + 4×17 + 5×18 + 6×19 + 7×35) mod 103 = 55, motif 311321.
        $modules = Code128::modules('PJJ123C');
        $this->assertSame(11 * 10 + 2, array_sum($modules));
        $this->assertSame([3, 1, 1, 3, 2, 1], array_slice($modules, -13, 6));
        $this->assertStringStartsWith('<svg', Code128::svg('ABC'));
    }
}
