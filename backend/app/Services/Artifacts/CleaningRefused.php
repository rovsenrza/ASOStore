<?php

namespace App\Services\Artifacts;

use RuntimeException;

/** The IPA cleaner declined: the removal asked for would not be safe, or the archive is unusable. */
class CleaningRefused extends RuntimeException {}
