<?php

namespace App\Controller;

use App\Entity\User;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class UserController extends AbstractController
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    #[Route('/connect/google', name: 'connect_google')]
    public function connectGoogle(ClientRegistry $clientRegistry): RedirectResponse
    {
        return $clientRegistry->getClient('google')->redirect(
            ['email', 'profile'], // profile scope for name
            []
        );
    }

    #[Route('/connect/google/check', name: 'connect_google_check')]
    public function connectCheck(): RedirectResponse
    {
        $redirectUrl = $_ENV['FRONTEND_URL'];
        return $this->redirect($redirectUrl);
    }

    #[Route('/api/users/me', name: 'api_user_me_get', methods: ['GET'])]
    public function getCurrentUser(): JsonResponse
    {
        $user = $this->getUser();

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'timezone' => $user->getTimezone(),
        ]);
    }

    #[Route('/api/users/me', name: 'api_user_me_put', methods: ['PUT'])]
    public function updateCurrentUser(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) {
            if (!is_string($data['name']) || empty(trim($data['name']))) {
                return $this->json(['error' => 'Invalid name provided'], 400);
            }
            $user->setName(trim($data['name']));
        }

        if (isset($data['email'])) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return $this->json(['error' => 'Invalid email format'], 400);
            }
            $user->setEmail($data['email']);
        }

        if (isset($data['timezone'])) {
            if (!is_string($data['timezone']) || !in_array($data['timezone'], \DateTimeZone::listIdentifiers())) {
                return $this->json(['error' => 'Invalid timezone identifier'], 400);
            }
            $user->setTimezone($data['timezone']);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $this->json([
            'message' => 'User updated successfully',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'timezone' => $user->getTimezone(),
            ],
        ]);
    }

}
