<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Form\EditUserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Knp\Component\Pager\PaginatorInterface;

#[Route('/{_locale}/user')]
#[Security('is_granted("ROLE_ADMIN") or is_granted("ROLE_ORGA")')]
class UserController extends AbstractController
{
    #[Route('/', name: 'app_user_index', methods: ['GET'])]
    public function index(
        Request $request,
        UserRepository $userRepository,
        PaginatorInterface $paginator
    ): Response {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_ORGA')) {
            throw $this->createAccessDeniedException('Accès non autorisé.');
        }

        $search = $request->query->get('q');
        $queryBuilder = $userRepository->createQueryBuilder('u');

        // Si c'est un organisateur, on cache les admins
        if (!$this->isGranted('ROLE_ADMIN')) {
            $queryBuilder
                ->where('u.roles NOT LIKE :role')
                ->setParameter('role', '%ROLE_ADMIN%');
        }

        // Ajout de la recherche si elle existe
        if ($search) {
            $queryBuilder
                ->andWhere('u.email LIKE :search OR u.firstName LIKE :search OR u.lastName LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $users = $paginator->paginate(
            $queryBuilder->getQuery(),
            $request->query->getInt('page', 1),
            7
        );

        return $this->render('user/index.html.twig', [
            'users' => $users,
            'search' => $search
        ]);
    }

    #[Route('/new', name: 'app_user_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]  // Seuls les admins peuvent créer de nouveaux utilisateurs
    public function new(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): Response
    {
        $user = new User();
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $hashedPassword = $passwordHasher->hashPassword(
                $user,
                'ChangeMe123!'
            );
            $user->setPassword($hashedPassword);
            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash('success', 'Utilisateur créé avec succès.');
            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('user/new.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/impersonate', name: 'app_user_impersonate', methods: ['GET'])]
    public function impersonate(User $user): Response
    {
        // Un organisateur ne peut pas incarner un admin
        if (!$this->isGranted('ROLE_ADMIN') && in_array('ROLE_ADMIN', $user->getRoles())) {
            $this->addFlash('error', 'Vous n\'avez pas les droits nécessaires pour cette action.');
            return $this->redirectToRoute('app_user_index');
        }

        return $this->redirect('/?_switch_user=' . $user->getEmail());
    }

    #[Route('/{id}/edit', name: 'app_user_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && in_array('ROLE_ADMIN', $user->getRoles())) {
            $this->addFlash('error', 'Vous n\'avez pas les droits nécessaires pour modifier cet utilisateur.');
            return $this->redirectToRoute('app_user_index');
        }

        if ($this->getUser() == null || ($this->isGranted('ROLE_USER') && !$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_ORGA'))) {
            $this->addFlash('error', 'Vous n\'avez pas les droits nécessaires pour modifier cet utilisateur.');
            return $this->redirectToRoute('app_home');
        }

        $is_admin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_ORGA');

        $form = $this->createForm(EditUserType::class, $user, [
            'is_admin' => $is_admin
            ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->isGranted('ROLE_ADMIN')) {
                $password = $form->get('password')->getData();
                if ($password === null) {
                    $hashedPassword = $user->getPassword();
                } else {
                    $hashedPassword = $passwordHasher->hashPassword(
                        $user,
                        $password
                    );
                }
                $user->setPassword($hashedPassword);
            }
            $entityManager->persist($user);
            $entityManager->flush();
            $this->addFlash('success', 'Utilisateur modifié avec succès.');
            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_user_delete', methods: ['POST'])]
    public function delete(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        // Un organisateur ne peut pas supprimer un admin
        if (!$this->isGranted('ROLE_ADMIN') && in_array('ROLE_ADMIN', $user->getRoles())) {
            $this->addFlash('error', 'Vous n\'avez pas les droits nécessaires pour cette action.');
            return $this->redirectToRoute('app_user_index');
        }

        if ($this->isCsrfTokenValid('delete' . $user->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($user);
            $entityManager->flush();
            $this->addFlash('success', 'Utilisateur supprimé avec succès.');
        }

        return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
    }
    #[route('/profile', name: 'app_user_profile', methods: ['GET'])]
    public function profile(): Response
    {
        # revoie vers le profile et un formulaire pour modifier le mot de passe
        return $this->render(
            'user/profile.html.twig',
            [
            'user' => $this->getUser()
            ]
        );
    }
    #[route('/pwd', name: 'app_change_password', methods: ['GET'])]
    public function pwd(): Response
    {
        # revoie vers le profile et un formulaire pour modifier le mot de passe
        return $this->render(
            'user/edit_PWD.html.twig',
            [
            'user' => $this->getUser()
            ]
        );
    }
}
