<?php

namespace App\Support\CrossJurisdiction;

use RuntimeException;

/** An expired, tampered or foreign hit token. */
final class InvalidHitToken extends RuntimeException {}
