<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use App\Entity\Book;
use App\Entity\Author;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Entity\User;

class AppFixtures extends Fixture
{
    private $userPasswordHasher;

    public function __construct(UserPasswordHasherInterface $userPasswordHasher)
    {
        $this->userPasswordHasher = $userPasswordHasher;
    }
    public function load(ObjectManager $manager): void
    {

        // Création d'un user normal
        $user = new User();
        $user->setEmail('user@bookapi.com');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($this->userPasswordHasher->hashPassword($user, 'password'));
        $manager->persist($user);

        // Création d'un user admin
        $user = new User();
        $user->setEmail('admin@bookapi.com');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->userPasswordHasher->hashPassword($user, 'password'));
        $manager->persist($user);

        // Création des auteurs
        $listAuthor = [];
        for ($i = 0; $i < 10; $i++) {
            $author = new Author;
            $author->setFirstName('Prénom ' . $i);
            $author->setLastName('Nom ' . $i);
            $manager->persist($author);
            $listAuthor[] = $author;
        }

        // Création des livres
        for ($i = 0; $i < 20; $i++) {
            $book = new Book;
            $book->setTitle('Livre ' . $i); 
            $book->setCoverText('Quatrième de couverture numéro : ' . $i);

            $book->setAuthor($listAuthor[array_rand($listAuthor)]);
            $manager->persist($book);
        }

        $manager->flush();
    }
}
