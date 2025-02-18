<?php

namespace App\Entity;

use App\Repository\CompetitionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Doctrine\ORM\EntityManagerInterface;

#[UniqueEntity(fields: ['name'], message: 'There is already a competition with this name')]

#[ORM\Entity(repositoryClass: CompetitionRepository::class)]
class Competition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: false, unique: true)]
    private ?string $name = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: false)]
    private ?\DateTimeInterface $start = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: false)]
    private ?\DateTimeInterface $end = null;

    #[ORM\OneToOne(cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?ChampionShip $championShip = null;

    #[ORM\OneToOne(cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tournament $tournament = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $organizer = null;

    /**
     * @var Collection<int, Team>
     */
    #[ORM\OneToMany(targetEntity: Team::class, mappedBy: 'competition')]
    private Collection $registeredTeams;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    public function __construct()
    {
        $this->registeredTeams = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getStart(): ?\DateTimeInterface
    {
        return $this->start;
    }

    public function setStart(\DateTimeInterface $start): static
    {
        $this->start = $start;

        return $this;
    }

    public function getEnd(): ?\DateTimeInterface
    {
        return $this->end;
    }

    public function setEnd(\DateTimeInterface $end): static
    {
        $this->end = $end;

        return $this;
    }

    public function getChampionShip(): ?ChampionShip
    {
        return $this->championShip;
    }

    public function setChampionShip(ChampionShip $championShip): static
    {
        $this->championShip = $championShip;

        return $this;
    }

    public function getTournament(): ?Tournament
    {
        return $this->tournament;
    }

    public function setTournament(Tournament $tournament): static
    {
        $this->tournament = $tournament;

        return $this;
    }

    public function getOrganizer(): ?User
    {
        return $this->organizer;
    }

    public function setOrganizer(?User $organizer): static
    {
        $this->organizer = $organizer;

        return $this;
    }

    /**
     * @return Collection<int, Team>
     */
    public function getRegisteredTeams(): Collection
    {
        return $this->registeredTeams;
    }

    public function addRegisteredTeam(Team $registeredTeam): static
    {
        if (!$this->registeredTeams->contains($registeredTeam)) {
            $this->registeredTeams->add($registeredTeam);
            $registeredTeam->setCompetition($this);
        }

        return $this;
    }

    public function removeRegisteredTeam(Team $registeredTeam): static
    {
        if ($this->registeredTeams->removeElement($registeredTeam)) {
            // set the owning side to null (unless already changed)
            if ($registeredTeam->getCompetition() === $this) {
                $registeredTeam->setCompetition(null);
            }
        }

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    #[Assert\Callback]
    public function validateDates(ExecutionContextInterface $context)
    {
        // Check that the start date is less than the end date
        if ($this->start >= $this->end) {
            $context->buildViolation('The start date must be before the end date.')
                ->atPath('end')
                ->addViolation();
        }

        // Validation if the end date of the championship is equal to the start date of the tournament
        if (($this->championShip->getEnd() !== null || $this->tournament->getStart() !== null) && $this->championShip->getEnd() !== $this->tournament->getStart()) {
            $context->buildViolation('The end date of the championship must be the same as the start date of the tournament.')
                ->atPath('end')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateOrganizerRole(ExecutionContextInterface $context, $payload)
    {
        if (!in_array('ROLE_ORGA', $this->organizer->getRoles())) {
            $context->buildViolation('The team owner must have the ROLE_ORGA role.')
                ->atPath('organizer')
                ->addViolation();
        }
    }
}
