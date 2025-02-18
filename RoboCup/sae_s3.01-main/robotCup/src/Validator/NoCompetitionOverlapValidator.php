<?php

namespace App\Validator;

use App\Entity\Competition;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class NoCompetitionOverlapValidator extends ConstraintValidator
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    public function validate($value, Constraint $constraint)
    {
        if (!$value instanceof Competition) {
            return;
        }

        $competitionRepository = $this->entityManager->getRepository(Competition::class);

        $existingCompetitions = $competitionRepository->createQueryBuilder('c')
            ->where('c.id != :id')
            ->andWhere('
                (:start BETWEEN c.start AND c.end) OR 
                (:end BETWEEN c.start AND c.end) OR
                (:start <= c.start AND :end >= c.end)
            ')
            ->setParameter('start', $value->getStart())
            ->setParameter('end', $value->getEnd())
            ->setParameter('id', $value->getId())
            ->getQuery()
            ->getResult();

        if (count($existingCompetitions) > 0) {
            $this->context->buildViolation($constraint->message)
                ->atPath('start')
                ->addViolation();
        }
    }
}
