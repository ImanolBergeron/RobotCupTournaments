<?php

namespace App\Controller;

use App\Entity\ChampionShip;
use App\Entity\Meeting;
use App\Entity\Team;
use App\Form\ScheduleType;
use App\Entity\Competition;
use App\Form\ChampionshipType;
use App\Entity\TimeSlot;
use App\Repository\ChampionShipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Knp\Component\Pager\PaginatorInterface;
use App\Entity\Stage;
use Psr\Log\LoggerInterface;

#[Route('/{_locale}/championship', requirements: ['_locale' => 'en|fr'])]
final class ChampionShipController extends AbstractController
{
    #[Route('/', name: 'app_championship', methods: ['GET'])]
    public function index(
        Request $request,
        ChampionShipRepository $championShipRepository,
        EntityManagerInterface $entityManager,
        PaginatorInterface $paginator
    ): Response {
        $allChampionships = $championShipRepository->findAll();

        $selectedId = $request->query->get('championship');
        $championship = null;

        if ($selectedId) {
            $championship = $championShipRepository->find($selectedId);
        } elseif (!empty($allChampionships)) {
            $championship = $allChampionships[0];
        }

        $meetingsQuery = null;
        if ($championship) {
            $meetingsQuery = $entityManager->getRepository(Meeting::class)
                ->createQueryBuilder('m')
                ->leftJoin('m.timeSlot', 't')
                ->where('m.championShip = :championship')
                ->setParameter('championship', $championship)
                ->orderBy('t.start', 'ASC')
                ->getQuery();

            $meetings = $paginator->paginate(
                $meetingsQuery,
                $request->query->getInt('page', 1),
                9
            );
        } else {
            $meetings = [];
        }

        return $this->render('champion_ship/index.html.twig', [
            'champion_ships' => $allChampionships,
            'championship' => $championship,
            'meetings' => $meetings ?? []  // Assurez-vous que meetings est toujours défini
        ]);
    }

    #[Route('/new', name: 'app_championship_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_ORGA')) {
            throw $this->createAccessDeniedException('Accès non autorisé.');
        }

        $championShip = new ChampionShip();
        $championShip->setOrganizer($this->getUser());

        $form = $this->createForm(ChampionshipType::class, $championShip);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Récupérer tous les créneaux disponibles
            $availableTimeSlots = $entityManager->getRepository(TimeSlot::class)
                ->findAllAvailableTimeSlots();

            // Récupérer ou créer un Stage par défaut
            $stage = $entityManager->getRepository(Stage::class)->findOneBy([])
                ?? $this->createDefaultStage($entityManager);

            // Récupérer toutes les équipes
            $teams = $entityManager->getRepository(Team::class)->findAll();

            $meetingCount = 0;

            for ($i = 0; $i < count($teams); $i++) {
                for ($j = $i + 1; $j < count($teams); $j++) {
                    $meeting = new Meeting();
                    $meeting->setChampionShip($championShip);
                    $meeting->setBlueTeam($teams[$i]);
                    $meeting->setGreenTeam($teams[$j]);
                    $meeting->setState('TO_PLAY');

                    // Utiliser le prochain créneau disponible
                    $timeSlot = $this->getNextAvailableTimeSlot($entityManager, $stage, $availableTimeSlots, $meetingCount);
                    $meeting->setTimeSlot($timeSlot);

                    $meeting->setStage($stage);
                    $entityManager->persist($meeting);
                    $meetingCount++;
                }
            }

            $entityManager->persist($championShip);
            $entityManager->flush();

            $this->addFlash('success', 'Le championnat a été créé avec succès.');
            return $this->redirectToRoute('app_championship', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('champion_ship/new.html.twig', [
            'champion_ship' => $championShip,
            'form' => $form,
        ]);
    }

    // Nouvelle méthode pour créer un Stage par défaut
    private function createDefaultStage(EntityManagerInterface $entityManager): Stage
    {
        $stage = new Stage();
        $stage->setName('Phase de groupes');
        $entityManager->persist($stage);

        return $stage;
    }

    private function getNextAvailableTimeSlot(EntityManagerInterface $entityManager, Stage $stage, array $availableTimeSlots, int $index = 0): ?TimeSlot
    {
        if (empty($availableTimeSlots)) {
            // Créer un nouveau créneau si aucun n'est disponible
            $timeSlot = new TimeSlot();
            $timeSlot->setStart(new \DateTime('08:00:00'));
            $timeSlot->setEnd(new \DateTime('09:00:00'));
            $timeSlot->setName('Créneau ' . ($index + 1));
            $entityManager->persist($timeSlot);
            return $timeSlot;
        }

        // Utiliser le modulo pour faire une rotation sur les créneaux disponibles
        return $availableTimeSlots[$index % count($availableTimeSlots)];
    }

    #[Route('/import-championship', name: 'app_championship_import', methods: ['POST'])]
    public function importMeetings(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        try {
            $uploadedFile = $request->files->get('importFile');

            if (!$uploadedFile) {
                throw new \Exception('No file uploaded');
            }

            $jsonContent = file_get_contents($uploadedFile->getPathname());
            $data = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON format');
            }

            if (!isset($data['championshipId']) || !isset($data['meetings'])) {
                throw new \Exception('Invalid file structure');
            }

            // Récupérer le championnat
            $championShip = $entityManager->getRepository(ChampionShip::class)->find($data['championshipId']);
            if (!$championShip) {
                throw new \Exception('Championship not found');
            }

            // Supprimer les meetings existants du championnat
            $existingMeetings = $entityManager->getRepository(Meeting::class)->findBy(['championShip' => $championShip]);
            foreach ($existingMeetings as $meeting) {
                $entityManager->remove($meeting);
            }
            $entityManager->flush();

            // Créer les nouveaux meetings
            foreach ($data['meetings'] as $meetingData) {
                $meeting = new Meeting();

                // Récupérer et associer les équipes
                $blueTeam = $entityManager->getRepository(Team::class)->find($meetingData['blueTeam']['id']);
                $greenTeam = $entityManager->getRepository(Team::class)->find($meetingData['greenTeam']['id']);

                if (!$blueTeam || !$greenTeam) {
                    throw new \Exception('Team not found');
                }

                $meeting->setBlueTeam($blueTeam);
                $meeting->setGreenTeam($greenTeam);
                $meeting->setBlueScore($meetingData['blueScore']);
                $meeting->setGreenScore($meetingData['greenScore']);
                if ($meetingData['blueScore'] !== null || $meetingData['greenScore'] !== null) {
                    $meeting->setState('PLAYED');
                } else {
                    $meeting->setState('TO_PLAY');
                }
                // Associer le championnat
                $meeting->setChampionShip($championShip);

                // Récupérer et associer le timeSlot
                $timeSlot = $entityManager->getReference('App\Entity\TimeSlot', $meetingData['timeSlot']['id']);
                $meeting->setTimeSlot($timeSlot);

                // Associer le stage si présent
                if (isset($meetingData['stage']) && $meetingData['stage']) {
                    $stage = $entityManager->getReference('App\Entity\Stage', $meetingData['stage']['id']);
                    $meeting->setStage($stage);
                }

                $entityManager->persist($meeting);
            }

            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Meetings imported successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    #[Route('/{id}', name: 'app_championship_show', methods: ['GET'])]
    public function show(
        ChampionShip $championShip,
        EntityManagerInterface $entityManager,
        Request $request,
        PaginatorInterface $paginator
    ): Response {
        $meetingsQuery = $entityManager->getRepository(Meeting::class)
            ->createQueryBuilder('m')
            ->leftJoin('m.timeSlot', 't')
            ->where('m.championShip = :championShip')
            ->setParameter('championShip', $championShip)
            ->getQuery();

        $meetings = $paginator->paginate(
            $meetingsQuery,
            $request->query->getInt('page', 1),
            6,
            ['wrap-queries' => true]
        );

        return $this->render('champion_ship/show.html.twig', [
            'champion_ship' => $championShip,
            'meetings' => $meetings
        ]);
    }

    #[Route('/{id}/edit', name: 'app_championship_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ChampionShip $championShip, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_ORGA')) {
            throw $this->createAccessDeniedException('Accès non autorisé.');
        }

        $form = $this->createForm(ChampionshipType::class, $championShip);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            return $this->redirectToRoute('app_championship', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('champion_ship/edit.html.twig', [
            'champion_ship' => $championShip,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_championship_delete', methods: ['POST'])]
    public function delete(Request $request, ChampionShip $championShip, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_ORGA')) {
            throw $this->createAccessDeniedException('Accès non autorisé.');
        }

        if ($this->isCsrfTokenValid('delete' . $championShip->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($championShip);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_championship', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/meeting/{id}/score', name: 'app_meeting_score', methods: ['POST'])]
    public function updateScore(
        Request $request,
        Meeting $meeting,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_ORGA')) {
            throw $this->createAccessDeniedException('Accès non autorisé.');
        }

        $forfeit = $request->request->get('forfeit');
        $blueScore = $request->request->get('blueScore');
        $greenScore = $request->request->get('greenScore');

        if ($forfeit && in_array($forfeit, ['GAVE_UP_BLUE', 'GAVE_UP_GREEN'])) {
            $meeting->setState($forfeit);
            $meeting->setBlueScore(intval($blueScore));
            $meeting->setGreenScore(intval($greenScore));
        } else {
            if ($blueScore !== null && $greenScore !== null) {
                $meeting->setBlueScore(intval($blueScore));
                $meeting->setGreenScore(intval($greenScore));
                $meeting->setState('PLAYED');
            }
        }

        $entityManager->flush();
        $this->addFlash('success', 'Les scores ont été enregistrés avec succès.');

        return $this->redirectToRoute('app_championship_show', [
            'id' => $meeting->getChampionShip()->getId()
        ]);
    }

    #[Route('/{id}/export', name: 'app_championship_export', methods: ['POST'])]
    public function exportMeetings(ChampionShip $championShip, EntityManagerInterface $entityManager): Response
    {
        try {
            // Récupérer tous les meetings du championnat
            $meetings = $entityManager->getRepository(Meeting::class)->findBy(['championShip' => $championShip]);

            // Transformer les meetings en tableau pour le JSON
            $meetingsData = [];
            foreach ($meetings as $meeting) {
                $timeSlot = $meeting->getTimeSlot();
                $startHour = $timeSlot->getStart();
                $endHour = $timeSlot->getEnd();

                // Convertir les heures si nécessaire
                $formattedStartHour = $startHour instanceof \DateTime
                    ? $startHour->format('H:i:s')
                    : sprintf(
                        '%02d:%02d:%02d',
                        floor($startHour / 3600),
                        floor(($startHour % 3600) / 60),
                        $startHour % 60
                    );

                $formattedEndHour = $endHour instanceof \DateTime
                    ? $endHour->format('H:i:s')
                    : sprintf(
                        '%02d:%02d:%02d',
                        floor($endHour / 3600),
                        floor(($endHour % 3600) / 60),
                        $endHour % 60
                    );

                $meetingsData[] = [
                    'blueTeam' => [
                        'id' => $meeting->getBlueTeam()->getId(),
                        'name' => $meeting->getBlueTeam()->getName()
                    ],
                    'greenTeam' => [
                        'id' => $meeting->getGreenTeam()->getId(),
                        'name' => $meeting->getGreenTeam()->getName()
                    ],
                    'blueScore' => $meeting->getBlueScore(),
                    'greenScore' => $meeting->getGreenScore(),
                    'state' => $meeting->getState(),
                    'timeSlot' => [
                        'id' => $timeSlot->getId(),
                        'startHour' => $formattedStartHour,
                        'endHour' => $formattedEndHour
                    ],
                    'stage' => $meeting->getStage() ? [
                        'id' => $meeting->getStage()->getId()
                    ] : null
                ];
            }

            // Créer la réponse JSON
            $jsonContent = json_encode([
                'championshipId' => $championShip->getId(),
                'meetings' => $meetingsData
            ], JSON_PRETTY_PRINT);

            $response = new Response($jsonContent);
            $response->headers->set('Content-Type', 'application/json');
            $response->headers->set(
                'Content-Disposition',
                'attachment; filename="championship_' . $championShip->getId() . '_meetings.json"'
            );

            return $response;
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
