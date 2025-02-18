<?php

namespace App\Controller;

use App\Entity\Tournament;
use App\Entity\Meeting;
use App\Entity\Team;
use App\Entity\Stage;
use App\Entity\Competition; // Ajout de l'import manquant
use App\Repository\TeamRepository;
use App\Repository\ChampionShipRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use App\Traits\TournamentBracketTrait;
use App\Traits\TeamScoreTrait;

#[Route('/tournament')]
class TournamentController extends AbstractController
{
    use TournamentBracketTrait;
    use TeamScoreTrait;

    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager, \Twig\Environment $twig)
    {
        $this->entityManager = $entityManager;
        $twig->addFilter(new \Twig\TwigFilter('group_matches_by_stage', function ($matches) {
            $grouped = [];
            foreach ($matches as $match) {
                $stageName = $match->getStage()->getName();
                if (!isset($grouped[$stageName])) {
                    $grouped[$stageName] = [
                        'stage_name' => $stageName,
                        'matches' => []
                    ];
                }
                $grouped[$stageName]['matches'][] = $match;
            }
            return array_values($grouped);
        }));
    }

    #[Route('/{championshipId}', name: 'tournament_show')]
    public function show(int $championshipId, ChampionShipRepository $champRepo): Response
    {
        try {
            $championship = $champRepo->find($championshipId);
            if (!$championship) {
                throw $this->createNotFoundException('Championship not found');
            }

            // Vérifier si le championnat est terminé
            if ($championship->getEnd() === null) {
                throw $this->createAccessDeniedException('Le championnat n\'est pas encore terminé');
            }

            // Trouver le tournoi associé via la table Competition
            $competition = $this->entityManager->getRepository(Competition::class)
                ->findOneBy(['championShip' => $championship]);

            if (!$competition || !$competition->getTournament()) {
                throw $this->createNotFoundException('Tournament not found');
            }

            $tournament = $competition->getTournament();

            // Vérifier si le premier tour existe déjà
            $existingMatches = $this->entityManager->getRepository(Meeting::class)
                ->findBy(['tournament' => $tournament]);

            if (empty($existingMatches)) {
                // Créer les matchs du premier tour
                $this->createFirstRoundMatches($championship, $tournament);
            } else {
                // Vérifier et créer les matchs des tours suivants si nécessaire
                $this->createNextRoundMatches($tournament);
            }

            // Récupérer tous les matchs du tournoi pour affichage
            $matches = $this->getTournamentMatches($tournament);

            return $this->render('tournament/show.html.twig', [
                'championship' => $championship,
                'tournament' => $tournament,
                'matches' => $matches,
                'title' => 'Tournoi - ' . $championship->getName()
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_championship_show', ['id' => $championshipId]);
        }
    }

    #[Route('/match/{matchId}', name: 'tournament_update_match', methods: ['POST'])]
    public function updateMatch(
        int $matchId,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        try {
            $meeting = $entityManager->getRepository(Meeting::class)->find($matchId);

            if (!$meeting) {
                throw $this->createNotFoundException('Match not found');
            }

            $score1 = $request->request->getInt('score1');
            $score2 = $request->request->getInt('score2');

            // Vérification que les scores sont différents
            if ($score1 === $score2) {
                throw new \InvalidArgumentException('Les scores ne peuvent pas être égaux dans un tournoi');
            }

            if ($score1 !== null && $score2 !== null) {
                $meeting->setBlueScore($score1)
                       ->setGreenScore($score2)
                       ->setState('PLAYED');

                $entityManager->persist($meeting);
                $entityManager->flush();

                // Créer les matchs du prochain tour si nécessaire
                $this->createNextRoundMatches($meeting->getTournament());
            }

            // Récupérer le championshipId à partir du tournament via la competition
            $tournament = $meeting->getTournament();
            $competition = $this->entityManager->getRepository(Competition::class)
                ->findOneBy(['tournament' => $tournament]);

            return $this->redirectToRoute('tournament_show', [
                'championshipId' => $competition->getChampionship()->getId()
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('tournament_show', [
                'championshipId' => $competition->getChampionship()->getId()
            ]);
        }
    }

    private function getQualifiedTeams($championship): array
    {
        $meetings = $this->entityManager->getRepository(Meeting::class)
            ->createQueryBuilder('m')
            ->where('m.championShip = :championship')
            ->andWhere('m.state = :state')
            ->setParameter('championship', $championship)
            ->setParameter('state', 'PLAYED')
            ->getQuery()
            ->getResult();

        $scoresByTeam = $this->calculateTeamPoints($meetings);
        $teams = array_values($scoresByTeam);

        usort($teams, function ($a, $b) {
            return $b['points'] !== $a['points']
                ? $b['points'] - $a['points']
                : strcmp($a['name'], $b['name']);
        });

        return array_slice($teams, 0, 32);
    }

    private function createFirstRoundMatches(\App\Entity\ChampionShip $championship, Tournament $tournament): void
    {
        $teams = $this->getQualifiedTeams($championship);
        $stage = $this->getOrCreateStage('Premier tour');

        // Si le nombre d'équipes est impair, la meilleure équipe passe directement au tour suivant
        if (count($teams) % 2 !== 0) {
            $nextStage = $this->getOrCreateStage($this->getNextStageName('Premier tour'));
            // Créer un "bye" match pour la meilleure équipe
            $byeMatch = new Meeting();
            $byeMatch->setTournament($tournament)
                    ->setBlueTeam($this->entityManager->getReference(Team::class, $teams[0]['id']))
                    ->setGreenTeam(null)
                    ->setStage($nextStage)
                    ->setState('PLAYED')
                    ->setBlueScore(1)
                    ->setGreenScore(0);

            $this->entityManager->persist($byeMatch);

            // Retirer la première équipe de la liste pour les autres matchs
            array_shift($teams);
        }

        // Créer les matchs pour les équipes restantes
        for ($i = 0; $i < count($teams); $i += 2) {
            if ($i + 1 < count($teams)) {
                $meeting = new Meeting();
                $meeting->setTournament($tournament)
                       ->setBlueTeam($this->entityManager->getReference(Team::class, $teams[$i]['id']))
                       ->setGreenTeam($this->entityManager->getReference(Team::class, $teams[$i + 1]['id']))
                       ->setStage($stage)
                       ->setState('TO_PLAY');

                $this->entityManager->persist($meeting);
            }
        }
        $this->entityManager->flush();
    }

    private function createNextRoundMatches(Tournament $tournament): void
    {
        // Get current stage
        $currentStage = $this->entityManager->getRepository(Stage::class)
            ->findOneBy([], ['id' => 'DESC']);

        if (!$currentStage) {
            return;
        }

        // Vérifier si tous les matchs du tour actuel sont terminés
        $unfinishedMatches = $this->entityManager->getRepository(Meeting::class)
            ->count([
                'tournament' => $tournament,
                'stage' => $currentStage,
                'state' => 'TO_PLAY'
            ]);

        if ($unfinishedMatches === 0) {
            // Récupérer les gagnants et perdants du tour actuel
            $winners = $this->getWinnersFromStage($tournament, $currentStage);
            $losers = $this->getLosersFromStage($tournament, $currentStage);

            // Si on est en demi-finale (il y a 2 gagnants), créer la petite finale et la finale
            if (count($winners) === 2 && $currentStage->getName() === 'Demi-finales') {
                // Créer la finale
                $finalStage = $this->getOrCreateStage('Finale');
                $finalMatch = new Meeting();
                $finalMatch->setTournament($tournament)
                          ->setBlueTeam($winners[0])
                          ->setGreenTeam($winners[1])
                          ->setStage($finalStage)
                          ->setState('TO_PLAY');

                // Créer la petite finale
                $thirdPlaceStage = $this->getOrCreateStage('Petite finale');
                $thirdPlaceMatch = new Meeting();
                $thirdPlaceMatch->setTournament($tournament)
                               ->setBlueTeam($losers[0])
                               ->setGreenTeam($losers[1])
                               ->setStage($thirdPlaceStage)
                               ->setState('TO_PLAY');

                $this->entityManager->persist($finalMatch);
                $this->entityManager->persist($thirdPlaceMatch);
            } elseif (count($winners) > 1) {
                // Création normale des matchs pour les autres tours
                $nextStage = $this->getOrCreateStage($this->getNextStageName($currentStage->getName()));
                for ($i = 0; $i < count($winners); $i += 2) {
                    if ($i + 1 < count($winners)) {
                        $meeting = new Meeting();
                        $meeting->setTournament($tournament)
                               ->setBlueTeam($winners[$i])
                               ->setGreenTeam($winners[$i + 1])
                               ->setStage($nextStage)
                               ->setState('TO_PLAY');

                        $this->entityManager->persist($meeting);
                    }
                }
            }
            $this->entityManager->flush();
        }
    }

    private function getWinnersFromStage(Tournament $tournament, Stage $stage): array
    {
        $matches = $this->entityManager->getRepository(Meeting::class)
            ->findBy([
                'tournament' => $tournament,
                'stage' => $stage,
                'state' => 'PLAYED'
            ]);

        $winners = [];
        foreach ($matches as $match) {
            $winners[] = $match->getBlueScore() > $match->getGreenScore()
                ? $match->getBlueTeam()
                : $match->getGreenTeam();
        }

        return $winners;
    }

    private function getLosersFromStage(Tournament $tournament, Stage $stage): array
    {
        $matches = $this->entityManager->getRepository(Meeting::class)
            ->findBy([
                'tournament' => $tournament,
                'stage' => $stage,
                'state' => 'PLAYED'
            ]);

        $losers = [];
        foreach ($matches as $match) {
            // Si une équipe est null (bye match), on ne l'ajoute pas aux perdants
            if ($match->getGreenTeam() === null) {
                continue;
            }
            $losers[] = $match->getBlueScore() < $match->getGreenScore()
                ? $match->getBlueTeam()
                : $match->getGreenTeam();
        }

        return $losers;
    }

    private function getNextStageName(string $currentStageName): string
    {
        // Premier, compter le nombre de matchs actifs au tour actuel
        $matches = $this->entityManager->getRepository(Meeting::class)
            ->findBy(['stage' => $currentStage = $this->entityManager->getRepository(Stage::class)->findOneBy(['name' => $currentStageName])]);

        $nbMatches = count($matches);

        // Déterminer le prochain tour en fonction du nombre de matchs
        if ($nbMatches >= 8) {
            return 'Huitièmes de finale';
        } elseif ($nbMatches >= 4) {
            return 'Quarts de finale';
        } elseif ($nbMatches >= 2) {
            return 'Demi-finales';
        } elseif ($nbMatches == 1) {
            return 'Finale';
        }

        // Fallback pour les cas spéciaux
        $stageOrder = [
            'Premier tour' => 'Huitièmes de finale',
            'Huitièmes de finale' => 'Quarts de finale',
            'Quarts de finale' => 'Demi-finales',
            'Demi-finales' => 'Finale',
            'Petite finale' => 'Finale'
        ];

        return $stageOrder[$currentStageName] ?? 'Finale';
    }

    private function getTournamentMatches(Tournament $tournament): array
    {
        return $this->entityManager->getRepository(Meeting::class)
            ->createQueryBuilder('m')
            ->where('m.tournament = :tournament')
            ->setParameter('tournament', $tournament)
            ->orderBy('m.stage', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function getOrCreateStage(string $name): Stage
    {
        $stage = $this->entityManager->getRepository(Stage::class)
            ->findOneBy(['name' => $name]);

        if (!$stage) {
            $stage = new Stage();
            $stage->setName($name);
            $this->entityManager->persist($stage);
            $this->entityManager->flush();
        }

        return $stage;
    }
}
