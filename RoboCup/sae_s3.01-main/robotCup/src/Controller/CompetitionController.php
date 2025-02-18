<?php

namespace App\Controller;

use App\Entity\Competition;
use App\Entity\ChampionShip;
use App\Entity\Tournament;
use App\Form\CompetitionType;
use App\Repository\CompetitionRepository;
use App\Entity\TimeSlot;
use App\Entity\Stage;
use App\Form\ScheduleType;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Form\StageType;

#[Route('/competition')]
final class CompetitionController extends AbstractController
{
    #[Route('/', name: 'app_competition', methods: ['GET'])]
    public function index(CompetitionRepository $competitionRepository): Response
    {
        return $this->render('competition/index.html.twig', [
            'competitions' => $competitionRepository->findAll(),
        ]);
    }

    private const SLOT_DURATION = 20;
    #[Route('/new', name: 'app_competition_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_ORGA')) {
            throw $this->createAccessDeniedException('Accès non autorisé.');
        }

        $competition = new Competition();
        $competition->setOrganizer($this->getUser());

        // Création du championnat associé
        $championship = new ChampionShip();
        $championship->setOrganizer($this->getUser());
        $championship->setName('Championnat temporaire');

        // Création du tournoi associé
        $tournament = new Tournament();
        $tournament->setName('Tournoi temporaire');
        $tournament->setLap(1);

        $competition->setChampionShip($championship);
        $competition->setTournament($tournament);

        $form = $this->createForm(CompetitionType::class, $competition);
        $form->handleRequest($request);
        // Créer un nouvel objet Stage
        $stage = new Stage();
        // Créer le formulaire Stage
        $form_stage = $this->createForm(StageType::class, $stage);

        // Gérer la soumission du formulaire
        $form_stage->handleRequest($request);
        try {
            if ($form_stage->isSubmitted() && $form_stage->isValid()) {
            // Sauvegarder l'objet dans la base de données
                $entityManager->persist($stage);
                $entityManager->flush();

            // Message de succès
                $this->addFlash('success', 'Stage créé avec succès.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Une erreur est survenue : ' . $e->getMessage());
        }

        try {
            if ($form->isSubmitted() && $form->isValid()) {
                // Mettre à jour les noms avec les vraies valeurs
                $championship->setName($competition->getName() . ' - Championnat');
                $tournament->setName($competition->getName() . ' - Tournoi');

                // Calculer la durée totale de la compétition en jours
                $totalDays = $competition->getStart()->diff($competition->getEnd())->days;

                // Réserver le dernier jour pour le tournoi
                $championshipEnd = clone $competition->getEnd();
                $championshipEnd->modify('-1 day');

                // Configurer les dates du championnat
                $championship->setStart($competition->getStart());
                $championship->setEnd($championshipEnd);

                // Configurer les dates du tournoi
                $tournament->setStart($championshipEnd);
                $tournament->setEnd($competition->getEnd());


                // Récupérer ou créer un Stage par défaut
                $stage = $entityManager->getRepository(Stage::class)->findOneBy([])
                    ?? $this->createDefaultStage($entityManager, $competition);

                $entityManager->persist($championship);
                $entityManager->persist($tournament);
                $entityManager->persist($competition);
                $entityManager->flush();

                $this->addFlash('success', 'La compétition a été créée avec succès.');
                return $this->redirectToRoute('app_competition_show', ['id' => $competition->getId()]);
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Une erreur est survenue : ' . $e->getMessage());
        }

        return $this->render('competition/new.html.twig', [
            'competition' => $competition,
            'form' => $form->createView(),
            'form_stage' => $form_stage->createView(),
        ]);
    }

    private function createDefaultStage(EntityManagerInterface $entityManager, Competition $competition): Stage
    {
        $stage = new Stage();
        $stage->setName('Phase de groupes');
        $entityManager->persist($stage);

        return $stage;
    }

    #[Route('/{id}', name: 'app_competition_show', methods: ['GET'])]
    public function show(Competition $competition): Response
    {
        $scheduleForm = $this->createForm(ScheduleType::class, [
            'date' => $competition->getStart()
        ]);

        return $this->render('competition/show.html.twig', [
            'competition' => $competition,
            'scheduleForm' => $scheduleForm->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_competition_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Competition $competition, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_ORGA')) {
            throw $this->createAccessDeniedException('Accès non autorisé.');
        }

        $form = $this->createForm(CompetitionType::class, $competition);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Mettre à jour le nom et les dates du championnat
            $championship = $competition->getChampionShip();
            $championship->setName($competition->getName() . ' - Championnat');
            $championship->setStart($competition->getStart());
            $championship->setEnd($competition->getEnd());
            $championship->setStart($competition->getStart());
            $championship->setEnd($competition->getEnd());

            // Mettre à jour les dates du tournoi
            $tournament = $competition->getTournament();
            $tournament->setStart($competition->getStart());
            $tournament->setEnd($competition->getEnd());

            $entityManager->flush();
            return $this->redirectToRoute('app_competition_show', ['id' => $competition->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('competition/edit.html.twig', [
            'competition' => $competition,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_competition_delete', methods: ['POST'])]
    public function delete(Request $request, Competition $competition, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_ORGA')) {
            throw $this->createAccessDeniedException('Accès non autorisé.');
        }

        if ($this->isCsrfTokenValid('delete' . $competition->getId(), $request->request->get('_token'))) {
            $entityManager->remove($competition);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_competition', [], Response::HTTP_SEE_OTHER);
    }
}
