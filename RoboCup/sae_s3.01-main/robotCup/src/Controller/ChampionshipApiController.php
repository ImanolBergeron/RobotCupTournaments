<?php

namespace App\Controller;

use App\Entity\ChampionShip;
use App\Entity\Meeting;
use App\Entity\Team;
use App\Entity\TimeSlot;
use App\Entity\Stage;
use App\Entity\Competition;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class ChampionshipApiController extends AbstractController
{
    #[Route('/api/championship/{id}/teams', name: 'api_championship_teams', methods: ['GET'])]
    public function getTeams(ChampionShip $championship, EntityManagerInterface $entityManager): JsonResponse
    {
        $teams = $entityManager->getRepository(Team::class)
            ->createQueryBuilder('t')
            ->join('t.competition', 'c')
            ->where('c.championShip = :championship')
            ->setParameter('championship', $championship)
            ->getQuery()
            ->getResult();

        $teamData = [
            'pending' => [],
            'active' => [],
            'removed' => []
        ];

        foreach ($teams as $team) {
            $teamInfo = [
                'id' => $team->getId(),
                'name' => $team->getName(),
                'structure' => $team->getStructure(),
                'creationDate' => $team->getCreationDate()->format('Y-m-d'),
                'ownerName' => $team->getOwner()->getFirstName() . ' ' . $team->getOwner()->getLastName(),
                'membersCount' => $team->getMembers()->count()
            ];

            // Utiliser l'état persisté de l'équipe
            switch ($team->getState()) {
                case 'WAITING':
                    $teamData['pending'][] = $teamInfo;
                    break;
                case 'ACCEPTED':
                    $teamData['active'][] = $teamInfo;
                    break;
                case 'REFUSED':
                    $teamData['removed'][] = $teamInfo;
                    break;
            }
        }

        return new JsonResponse($teamData);
    }

    #[Route('/api/championship/{championshipId}/teams/{teamId}/accept', name: 'api_accept_team', methods: ['POST'])]
    public function acceptTeam(
        int $championshipId,
        int $teamId,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        try {
            $team = $entityManager->getRepository(Team::class)->find($teamId);
            $championship = $entityManager->getRepository(ChampionShip::class)->find($championshipId);

            if (!$team || !$championship) {
                throw new \Exception('Équipe ou championnat non trouvé');
            }

            // Mettre à jour l'état de l'équipe et persister le changement
            $team->setState('ACCEPTED');
            $entityManager->persist($team);
            $entityManager->flush();

            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
    #[Route('/api/championship/{championshipId}/teams/{teamId}/remove', name: 'api_remove_team', methods: ['POST'])]
    public function removeTeam(
        int $championshipId,
        int $teamId,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger
    ): JsonResponse {
        try {
            $team = $entityManager->getRepository(Team::class)->find($teamId);
            $championship = $entityManager->getRepository(ChampionShip::class)->find($championshipId);

            if (!$team || !$championship) {
                throw new \Exception('Équipe ou championnat non trouvé');
            }

            // 1. Supprimer tous les matchs où l'équipe participe
            $matches = $entityManager->getRepository(Meeting::class)
                ->createQueryBuilder('m')
                ->where('m.championShip = :championship')
                ->andWhere('m.blueTeam = :team OR m.greenTeam = :team')
                ->setParameter('championship', $championship)
                ->setParameter('team', $team)
                ->getQuery()
                ->getResult();

            foreach ($matches as $match) {
                $entityManager->remove($match);
                $logger->info('Match supprimé : ' . $match->getId());
            }

            // 2. Mettre à jour l'état de l'équipe
            $team->setState('REFUSED');
            $entityManager->persist($team);

            // 3. Sauvegarder les changements
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => sprintf('Équipe retirée et %d matchs supprimés.', count($matches))
            ]);
        } catch (\Exception $e) {
            $logger->error('Erreur lors de la suppression de l\'équipe : ' . $e->getMessage());
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/championship/{id}/refresh-matches', name: 'api_refresh_matches', methods: ['POST'])]
    public function refreshMatches(ChampionShip $championship, EntityManagerInterface $entityManager, LoggerInterface $logger): JsonResponse
    {
        try {
            // Trouver la compétition associée au championnat
            $competition = $entityManager->getRepository(Competition::class)
                ->findOneBy(['championShip' => $championship]);

            if (!$competition) {
                throw new \Exception('Compétition non trouvée pour ce championnat');
            }

            $stage = $entityManager->getRepository(Stage::class)->findOneBy([])
                ?? $this->createDefaultStage($entityManager);

            $entityManager->beginTransaction();

            try {
                // Get available time slots
                $availableTimeSlots = $entityManager->getRepository(TimeSlot::class)
                    ->findAvailableTimeSlotsForChampionship($championship);

                if (empty($availableTimeSlots)) {
                    throw new \Exception('Aucun créneau horaire disponible pour cette période de compétition');
                }

                // Delete existing matches
                $meetings = $entityManager->getRepository(Meeting::class)
                    ->findBy(['championShip' => $championship]);

                foreach ($meetings as $meeting) {
                    $entityManager->remove($meeting);
                }
                $entityManager->flush();

                // Get teams for THIS specific competition with ACCEPTED state
                $teams = $entityManager->getRepository(Team::class)
                    ->createQueryBuilder('t')
                    ->where('t.competition = :competition')
                    ->andWhere('t.state = :state')
                    ->setParameter('competition', $competition)
                    ->setParameter('state', 'ACCEPTED')
                    ->getQuery()
                    ->getResult();

                // ...existing code for creating matches...
                $usedTimeSlots = [];
                $matchCount = 0;
                $unmatchedPairs = [];

                for ($i = 0; $i < count($teams); $i++) {
                    for ($j = $i + 1; $j < count($teams); $j++) {
                        $timeSlot = $this->findAvailableTimeSlot(
                            $availableTimeSlots,
                            $usedTimeSlots
                        );

                        if (!$timeSlot) {
                            $unmatchedPairs[] = [$teams[$i]->getName(), $teams[$j]->getName()];
                            continue;
                        }

                        $usedTimeSlots[] = $timeSlot;

                        $meeting = new Meeting();
                        $meeting->setChampionShip($championship)
                               ->setBlueTeam($teams[$i])
                               ->setGreenTeam($teams[$j])
                               ->setStage($stage)
                               ->setState('TO_PLAY')
                               ->setTimeSlot($timeSlot);

                        $entityManager->persist($meeting);
                        $matchCount++;
                    }
                }

                $entityManager->flush();
                $entityManager->commit();

                $message = sprintf('%d nouveaux matches créés', $matchCount);
                if (!empty($unmatchedPairs)) {
                    $message .= sprintf('. %d paires d\'équipes n\'ont pas pu être programmées par manque de créneaux', count($unmatchedPairs));
                }

                return new JsonResponse([
                    'success' => true,
                    'message' => $message,
                    'unmatchedPairs' => $unmatchedPairs
                ]);
            } catch (\Exception $e) {
                $entityManager->rollback();
                throw $e;
            }
        } catch (\Exception $e) {
            $logger->error('Erreur lors du rafraîchissement des matchs : ' . $e->getMessage());
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function createDefaultStage(EntityManagerInterface $entityManager): Stage
    {
        $stage = new Stage();
        $stage->setName('Default Stage');
        $entityManager->persist($stage);
        $entityManager->flush();
        return $stage;
    }

    private function findAvailableTimeSlot(array $availableTimeSlots, array $usedSlots): ?TimeSlot
    {
        foreach ($availableTimeSlots as $timeSlot) {
            if (!in_array($timeSlot, $usedSlots)) {
                return $timeSlot;
            }
        }
        return null;
    }
    #[Route('/api/championship/{id}/finish', name: 'api_championship_finish', methods: ['POST'])]
    public function finishChampionship(
        ChampionShip $championship,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        SessionInterface $session
    ): JsonResponse {
        try {
            // Vérifier si tous les matchs sont joués
            $unfinishedMatches = $entityManager->getRepository(Meeting::class)
                ->count([
                    'championShip' => $championship,
                    'state' => 'TO_PLAY'
                ]);

            if ($unfinishedMatches > 0) {
                throw new \Exception('Tous les matchs du championnat doivent être terminés avant de le clôturer');
            }

            // Mettre à jour la date de fin du championnat
            $championship->setEnd(new \DateTime());
            $entityManager->persist($championship);
            $entityManager->flush();

            // Nettoyer la session du tournoi
            $session->remove('tournament_bracket_' . $championship->getId());

            return new JsonResponse([
                'success' => true,
                'message' => 'Le championnat a été clôturé avec succès.'
            ]);
        } catch (\Exception $e) {
            $logger->error('Erreur lors de la clôture du championnat : ' . $e->getMessage());
            return new JsonResponse([
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
