<?php

namespace App\Traits;

use App\Entity\Team;

trait TeamScoreTrait
{
    private function updateTeamScore(array &$scoresByTeam, Team $team, int $points): void
    {
        $teamId = $team->getId();
        if (!isset($scoresByTeam[$teamId])) {
            $scoresByTeam[$teamId] = [
                'id' => $teamId,
                'name' => $team->getName(),
                'points' => 0
            ];
        }
        $scoresByTeam[$teamId]['points'] += $points;
    }

    private function calculateTeamPoints(array $matches): array
    {
        $scoresByTeam = [];

        foreach ($matches as $meeting) {
            if ($meeting->getState() === 'PLAYED') {
                $blueTeam = $meeting->getBlueTeam();
                $greenTeam = $meeting->getGreenTeam();
                $blueScore = $meeting->getBlueScore();
                $greenScore = $meeting->getGreenScore();

                // Attribution des points
                if ($blueScore > $greenScore) {
                    $this->updateTeamScore($scoresByTeam, $blueTeam, 3);
                    $this->updateTeamScore($scoresByTeam, $greenTeam, 0);
                } elseif ($blueScore < $greenScore) {
                    $this->updateTeamScore($scoresByTeam, $blueTeam, 0);
                    $this->updateTeamScore($scoresByTeam, $greenTeam, 3);
                } else {
                    $this->updateTeamScore($scoresByTeam, $blueTeam, 1);
                    $this->updateTeamScore($scoresByTeam, $greenTeam, 1);
                }
            }
        }

        return $scoresByTeam;
    }
}
