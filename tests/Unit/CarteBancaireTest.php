<?php

namespace Tests\Unit;

use App\Support\CarteBancaire;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Les contrôles d'une carte saisie : réseau, longueur, clé de Luhn, code de sécurité, expiration, masque et empreinte. */
class CarteBancaireTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $maintenant = CarbonImmutable::parse('2026-09-21 09:00:00');
        CarbonImmutable::setTestNow($maintenant);
        Carbon::setTestNow($maintenant);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_le_reseau_se_lit_sur_les_premiers_chiffres(): void
    {
        $this->assertSame('visa', CarteBancaire::reseau('4242 4242 4242 4242'));
        $this->assertSame('mastercard', CarteBancaire::reseau('5555555555554444'));
        $this->assertSame('mastercard', CarteBancaire::reseau('2221000000000009'));
        $this->assertSame('mastercard', CarteBancaire::reseau('2720990000000000'));
        $this->assertSame('amex', CarteBancaire::reseau('378282246310005'));
        $this->assertSame('amex', CarteBancaire::reseau('341111111111111'));
        $this->assertNull(CarteBancaire::reseau('6011111111111117'), 'Discover n\'est pas accepté');
        $this->assertNull(CarteBancaire::reseau('2220999999999999'));
        $this->assertNull(CarteBancaire::reseau('2721000000000000'));
        $this->assertNull(CarteBancaire::reseau(''));
    }

    public function test_les_longueurs_dependent_du_reseau(): void
    {
        $this->assertSame(15, CarteBancaire::longueur('amex'));
        $this->assertSame(16, CarteBancaire::longueur('visa'));
        $this->assertSame(16, CarteBancaire::longueur('mastercard'));
        $this->assertSame(4, CarteBancaire::longueurCvv('amex'));
        $this->assertSame(3, CarteBancaire::longueurCvv('visa'));
    }

    public function test_la_cle_de_luhn_detecte_une_faute_de_frappe(): void
    {
        $this->assertTrue(CarteBancaire::luhn('4242424242424242'));
        $this->assertFalse(CarteBancaire::luhn('4242424242424241'));
        $this->assertFalse(CarteBancaire::luhn(''));
        $this->assertTrue(CarteBancaire::numeroValide('4242-4242-4242-4242'));
        $this->assertTrue(CarteBancaire::numeroValide('378282246310005'));
        $this->assertFalse(CarteBancaire::numeroValide('37828224631000'), 'un Amex a 15 chiffres, pas 14');
        $this->assertFalse(CarteBancaire::numeroValide('424242424242424'), 'une Visa a 16 chiffres, pas 15');
        $this->assertFalse(CarteBancaire::numeroValide('4242424242424241'));
    }

    public function test_le_code_de_securite_a_3_chiffres_ou_4_pour_amex(): void
    {
        $this->assertTrue(CarteBancaire::cvvValide('123', 'visa'));
        $this->assertFalse(CarteBancaire::cvvValide('1234', 'visa'));
        $this->assertFalse(CarteBancaire::cvvValide('12', 'mastercard'));
        $this->assertTrue(CarteBancaire::cvvValide('1234', 'amex'));
        $this->assertFalse(CarteBancaire::cvvValide('123', 'amex'));
        $this->assertFalse(CarteBancaire::cvvValide('12a', 'visa'));
        $this->assertFalse(CarteBancaire::cvvValide(null, 'visa'));
    }

    public function test_l_expiration_se_lit_en_mm_aa_et_doit_etre_valable(): void
    {
        $this->assertSame([9, 2029], CarteBancaire::lireExpiration('09/29'));
        $this->assertSame([12, 2030], CarteBancaire::lireExpiration(' 12 / 2030 '));
        $this->assertNull(CarteBancaire::lireExpiration('13/29'));
        $this->assertNull(CarteBancaire::lireExpiration('0929'));
        $this->assertNull(CarteBancaire::lireExpiration('00/29'));

        $this->assertTrue(CarteBancaire::expirationValide('09/26'), 'valable jusqu\'à la fin du mois en cours');
        $this->assertFalse(CarteBancaire::expirationValide('08/26'), 'le mois dernier : expirée');
        $this->assertTrue(CarteBancaire::expirationValide('12/29'));
        $this->assertFalse(CarteBancaire::expirationValide('12/45'), 'trop lointaine : faute de frappe probable');
        $this->assertFalse(CarteBancaire::expirationValide('abc'));
        $this->assertSame('09/29', CarteBancaire::formaterExpiration('09/2029'));
    }

    public function test_le_masque_ne_montre_que_les_quatre_derniers_chiffres(): void
    {
        $this->assertSame('**** **** **** 4242', CarteBancaire::masque('4242 4242 4242 4242'));
        $this->assertSame('**** ****** *0005', CarteBancaire::masque('378282246310005'));
    }

    public function test_l_empreinte_est_stable_a_sens_unique_et_ne_contient_pas_le_numero(): void
    {
        $a = CarteBancaire::empreinte('4242 4242 4242 4242');

        $this->assertSame($a, CarteBancaire::empreinte('4242424242424242'));
        $this->assertNotSame($a, CarteBancaire::empreinte('5555555555554444'));
        $this->assertSame(64, strlen($a));
        $this->assertStringNotContainsString('4242', $a);
    }
}
