<?php

namespace App\DataFixtures;

use App\Entity\MemberProject;
use App\Factory\MemberFactory;
use App\Factory\ProjectFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Usage:
 *   bin/console doctrine:fixtures:load --group=staging --no-interaction
 *   bin/console doctrine:fixtures:load --group=dev --no-interaction
 */
class AppFixtures extends Fixture implements FixtureGroupInterface
{
    public static function getGroups(): array
    {
        return ['staging', 'dev'];
    }

    public function load(ObjectManager $manager): void
    {
        $pass = 'asdasd';

        $ezequiel   = MemberFactory::createOne(['name' => 'Ezequiel Torrontegui',  'email' => 'ezequiel.torrontegui@gmail.com',  'password' => $pass]);
        $brunilda   = MemberFactory::createOne(['name' => 'Brunilda Fasciotti',    'email' => 'brunilda.fasciotti@gmail.com',    'password' => $pass]);
        $onofre     = MemberFactory::createOne(['name' => 'Onofre Zampedri',       'email' => 'onofre.zampedri@gmail.com',       'password' => $pass]);
        $clotilde   = MemberFactory::createOne(['name' => 'Clotilde Menvielle',    'email' => 'clotilde.menvielle@gmail.com',    'password' => $pass]);
        $dagoberto  = MemberFactory::createOne(['name' => 'Dagoberto Quirasco',    'email' => 'dagoberto.quirasco@gmail.com',    'password' => $pass]);
        $ermengarda = MemberFactory::createOne(['name' => 'Ermengarda Peluffo',    'email' => 'ermengarda.peluffo@gmail.com',    'password' => $pass]);
        $walterio   = MemberFactory::createOne(['name' => 'Walterio Cenciarelli',  'email' => 'walterio.cenciarelli@gmail.com',  'password' => $pass]);
        $serafina   = MemberFactory::createOne(['name' => 'Serafina Duhalde',      'email' => 'serafina.duhalde@gmail.com',      'password' => $pass]);
        $nepomuceno = MemberFactory::createOne(['name' => 'Nepomuceno Biasotti',   'email' => 'nepomuceno.biasotti@gmail.com',   'password' => $pass]);
        $genoveva   = MemberFactory::createOne(['name' => 'Genoveva Troncoso',     'email' => 'genoveva.troncoso@gmail.com',     'password' => $pass]);
        $baldomero  = MemberFactory::createOne(['name' => 'Baldomero Schiaffino',  'email' => 'baldomero.schiaffino@gmail.com',  'password' => $pass]);
        $ildegarda  = MemberFactory::createOne(['name' => 'Ildegarda Capobianco',  'email' => 'ildegarda.capobianco@gmail.com',  'password' => $pass]);
        $timoteo    = MemberFactory::createOne(['name' => 'Timoteo Zorzoli',       'email' => 'timoteo.zorzoli@gmail.com',       'password' => $pass]);
        $cunegunda  = MemberFactory::createOne(['name' => 'Cunegunda Ferreyros',   'email' => 'cunegunda.ferreyros@gmail.com',   'password' => $pass]);
        $aristobulo = MemberFactory::createOne(['name' => 'Aristóbulo Mangiante',  'email' => 'aristobulo.mangiante@gmail.com',  'password' => $pass]);

        MemberFactory::createOne([
            'name'     => 'Marcos Soler',
            'email'    => 'marcosfsoler@gmail.com',
            'password' => 'marcos32',
            'roles'    => ['ROLE_ADMIN'],
        ]);

        $cultivo     = ProjectFactory::createOne(['name' => 'Sala de cultivo']);
        $invernadero = ProjectFactory::createOne(['name' => 'Invernadero hidropónico']);
        $vivero      = ProjectFactory::createOne(['name' => 'Vivero comunitario']);
        $taller      = ProjectFactory::createOne(['name' => 'Taller de herramientas']);
        $almacen     = ProjectFactory::createOne(['name' => 'Almacén común']);

        $associations = [
            [$ezequiel,   $cultivo,     '20.00'],
            [$brunilda,   $cultivo,     '20.00'],
            [$onofre,     $cultivo,     '20.00'],
            [$clotilde,   $cultivo,     '20.00'],
            [$dagoberto,  $cultivo,     '20.00'],

            [$ermengarda, $invernadero, '35.00'],
            [$walterio,   $invernadero, '30.00'],
            [$serafina,   $invernadero, '20.00'],
            [$nepomuceno, $invernadero, '15.00'],

            [$genoveva,   $vivero,      '40.00'],
            [$baldomero,  $vivero,      '35.00'],
            [$ildegarda,  $vivero,      '25.00'],

            [$timoteo,    $taller,      '25.00'],
            [$cunegunda,  $taller,      '20.00'],
            [$aristobulo, $taller,      '20.00'],
            [$ezequiel,   $taller,      '15.00'],
            [$brunilda,   $taller,      '12.00'],
            [$onofre,     $taller,       '8.00'],

            [$clotilde,   $almacen,     '30.00'],
            [$dagoberto,  $almacen,     '25.00'],
            [$ermengarda, $almacen,     '25.00'],
            [$walterio,   $almacen,     '20.00'],
        ];

        foreach ($associations as [$member, $project, $pct]) {
            $manager->persist(new MemberProject($member->_real(), $project->_real(), $pct));
        }

        $manager->flush();
    }
}
