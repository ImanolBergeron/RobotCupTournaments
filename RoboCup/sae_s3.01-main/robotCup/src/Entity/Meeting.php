<?php

namespace App\Entity;

use App\Repository\MeetingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[UniqueEntity(fields: ['championShip', 'tournament', 'blueTeam', 'greenTeam'], message: 'This meeting already exist')]
#[ORM\UniqueConstraint(name: 'UNIQ_MEETING', columns: ['champion_ship_id', 'tournament_id', 'blue_team_id', 'green_team_id'])]

#[ORM\Entity(repositoryClass: MeetingRepository::class)]
class Meeting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Team $blueTeam = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Team $greenTeam = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: "The score must be positive or zero.")]
    private ?int $blueScore = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: "The score must be positive or zero.")]
    private ?int $greenScore = null;

    #[ORM\Column(length: 255, nullable: false)]
    #[Assert\Choice(choices: ['TO_PLAY', 'PLAYED', 'GAVE_UP_BLUE','GAVE_UP_GREEN','CANCELLED'], message: 'State must be one of "TO_PLAY", "PLAYED", or "GAVE_UP".')]
    private ?string $state = null;

    #[ORM\ManyToOne(inversedBy: 'meetings')]
    #[ORM\JoinColumn(nullable: true)]
    private ?TimeSlot $timeSlot = null;

    #[ORM\ManyToOne(inversedBy: 'meetings')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tournament $tournament = null;

    #[ORM\ManyToOne(inversedBy: 'meetings')]
    #[ORM\JoinColumn(nullable: true)]
    private ?ChampionShip $championShip = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Stage $stage = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comments = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBlueTeam(): ?Team
    {
        return $this->blueTeam;
    }

    public function setBlueTeam(?Team $blueTeam): static
    {
        $this->blueTeam = $blueTeam;

        return $this;
    }

    public function getGreenTeam(): ?Team
    {
        return $this->greenTeam;
    }

    public function setGreenTeam(?Team $greenTeam): static
    {
        $this->greenTeam = $greenTeam;

        return $this;
    }

    public function getBlueScore(): ?int
    {
        return $this->blueScore;
    }

    public function setBlueScore(?int $blueScore): static
    {
        $this->blueScore = $blueScore;

        return $this;
    }

    public function getGreenScore(): ?int
    {
        return $this->greenScore;
    }

    public function setGreenScore(?int $greenScore): static
    {
        $this->greenScore = $greenScore;

        return $this;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(string $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function getTimeSlot(): ?TimeSlot
    {
        return $this->timeSlot;
    }

    public function setTimeSlot(?TimeSlot $timeSlot): static
    {
        $this->timeSlot = $timeSlot;

        return $this;
    }

    public function getTournament(): ?Tournament
    {
        return $this->tournament;
    }

    public function setTournament(?Tournament $tournament): static
    {
        $this->tournament = $tournament;

        return $this;
    }

    public function getChampionShip(): ?ChampionShip
    {
        return $this->championShip;
    }

    public function setChampionShip(?ChampionShip $championShip): static
    {
        $this->championShip = $championShip;

        return $this;
    }

    public function getStage(): ?Stage
    {
        return $this->stage;
    }

    public function setStage(?Stage $stage): static
    {
        $this->stage = $stage;

        return $this;
    }

    #[Assert\Callback]
    public function validateTeamsAreDifferent(ExecutionContextInterface $context, $payload)
    {
        if ($this->blueTeam === $this->greenTeam) {
            $context->buildViolation('Blue team and green team must be different.')
                ->atPath('greenTeam')
                ->addViolation();
        }
    }
    #[Assert\Callback]
    public function validateTournamentOrChampionShip(ExecutionContextInterface $context, $payload)
    {
        // Ensure only one of `championShip` or `tournament` is set
        if (($this->championShip && $this->tournament) || (!$this->championShip && !$this->tournament)) {
            $context->buildViolation('A meeting must be associated with either a Championship or a Tournament, not both or neither.')
                ->atPath('championShip')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateNoOverlap(ExecutionContextInterface $context, $payload)
    {
        // Ensure that this meeting not overlap an existing meeting in same time in same stage
        // Get the EntityManager
        $entityManager = $context->getObject()->getEntityManager();
        $meetingRepository = $entityManager->getRepository(Meeting::class);

        //Search if we overlap on another meeting
        $existingMeetings = $meetingRepository->createQueryBuilder('m')
            ->innerJoin('m.timeSlot', 'ts')
            ->where('m.stage = :stage')
            ->andWhere('(
                (:start < ts.end AND :end > ts.start) OR 
                (:start <= ts.start AND :end >= ts.end) OR
                (:start >= ts.start AND :end <= ts.end)  
            )')
            ->setParameter('start', $this->timeSlot->getStart())
            ->setParameter('end', $this->timeSlot->getEnd())
            ->setParameter('stage', $this->stage)
            ->getQuery()
            ->getResult();

        // If overlapping meetings exist
        if (count($existingMeetings) > 0) {
            // Add a violation
            $context->buildViolation('The meeting dates overlap with an existing meeting.')
                ->atPath('stage')
                ->addViolation();
        }
    }

    public function getComments(): ?string
    {
        return $this->comments;
    }

    public function setComments(string $comments): static
    {
        $this->comments = $comments;

        return $this;
    }
}
