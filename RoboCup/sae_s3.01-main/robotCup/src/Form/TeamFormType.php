<?php

namespace App\Form;

use App\Entity\Team;
use App\Entity\Competition;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class TeamFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Team_Name',
                'required' => true,
            ])
            ->add('structure', TextType::class, [
                'label' => 'Structure_Name',
                'required' => true,
            ])
            ->add('competitions', EntityType::class, [
                'class' => Competition::class,
                'choice_label' => 'name',
                'required' => true,
                'mapped' => false,
                'label' => 'Competition',
                'placeholder' => 'Choisissez une compétition'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Team::class,
        ]);
    }
}
