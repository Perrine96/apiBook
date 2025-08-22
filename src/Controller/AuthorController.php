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
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur pour gérer les opérations CRUD sur les auteurs
 * 
 * Ce contrôleur fournit toutes les opérations CRUD (Create, Read, Update, Delete)
 * pour l'entité Author avec gestion des erreurs et validation des données.
 */
final class AuthorController extends AbstractController
{
    /**
     * Récupère tous les auteurs avec pagination et cache
     * 
     * Route: GET /api/authors
     * 
     * @param AuthorRepository $authorRepository Repository pour accéder aux données des auteurs
     * @param SerializerInterface $serializer Service de sérialisation pour convertir en JSON
     * @param Request $request Requête HTTP pour récupérer les paramètres de pagination
     * @param TagAwareCacheInterface $cache Service de cache
     * @return JsonResponse Liste de tous les auteurs au format JSON
     */
    #[Route('/api/authors', name: 'authors_list', methods: ['GET'])]
    public function getAllAuthors(AuthorRepository $authorRepository, SerializerInterface $serializer, Request $request, TagAwareCacheInterface $cache): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(50, (int) $request->query->get('limit', 3)));
        
        $idCache = "getAllAuthors-" . $page . "-" . $limit;
        $authorList = $cache->get($idCache, function (ItemInterface $item) use ($authorRepository, $page, $limit) {
            $item->tag('authorsCache');
            $offset = ($page - 1) * $limit;
            return $authorRepository->findBy([], ['id' => 'ASC'], $limit, $offset);
        });
        
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
        
        // Lever une exception HTTP au lieu de retourner une réponse vide
        throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException("Auteur avec l'ID $id non trouvé");
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
    public function createAuthor(Request $request, SerializerInterface $serializer, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator, ValidatorInterface $validator, TagAwareCacheInterface $cache): JsonResponse
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
            
            // Invalider le cache des auteurs
            $cache->invalidateTags(['authorsCache']);
            
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
    public function updateAuthor(Author $currentAuthor, Request $request, SerializerInterface $serializer, EntityManagerInterface $em, ValidatorInterface $validator, TagAwareCacheInterface $cache): JsonResponse
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
            
            // Invalider le cache des auteurs
            $cache->invalidateTags(['authorsCache']);
            
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
    public function deleteAuthor(Author $author, EntityManagerInterface $em, TagAwareCacheInterface $cache): JsonResponse
    {
        // Supprimer l'auteur (les livres seront supprimés automatiquement grâce au cascade: ['remove'])
        $em->remove($author);
        $em->flush();
        
        // Invalider le cache des auteurs et des livres
        $cache->invalidateTags(['authorsCache', 'booksCache']);
        
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Vide le cache des auteurs
     * 
     * Route: POST /api/authors/clear-cache
     * 
     * @param TagAwareCacheInterface $cache Service de cache
     * @return JsonResponse Confirmation de suppression du cache
     */
    #[Route('/api/authors/clear-cache', name: 'clear_authors_cache', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les permissions pour vider le cache')]
    public function clearAuthorsCache(TagAwareCacheInterface $cache): JsonResponse
    {
        $cache->invalidateTags(['authorsCache']);
        
        return new JsonResponse(['message' => 'Cache des auteurs vidé avec succès'], Response::HTTP_OK);
    }
}
