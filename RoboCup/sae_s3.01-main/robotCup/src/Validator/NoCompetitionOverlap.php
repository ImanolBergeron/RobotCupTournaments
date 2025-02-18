<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class NoCompetitionOverlap extends Constraint
{
    public string $message = 'The competition dates overlap with an existing competition.';
}
