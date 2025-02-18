<?php

namespace App\Controller;

use App\Entity\Team;
use App\Entity\Member;
use App\Entity\Competition;
use App\Entity\Meeting;
use App\Form\TeamFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class TeamController extends AbstractController
{
    #[Route('/team', name: 'app_team')]
    public function index(): Response
    {
        return $this->render('team/index.html.twig', [
            'controller_name' => 'TeamController',
        ]);
    }

    #[Route('/{_locale}/seeTeam', name: 'app_seeTeam')]
    public function seeTeam(EntityManagerInterface $entityManager): Response
    {
        // Récupérer l'utilisateur connecté
         $user = $this->getUser();

        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_ORGA')) {
            return $this->redirectToRoute('app_home');
        }

        // Récupérer les equipe appartenant à l'utilisateur
        $teams = $entityManager->getRepository(Team::class)->findBy(['owner' => $user]);


        // Si l'utilisateur n'a pas d'équipe, le rediriger vers la page de création d'équipe
        if (!$teams) {
            return $this->redirectToRoute('app_team');
        }

        // Rendre la vue avec les données
        return $this->render('default/seeTeam.html.twig', [
            'teams' => $teams,
        ]);
    }

    #[Route('/{_locale}/team', name: 'app_team')]
    public function create(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();

        // Vérifier s'il y a des compétitions disponibles
        $competitions = $entityManager->getRepository(Competition::class)->findAll();
        if (empty($competitions)) {
            $this->addFlash('error', 'Aucune compétition n\'est disponible. Impossible de créer une équipe.');
            return $this->redirectToRoute('app_home');
        }

        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_ORGA')) {
            return $this->redirectToRoute('app_home');
        }

        $team = new Team();
        $team->setOwner($user);

        $form = $this->createForm(TeamFormType::class, $team);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // Les membres de l'équipe sont optionnels maintenant
                if (isset($request->request->all()['members']) && !empty($request->request->all()['members'])) {
                    $membersData = $request->request->all()['members'];
                    foreach ($membersData as $memberData) {
                        $member = new Member();
                        $member->setName($memberData['name']);
                        $member->setSurname($memberData['surname']);
                        $member->setEmail($memberData['email']);
                        $team->addMember($member);
                    }
                }

                $team->setState("WAITING");

                // Associer la compétition sélectionnée à l'équipe
                $competition = $form->get('competitions')->getData();
                if (!$competition) {
                    throw new \Exception('Veuillez sélectionner une compétition.');
                }
                $team->setCompetition($competition);

                // Persister l'équipe
                $entityManager->persist($team);
                $entityManager->flush();

                $this->addFlash('success', 'Équipe créée et inscrite avec succès !');
                return $this->redirectToRoute('app_home');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur lors de la création de l\'équipe: ' . $e->getMessage());
            }
        }

        return $this->render('default/team.html.twig', [
            'teamForm' => $form->createView()
        ]);
    }

    #[Route('/{_locale}/team/{id}', name: 'app_team_matchs')]
    public function teamMatchs(int $id, EntityManagerInterface $entityManager): Response
    {
        $team = $entityManager->getRepository(Team::class)->find($id);

        if (!$team) {
            throw $this->createNotFoundException('Team not found');
        }

        $blueTeamMeetings = $entityManager->getRepository(Meeting::class)->findBy([
            'blueTeam' => $team,
            'state' => 'PLAYED'
        ]);
        $greenTeamMeetings = $entityManager->getRepository(Meeting::class)->findBy([
            'greenTeam' => $team,
            'state' => 'PLAYED'
        ]);

        $meetings = array_merge($blueTeamMeetings, $greenTeamMeetings);

        return $this->render('match/teamMatchs.html.twig', [
            'meetings' => $meetings,
        ]);
    }
}
