<?php

namespace App\Services\TelegramStore\Payments;

use RuntimeException;

/** Platega did not answer, refused the request, or answered something unexpected. */
class PlategaException extends RuntimeException {}
