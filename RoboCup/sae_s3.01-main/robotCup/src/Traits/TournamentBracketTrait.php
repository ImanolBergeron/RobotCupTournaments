<?php

namespace App\Traits;

trait TournamentBracketTrait
{
    private function generateBracket(array $teams): array
    {
        $totalTeams = count($teams);
        $rounds = ceil(log($totalTeams, 2));
        $bracket = [];
        $matchCount = $totalTeams;

        // Premier tour
        $round = $this->generateFirstRound($teams, $totalTeams);
        $bracket[] = $round;

        // Tours suivants
        for ($r = 1; $r < $rounds; $r++) {
            $previousRoundMatches = count($bracket[$r - 1]);
            $bracket[] = $this->generateNextRound($previousRoundMatches);
        }

        return $bracket;
    }

    private function generateFirstRound(array $teams, int $totalTeams): array
    {
        $round = [];
        for ($i = 0; $i < $totalTeams; $i += 2) {
            if ($i + 1 < $totalTeams) {
                $round[] = [
                    'team1' => $teams[$i],
                    'team2' => $teams[$i + 1],
                    'score1' => null,
                    'score2' => null,
                    'winner' => null
                ];
            } else {
                $round[] = [
                    'team1' => $teams[$i],
                    'team2' => ['id' => -1, 'name' => 'Bye', 'points' => 0],
                    'score1' => 1,
                    'score2' => 0,
                    'winner' => $teams[$i]['id']
                ];
            }
        }
        return $round;
    }

    private function generateNextRound(int $previousRoundMatches): array
    {
        $round = [];
        $matchesInRound = ceil($previousRoundMatches / 2);

        for ($m = 0; $m < $matchesInRound; $m++) {
            $round[] = [
                'team1' => ['id' => null, 'name' => 'TBD', 'points' => 0],
                'team2' => ['id' => null, 'name' => 'TBD', 'points' => 0],
                'score1' => null,
                'score2' => null,
                'winner' => null
            ];
        }
        return $round;
    }
}
