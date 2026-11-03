<?php

namespace App\Services\Signing\Delta;

use RuntimeException;

/** A pack, or the base it names, cannot be composed into a valid IPA. */
final class DeltaException extends RuntimeException {}
