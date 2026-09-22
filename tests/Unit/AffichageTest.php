<?php

namespace Tests\Unit;

use App\Support\Duree;
use App\Support\Format;
use App\Support\RechercheCriteres;
use PHPUnit\Framework\TestCase;

class AffichageTest extends TestCase
{
    public function test_les_durees_sont_lisibles(): void
    {
        $this->assertNull(Duree::libelle(null));
        $this->assertNull(Duree::libelle(0));
        $this->assertSame('30 min', Duree::libelle(30));
        $this->assertSame('1 h', Duree::libelle(60));
        $this->assertSame('1 h 30', Duree::libelle(90));
        $this->assertSame('8 h', Duree::libelle(480));
        $this->assertSame('1 jour', Duree::libelle(1440));
        $this->assertSame('2 jours', Duree::libelle(2880));
    }

    public function test_les_notes_et_pluriels(): void
    {
        $this->assertSame('4,9', Format::note(4.85));
        $this->assertSame('5,0', Format::note(5.0));
        $this->assertSame('0 prestation', Format::pluriel(0, 'prestation'));
        $this->assertSame('1 prestation', Format::pluriel(1, 'prestation'));
        $this->assertSame('2 prestations', Format::pluriel(2, 'prestation'));
        $this->assertSame('3 avis', Format::pluriel(3, 'avis', 'avis'));
    }

    public function test_les_criteres_ignorent_les_valeurs_invalides(): void
    {
        $criteres = RechercheCriteres::depuis(['q' => '  Plomb  ', 'categorie' => 'abc', 'service' => '-4', 'zone' => 'x:1', 'prix_min' => 'zz', 'note_min' => '9', 'tri' => 'hack', 'photo' => 'peut-être']);

        $this->assertSame('Plomb', $criteres->q);
        $this->assertSame(0, $criteres->categorie);
        $this->assertSame(0, $criteres->service);
        $this->assertSame('', $criteres->zone);
        $this->assertNull($criteres->prixMin);
        $this->assertSame(0.0, $criteres->noteMin);
        $this->assertSame('pertinence', $criteres->tri, 'avec un texte, le tri par défaut est la pertinence');
        $this->assertFalse($criteres->avecPhoto);
    }

    public function test_les_prix_inverses_sont_remis_dans_l_ordre(): void
    {
        $criteres = RechercheCriteres::depuis(['prix_min' => '50000', 'prix_max' => '10000']);

        $this->assertSame([10000, 50000], [$criteres->prixMin, $criteres->prixMax]);
    }

    public function test_les_jetons_sont_nettoyes_pour_la_requete(): void
    {
        $criteres = RechercheCriteres::depuis(['q' => "Réparation d'éviers ; DROP TABLE -- é % ' \\ a"]);

        $this->assertSame(['reparation', 'eviers', 'drop', 'table'], $criteres->jetons(), 'que des lettres et chiffres, mots de 2 lettres minimum');
        $this->assertLessThanOrEqual(6, count(RechercheCriteres::depuis(['q' => 'aa bb cc dd ee ff gg hh'])->jetons()));
    }

    public function test_les_liens_de_filtres_gardent_les_autres_criteres(): void
    {
        $criteres = RechercheCriteres::depuis(['q' => 'coiffure', 'categorie' => '3', 'prix_min' => '5000', 'tri' => 'prix_asc']);

        $this->assertSame(['q' => 'coiffure', 'prix_min' => 5000, 'tri' => 'prix_asc'], $criteres->versQuery(['categorie']));
    }
}
