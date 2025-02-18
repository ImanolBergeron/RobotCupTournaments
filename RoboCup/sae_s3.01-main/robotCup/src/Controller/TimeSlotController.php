<?php

namespace App\Controller;

use App\Form\ScheduleType;
use App\Entity\TimeSlot;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TimeSlotController extends AbstractController
{
    private const SLOT_DURATION = 20;

    #[Route('/timeslot/create', name: 'app_timeslot_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ScheduleType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $data = $form->getData();
                $date = $data['date'];
                $startTime = $data['start'];
                $endTime = $data['end'];

                // Combine date and time
                $startDateTime = clone $date;
                $startDateTime->setTime(
                    (int)$startTime->format('H'),
                    (int)$startTime->format('i')
                );

                $endDateTime = clone $date;
                $endDateTime->setTime(
                    (int)$endTime->format('H'),
                    (int)$endTime->format('i')
                );

                // Create time slots
                $currentTime = clone $startDateTime;
                $interval = new \DateInterval('PT' . self::SLOT_DURATION . 'M');

                while ($currentTime < $endDateTime) {
                    $timeSlot = new TimeSlot();
                    $timeSlot->setStart(clone $currentTime);

                    $slotEnd = clone $currentTime;
                    $slotEnd->add($interval);

                    if ($slotEnd > $endDateTime) {
                        break;
                    }

                    $timeSlot->setEnd($slotEnd);
                    $timeSlot->setName(sprintf(
                        'Créneau %s-%s',
                        $currentTime->format('Y-m-d H:i'),
                        $slotEnd->format('H:i')
                    ));

                    $entityManager->persist($timeSlot);
                    $currentTime->add($interval);
                }

                $entityManager->flush();
                $this->addFlash('success', 'Les créneaux horaires ont été créés avec succès');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur lors de la création des créneaux: ' . $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_competition');
    }
}
