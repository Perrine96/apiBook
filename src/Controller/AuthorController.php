<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\AuthorRepository;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Author;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Contrôleur pour gérer les opérations CRUD sur les auteurs
 * 
 * Ce contrôleur fournit toutes les opérations CRUD (Create, Read, Update, Delete)
 * pour l'entité Author avec gestion des erreurs et validation des données.
 */
final class AuthorController extends AbstractController
{
    /**
     * Récupère tous les auteurs
     * 
     * Route: GET /api/authors
     * 
     * @param AuthorRepository $authorRepository Repository pour accéder aux données des auteurs
     * @param SerializerInterface $serializer Service de sérialisation pour convertir en JSON
     * @return JsonResponse Liste de tous les auteurs au format JSON
     */
    #[Route('/api/authors', name: 'authors_list', methods: ['GET'])]
    public function getAllAuthors(AuthorRepository $authorRepository, SerializerInterface $serializer): JsonResponse
    {
        $authorList = $authorRepository->findAll();
        $jsonAuthorList = $serializer->serialize($authorList, 'json', ['groups' => 'getBooks']);
        return new JsonResponse($jsonAuthorList, Response::HTTP_OK, [], true);
    }

    /**
     * Récupère un auteur spécifique par son ID
     * 
     * Route: GET /api/authors/{id}
     * 
     * @param int $id ID de l'auteur à récupérer
     * @param SerializerInterface $serializer Service de sérialisation
     * @param AuthorRepository $authorRepository Repository des auteurs
     * @return JsonResponse Auteur au format JSON ou 404 si non trouvé
     */
    #[Route('/api/authors/{id}', name: 'author_detail', methods: ['GET'])]
    public function getAuthor(int $id, SerializerInterface $serializer, AuthorRepository $authorRepository): JsonResponse
    {
        $author = $authorRepository->find($id);
        if ($author) {
            $jsonAuthor = $serializer->serialize($author, 'json', ['groups' => 'getBooks']);
            return new JsonResponse($jsonAuthor, Response::HTTP_OK, [], true);
        }
        return new JsonResponse(null, Response::HTTP_NOT_FOUND);
    }

    /**
     * Crée un nouvel auteur
     * 
     * Route: POST /api/authors
     * 
     * Cette méthode :
     * 1. Désérialise les données JSON reçues en objet Author
     * 2. Valide les données avec les contraintes définies dans l'entité
     * 3. Persiste l'auteur en base de données
     * 4. Retourne l'auteur créé avec un header Location
     * 
     * @param Request $request Requête HTTP contenant les données JSON
     * @param SerializerInterface $serializer Service de sérialisation
     * @param EntityManagerInterface $em Gestionnaire d'entités Doctrine
     * @param UrlGeneratorInterface $urlGenerator Générateur d'URLs
     * @param ValidatorInterface $validator Service de validation
     * @return JsonResponse Auteur créé ou erreurs de validation
     */
    #[Route('/api/authors', name: 'create_author', methods: ['POST'])]
    public function createAuthor(Request $request, SerializerInterface $serializer, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator, ValidatorInterface $validator): JsonResponse
    {
        try {
            // Désérialiser les données JSON en objet Author
            $author = $serializer->deserialize($request->getContent(), Author::class, 'json');
            
            // Valider les données avec les contraintes définies dans l'entité
            $errors = $validator->validate($author);
            if (count($errors) > 0) {
                $errorMessages = [];
                foreach ($errors as $error) {
                    $errorMessages[] = $error->getMessage();
                }
                return new JsonResponse(['errors' => $errorMessages], Response::HTTP_BAD_REQUEST);
            }
            
            // Persister l'auteur en base de données
            $em->persist($author);
            $em->flush();
            
            // Sérialiser l'auteur créé pour la réponse
            $jsonAuthor = $serializer->serialize($author, 'json', ['groups' => 'getBooks']);
            
            // Générer l'URL de l'auteur créé pour le header Location
            $location = $urlGenerator->generate('author_detail', ['id' => $author->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
            
            return new JsonResponse($jsonAuthor, Response::HTTP_CREATED, ["Location" => $location], true);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Erreur lors de la création de l\'auteur: ' . $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Met à jour un auteur existant
     * 
     * Route: PUT /api/authors/{id}
     * 
     * Cette méthode utilise AbstractNormalizer::OBJECT_TO_POPULATE pour
     * mettre à jour l'objet existant au lieu d'en créer un nouveau.
     * 
     * @param Author $currentAuthor Auteur à modifier (injecté automatiquement par Symfony)
     * @param Request $request Requête HTTP avec les nouvelles données
     * @param SerializerInterface $serializer Service de sérialisation
     * @param EntityManagerInterface $em Gestionnaire d'entités
     * @param ValidatorInterface $validator Service de validation
     * @return JsonResponse Réponse 204 si succès ou erreurs de validation
     */
    #[Route('/api/authors/{id}', name: 'update_author', methods: ['PUT'])]
    public function updateAuthor(Author $currentAuthor, Request $request, SerializerInterface $serializer, EntityManagerInterface $em, ValidatorInterface $validator): JsonResponse
    {
        try {
            // Mettre à jour l'auteur existant avec les nouvelles données
            $updatedAuthor = $serializer->deserialize(
                $request->getContent(), 
                Author::class, 
                'json', 
                [\Symfony\Component\Serializer\Normalizer\AbstractNormalizer::OBJECT_TO_POPULATE => $currentAuthor]
            );
            
            // Valider les données mises à jour
            $errors = $validator->validate($updatedAuthor);
            if (count($errors) > 0) {
                $errorMessages = [];
                foreach ($errors as $error) {
                    $errorMessages[] = $error->getMessage();
                }
                return new JsonResponse(['errors' => $errorMessages], Response::HTTP_BAD_REQUEST);
            }
            
            // Persister les modifications
            $em->persist($updatedAuthor);
            $em->flush();
            
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Erreur lors de la mise à jour de l\'auteur: ' . $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Supprime un auteur et tous ses livres associés
     * 
     * Route: DELETE /api/authors/{id}
     * 
     * ATTENTION: Cette opération supprime en cascade tous les livres associés à l'auteur
     * grâce à la configuration cascade: ['remove'] dans l'entité Author.
     * 
     * @param Author $author Auteur à supprimer (injecté automatiquement par Symfony)
     * @param EntityManagerInterface $em Gestionnaire d'entités
     * @return JsonResponse Réponse 204 si succès
     */
    #[Route('/api/authors/{id}', name: 'delete_author', methods: ['DELETE'])]
    public function deleteAuthor(Author $author, EntityManagerInterface $em): JsonResponse
    {
        // Supprimer l'auteur (les livres seront supprimés automatiquement grâce au cascade: ['remove'])
        $em->remove($author);
        $em->flush();
        
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
