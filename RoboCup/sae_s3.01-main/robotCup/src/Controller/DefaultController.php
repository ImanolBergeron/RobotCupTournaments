<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Repository\CompetitionRepository;

class DefaultController extends AbstractController
{
    #[Route('/')]
    public function indexNoLocale(): Response
    {
        return $this->redirectToRoute('app_home', ['_locale' => 'fr']);
    }

    #[Route('/{_locale}', name: 'app_home')]
    public function index(CompetitionRepository $competitionRepository): Response
    {
        // Récupère la dernière, la compétition en cours et la suivante
        $lastCompetition = $competitionRepository->findLastCompletedCompetition();
        $currentCompetition = $competitionRepository->findCurrentCompetition();
        $nextCompetition = $competitionRepository->findNextCompetition();

        return $this->render('default/index.html.twig', [
            'competitions' => [
                'last' => $lastCompetition,
                'current' => $currentCompetition,
                'next' => $nextCompetition,
            ],
        ]);
    }
}
