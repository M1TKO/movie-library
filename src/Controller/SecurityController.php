<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\{Owner, OwnerProvider};
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/setup', name: 'setup', methods: ['GET', 'POST'])]
    public function setup(Request $request, OwnerProvider $owners, Connection $db, UserPasswordHasherInterface $hasher): Response
    {
        if (!in_array($request->getClientIp(), ['127.0.0.1', '::1'], true)) {
            return new Response('Owner setup is available only on the host computer.', 403);
        }
        if ($owners->exists()) {
            return $this->redirectToRoute('login');
        }
        $error = null;
        if ($request->isMethod('POST')) {
            $password = $request->request->getString('password');
            if (!$this->isCsrfTokenValid('setup', $request->request->getString('_token'))) {
                $error = 'The form expired. Please try again.';
            } elseif (strlen($password) < 12 || strlen($password) > 128) {
                $error = 'Choose a password between 12 and 128 bytes long.';
            } elseif ($password !== $request->request->getString('confirm')) {
                $error = 'The passwords do not match.';
            } else {
                try {
                    $db->insert('owner_auth', ['id' => 1, 'password_hash' => $hasher->hashPassword(new Owner(''), $password), 'created_at' => gmdate(DATE_ATOM)]);
                } catch (UniqueConstraintViolationException) {
                    return $this->redirectToRoute('login');
                }
                $request->getSession()->migrate(true);
                $this->addFlash('success', 'Owner created. Sign in with your new password.');
                return $this->redirectToRoute('login');
            }
        }
        return $this->render('security/setup.html.twig', ['error' => $error]);
    }

    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(OwnerProvider $owners, AuthenticationUtils $auth): Response
    {
        if (!$owners->exists()) {
            return $this->redirectToRoute('setup');
        }
        if ($this->getUser()) {
            return $this->redirectToRoute('library');
        }
        return $this->render('security/login.html.twig', ['error' => $auth->getLastAuthenticationError()]);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by Symfony Security.');
    }
}
