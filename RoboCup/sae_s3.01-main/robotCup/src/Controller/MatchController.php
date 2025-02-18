<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\CompetitionRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class MatchController extends AbstractController
{
    #[Route('/{_locale}/matches', name: 'app_matches')]
    public function index(EntityManagerInterface $entityManager): Response
    {

        $Competition = $_GET['id'];
        // Récupérer les données de la vue 'team_scores'
        $connection = $entityManager->getConnection();

        $sql = 'SELECT * FROM team_scores JOIN team ON team.id = team_scores.team_id 
                WHERE team.competition_id = :idComp 
                ORDER BY total_score DESC, goals_scored - goals_conceded DESC';
        $stmt = $connection->prepare($sql);
        $teamStats = $stmt->executeQuery(['idComp' => $Competition])->fetchAllAssociative();

        return $this->render('match/index.html.twig', [
            'teamStats' => $teamStats
        ]);
    }

    #[Route('/{_locale}/ChoixCompetition', name: 'app_ChoiceCompetition')]
    public function ChoixCmpetition(CompetitionRepository $competitionRepository): Response
    {
        return $this->render('match/selectionChampionnat.html.twig', [
            'competitions' => $competitionRepository->findAll(),
        ]);
    }

    #[Route('/{_locale}/choixPlanningOuTableau', name: 'app_ChoicePlanningOrTab')]
    public function ChoixPlanningOuTableau(): Response
    {
        $Compete = $_GET['id'];
        return $this->render('match/choixPlanningOuTableau.html.twig', [
            'competitions' => $Compete,
        ]);
    }



    #[Route('/{_locale}/Planning', name: 'app_Planning')]
    public function Planning(EntityManagerInterface $entityManager): Response
    {
        $Compete = $_GET['id'];

        $semaine = 0;
        if (isset($_GET['semaine'])) {
            $semaine = $_GET['semaine'];
        }

        $connection = $entityManager->getConnection();
        $sql = 'SELECT * FROM meeting WHERE tournament_id = :idComp OR champion_ship_id = :idComp';
        $stmt = $connection->prepare($sql);
        $meeting = $stmt->executeQuery(['idComp' => $Compete])->fetchAllAssociative();

        $date = date('Y-m-d');

        $sql = 'SELECT * FROM time_slot';
        $stmt = $connection->prepare($sql);
        $Creneau = $stmt->executeQuery()->fetchAllAssociative();

        $sql = 'SELECT * FROM team';
        $stmt = $connection->prepare($sql);
        $teams = $stmt->executeQuery()->fetchAllAssociative();

        /*dump($teams);
        dump($meeting);
        die;
        */
        return $this->render('match/Planning.html.twig', [
            'competition' => $Compete,
            'meetings' => $meeting,
            'date' => $date,
            'slots' => $Creneau,
            'semaine' => $semaine,
            'teams' => $teams,
        ]);
    }
}
