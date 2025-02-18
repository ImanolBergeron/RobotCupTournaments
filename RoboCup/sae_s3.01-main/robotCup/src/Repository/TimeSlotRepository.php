<?php

namespace App\Repository;

use App\Entity\TimeSlot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Stage;
use App\Entity\ChampionShip;
use App\Entity\Competition;

/**
 * @extends ServiceEntityRepository<TimeSlot>
 */
class TimeSlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimeSlot::class);
    }

    public function findFirstAvailableTimeSlot(): ?TimeSlot
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.meetings', 'm')
            ->where('m.id IS NULL')
            ->orderBy('t.start', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAllAvailableTimeSlots(): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.meetings', 'm')
            ->where('m.id IS NULL')
            ->orderBy('t.start', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findFirstAvailableTimeSlotForStage(?Stage $stage): ?TimeSlot
    {
        $qb = $this->createQueryBuilder('t');

        return $qb
            ->select('t')
            ->leftJoin('t.meetings', 'm', 'WITH', 'm.stage = :stage')
            ->where($qb->expr()->isNull('m.id'))
            ->setParameter('stage', $stage)
            ->orderBy('t.start', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAllAvailableTimeSlotsForStage(?Stage $stage): array
    {
        $qb = $this->createQueryBuilder('t');

        return $qb
            ->select('t')
            ->leftJoin('t.meetings', 'm')
            ->where($qb->expr()->orX(
                $qb->expr()->isNull('m.id'),
                $qb->expr()->neq('m.stage', ':stage')
            ))
            ->setParameter('stage', $stage)
            ->orderBy('t.start', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function deleteTimeSlotsByStage(Stage $stage): void
    {
        $this->createQueryBuilder('t')
            ->delete()
            ->where('EXISTS (
                SELECT 1 
                FROM App\Entity\Meeting m 
                WHERE m.timeSlot = t 
                AND m.stage = :stage
            )')
            ->setParameter('stage', $stage)
            ->getQuery()
            ->execute();
    }

    public function findAvailableTimeSlotsForCompetition(Competition $competition)
    {
        $qb = $this->createQueryBuilder('ts');
        return $qb
            ->leftJoin('ts.meetings', 'm')
            ->where('ts.start >= :competitionStart')
            ->andWhere('ts.start <= :competitionEnd')
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('m.id'),
                $qb->expr()->neq('m.championShip', ':championship')
            ))
            ->setParameter('competitionStart', $competition->getStart())
            ->setParameter('competitionEnd', $competition->getEnd())
            ->setParameter('championship', $competition->getChampionShip())
            ->orderBy('ts.start', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAvailableTimeSlotsForChampionship(ChampionShip $championship)
    {
        $qb = $this->createQueryBuilder('t');

        // Sélectionner tous les créneaux dont le début est dans la période du championnat
        // ET qui n'ont pas déjà un match pour ce championnat
        return $qb
            ->leftJoin('t.meetings', 'm', 'WITH', 'm.championShip = :championship')
            ->where('t.start >= :start')
            ->andWhere('t.start <= :end')
            ->andWhere($qb->expr()->isNull('m.id'))
            ->setParameter('championship', $championship)
            ->setParameter('start', $championship->getStart())
            ->setParameter('end', $championship->getEnd())
            ->orderBy('t.start', 'ASC')
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return TimeSlot[] Returns an array of TimeSlot objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('t')
    //            ->andWhere('t.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('t.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?TimeSlot
    //    {
    //        return $this->createQueryBuilder('t')
    //            ->andWhere('t.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
