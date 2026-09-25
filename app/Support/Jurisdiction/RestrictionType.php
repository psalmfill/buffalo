<?php

namespace App\Support\Jurisdiction;

enum RestrictionType
{
    /** No records. */
    case Nothing;

    /** All records. */
    case Everything;

    /** Records whose state is one of the given states. */
    case States;

    /** Records in one LGA. */
    case Lga;
}
