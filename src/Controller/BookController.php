<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\BookRepository;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Book;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Repository\AuthorRepository;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Contrôleur pour gérer les opérations CRUD sur les livres
 * 
 * Ce contrôleur fournit toutes les opérations CRUD (Create, Read, Update, Delete)
 * pour l'entité Book avec gestion des relations avec les auteurs.
 */
final class BookController extends AbstractController
{
    /**
     * Récupère tous les livres
     * 
     * Route: GET /api/books
     * 
     * @param BookRepository $bookRepository Repository pour accéder aux données des livres
     * @param SerializerInterface $serializer Service de sérialisation pour convertir en JSON
     * @return JsonResponse Liste de tous les livres au format JSON
     */
    #[Route('/api/books', name: 'book', methods: ['GET'])]
    public function getBookList(BookRepository $bookRepository, SerializerInterface $serializer, Request $request, TagAwareCacheInterface $cache): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(50, (int) $request->query->get('limit', 3)));
        
        $idCache = "getBookList-" . $page . "-" . $limit;
        $bookList = $cache->get($idCache, function (ItemInterface $item) use ($bookRepository, $page, $limit) {
            $item->tag('booksCache');
            $offset = ($page - 1) * $limit;
            return $bookRepository->findBy([], ['id' => 'ASC'], $limit, $offset);
        });

        $jsonBookList = $serializer->serialize($bookList, 'json', ['groups' => 'getBooks']); 
        return new JsonResponse($jsonBookList, Response::HTTP_OK, [], true);
    }

    /**
     * Récupère un livre spécifique par son ID
     * 
     * Route: GET /api/books/{id}
     * 
     * @param int $id ID du livre à récupérer
     * @param SerializerInterface $serializer Service de sérialisation
     * @param BookRepository $bookRepository Repository des livres
     * @return JsonResponse Livre au format JSON ou 404 si non trouvé
     */
    #[Route('/api/books/{id}', name: 'detailBook', methods: ['GET'])]
    public function getDetailBook(int $id, SerializerInterface $serializer, BookRepository $bookRepository): JsonResponse {
        $book = $bookRepository->find($id);
        if ($book) {
            $jsonBook = $serializer->serialize($book, 'json', ['groups' => 'getBooks']); 
            return new JsonResponse($jsonBook, Response::HTTP_OK, [], true);
        }
        
        // Lever une exception HTTP au lieu de retourner une réponse vide
        throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException("Livre avec l'ID $id non trouvé");
    }

    /**
     * Supprime un livre
     * 
     * Route: DELETE /api/books/{id}
     * 
     * @param Book $book Livre à supprimer (injecté automatiquement par Symfony)
     * @param EntityManagerInterface $em Gestionnaire d'entités
     * @return JsonResponse Réponse 204 si succès
     */
    #[Route('/api/books/{id}', name: 'deleteBook', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les permissions pour supprimer un livre')]
    public function deleteBook(Book $book, EntityManagerInterface $em, TagAwareCacheInterface $cache): JsonResponse {
        $cache->invalidateTags(['booksCache']);
        $em->remove($book);
        $em->flush();
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Crée un nouveau livre
     * 
     * Route: POST /api/books
     * 
     * Cette méthode gère deux cas pour l'auteur :
     * 1. Si l'auteur est passé comme ID (nombre), elle récupère l'auteur existant
     * 2. Si l'auteur est passé comme objet complet, elle crée un nouvel auteur
     * 
     * @param Request $request Requête HTTP contenant les données JSON
     * @param SerializerInterface $serializer Service de sérialisation
     * @param EntityManagerInterface $em Gestionnaire d'entités Doctrine
     * @param UrlGeneratorInterface $urlGenerator Générateur d'URLs
     * @param ValidatorInterface $validator Service de validation
     * @return JsonResponse Livre créé ou erreurs de validation
     */
    #[Route('/api/books', name: 'createBook', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les permissions pour créer un livre')]
    public function createBook(Request $request, SerializerInterface $serializer, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator, ValidatorInterface $validator, TagAwareCacheInterface $cache): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);
            
            // Créer le livre sans l'auteur d'abord
            $bookData = $data;
            unset($bookData['author']);
            
            $book = $serializer->deserialize(json_encode($bookData), Book::class, 'json');
            
            // Gérer l'auteur séparément
            if (isset($data['author'])) {
                if (is_numeric($data['author'])) {
                    // L'auteur est passé comme ID
                    $author = $em->getRepository(\App\Entity\Author::class)->find($data['author']);
                    if (!$author) {
                        return new JsonResponse(['error' => 'Auteur non trouvé avec l\'ID: ' . $data['author']], Response::HTTP_NOT_FOUND);
                    }
                    $book->setAuthor($author);
                } else {
                    // L'auteur est passé comme objet complet
                    $author = $serializer->deserialize(json_encode($data['author']), \App\Entity\Author::class, 'json');
                    $book->setAuthor($author);
                }
            }
            
            // Validation des données
            $errors = $validator->validate($book);
            if (count($errors) > 0) {
                $errorMessages = [];
                foreach ($errors as $error) {
                    $errorMessages[] = $error->getMessage();
                }
                return new JsonResponse(['errors' => $errorMessages], Response::HTTP_BAD_REQUEST);
            }
            
            $em->persist($book);
            $em->flush();
            
            // Invalider le cache des livres
            $cache->invalidateTags(['booksCache']);
            
            $jsonBook = $serializer->serialize($book, 'json', ['groups' => 'getBooks']);
            $location = $urlGenerator->generate('detailBook', ['id' => $book->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
            
            return new JsonResponse($jsonBook, Response::HTTP_CREATED, ["Location" => $location], true);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Erreur lors de la création du livre: ' . $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Met à jour un livre existant
     * 
     * Route: PUT /api/books/{id}
     * 
     * Cette méthode utilise AbstractNormalizer::OBJECT_TO_POPULATE pour
     * mettre à jour l'objet existant au lieu d'en créer un nouveau.
     * 
     * @param Book $currentBook Livre à modifier (injecté automatiquement par Symfony)
     * @param Request $request Requête HTTP avec les nouvelles données
     * @param SerializerInterface $serializer Service de sérialisation
     * @param EntityManagerInterface $em Gestionnaire d'entités
     * @param AuthorRepository $authorRepository Repository des auteurs
     * @return JsonResponse Réponse 204 si succès
     */
    #[Route('/api/books/{id}', name: 'updateBook', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les permissions pour modifier un livre')]
    public function updateBook(Book $currentBook, Request $request, SerializerInterface $serializer, EntityManagerInterface $em, AuthorRepository $authorRepository, TagAwareCacheInterface $cache): JsonResponse {
        $updatedBook = $serializer->deserialize($request->getContent(), Book::class, 'json', [AbstractNormalizer::OBJECT_TO_POPULATE => $currentBook]);
        $content = $request->toArray();
        $idAuthor = $content['idAuthor'] ?? -1;
        $updatedBook->setAuthor($authorRepository->find($idAuthor));
        $em->persist($updatedBook);
        $em->flush();
        
        // Invalider le cache des livres
        $cache->invalidateTags(['booksCache']);
        
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Vide le cache des livres
     * 
     * Route: POST /api/books/clear-cache
     * 
     * @param TagAwareCacheInterface $cache Service de cache
     * @return JsonResponse Confirmation de suppression du cache
     */
    #[Route('/api/books/clear-cache', name: 'clear_books_cache', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les permissions pour vider le cache')]
    public function clearBooksCache(TagAwareCacheInterface $cache): JsonResponse
    {
        $cache->invalidateTags(['booksCache']);
        
        return new JsonResponse(['message' => 'Cache des livres vidé avec succès'], Response::HTTP_OK);
    }
}