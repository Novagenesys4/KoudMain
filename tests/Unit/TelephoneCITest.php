<?php

namespace Tests\Unit;

use App\Support\TelephoneCI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TelephoneCITest extends TestCase
{
    #[DataProvider('formatsValides')]
    public function test_numeros_valides_et_normalises(string $saisie, string $attendu): void
    {
        $this->assertTrue(TelephoneCI::estValide($saisie));
        $this->assertSame($attendu, TelephoneCI::normaliser($saisie));
    }

    public static function formatsValides(): array
    {
        return [
            'mobile simple' => ['0712345678', '0712345678'],
            'avec espaces' => ['07 12 34 56 78', '0712345678'],
            'avec points' => ['05.12.34.56.78', '0512345678'],
            'avec tirets' => ['01-12-34-56-78', '0112345678'],
            'préfixe +225' => ['+225 07 12 34 56 78', '0712345678'],
            'préfixe 00225' => ['00225 0712345678', '0712345678'],
            'fixe 21' => ['2112345678', '2112345678'],
            'fixe 25' => ['2512345678', '2512345678'],
            'fixe 27' => ['2712345678', '2712345678'],
        ];
    }

    #[DataProvider('formatsInvalides')]
    public function test_numeros_invalides(?string $saisie): void
    {
        $this->assertFalse(TelephoneCI::estValide($saisie));
    }

    public static function formatsInvalides(): array
    {
        return [
            'null' => [null],
            'vide' => [''],
            'trop court' => ['071234567'],
            'trop long' => ['07123456789'],
            'mauvais préfixe' => ['0612345678'],
            'lettres' => ['07abcdefgh'],
        ];
    }
}
